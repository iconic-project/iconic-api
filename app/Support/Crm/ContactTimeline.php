<?php

declare(strict_types=1);

namespace App\Support\Crm;

use App\Enums\ActivityKind;
use App\Enums\BehaviouralEventName;
use App\Enums\BookingStatus;
use App\Enums\ConsentCapturePoint;
use App\Enums\ConsentDocument;
use App\Enums\ConsentPurpose;
use App\Enums\ConsentSource;
use App\Enums\DeliveryKind;
use App\Enums\DeliveryStatus;
use App\Enums\PaymentKind;
use App\Enums\PaymentStatus;
use App\Models\Contact;
use App\Support\Bookings\LegacyStatus;
use App\Support\Iso;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Facades\DB;

final class ContactTimeline
{
    /**
     * @return LengthAwarePaginator<int, array{
     *     at: string,
     *     kind: string,
     *     title: string,
     *     detail: string,
     *     link: array{type: string, id: int, reference: string|null}|null
     * }>
     */
    public static function page(Contact $contact, int $perPage): LengthAwarePaginator
    {
        $union = self::bookings($contact->id)
            ->unionAll(self::payments($contact->id))
            ->unionAll(self::deliveries($contact->id))
            ->unionAll(self::consents($contact->id))
            ->unionAll(self::registerConsents($contact->id))
            ->unionAll(self::behavioural($contact->id))
            ->unionAll(self::merges($contact->id))
            ->unionAll(self::deals($contact->id))
            ->unionAll(self::tasks($contact->id))
            ->unionAll(self::activities($contact->id))
            ->unionAll(self::subjectRequests($contact->id))
            ->unionAll(self::journeys($contact->id))
            ->unionAll(self::conversations($contact->id));

        /** @var Paginator<int, object> $rows */
        $rows = DB::query()
            ->fromSub($union, 'timeline')
            ->orderByDesc('at')
            ->orderByDesc('sort_key')
            ->paginate($perPage);

        $rows->setCollection($rows->getCollection()->map(
            fn (object $row): array => self::format($row),
        ));

        /** @var LengthAwarePaginator<int, array{at: string, kind: string, title: string, detail: string, link: array{type: string, id: int, reference: string|null}|null}> $rows */
        return $rows;
    }

    private static function bookings(int $contactId): Builder
    {
        $milestones = [
            BookingStatus::Confirmed->value,
            BookingStatus::FullyPaid->value,
            BookingStatus::Cancelled->value,
        ];

        return DB::table('change_history')
            ->join('bookings', function ($join): void {
                $join->on('bookings.id', '=', 'change_history.subject_id')
                    ->where('change_history.subject_type', '=', 'booking');
            })
            ->where('bookings.contact_id', $contactId)
            ->where(function ($query) use ($milestones): void {
                $query->whereIn('change_history.event', ['booking.created', 'booking.requested', 'booking.moved'])
                    ->orWhere(function ($inner) use ($milestones): void {
                        $inner->where('change_history.event', 'booking.status_changed')
                            ->whereRaw(
                                "JSON_UNQUOTE(JSON_EXTRACT(change_history.after, '$.status')) in (?, ?, ?)",
                                $milestones,
                            );
                    });
            })
            ->select([
                DB::raw('change_history.created_at as `at`'),
                DB::raw("'booking' as kind"),
                DB::raw("JSON_OBJECT(
                    'event', change_history.event,
                    'status', JSON_UNQUOTE(JSON_EXTRACT(change_history.after, '$.status')),
                    'what', JSON_UNQUOTE(JSON_EXTRACT(change_history.after, '$.what'))
                ) as payload"),
                DB::raw("'booking' as link_type"),
                DB::raw('bookings.id as link_id'),
                DB::raw('COALESCE(bookings.reference, bookings.request_reference) as link_reference'),
                DB::raw("CONCAT('booking-', change_history.id) as sort_key"),
            ]);
    }

    private static function payments(int $contactId): Builder
    {
        return DB::table('payments')
            ->join('bookings', 'bookings.id', '=', 'payments.booking_id')
            ->where('bookings.contact_id', $contactId)
            ->select([
                DB::raw('payments.created_at as `at`'),
                DB::raw("'payment' as kind"),
                DB::raw("JSON_OBJECT('kind', payments.kind, 'amount', payments.amount, 'status', payments.status) as payload"),
                DB::raw("'booking' as link_type"),
                DB::raw('bookings.id as link_id'),
                DB::raw('COALESCE(bookings.reference, bookings.request_reference) as link_reference'),
                DB::raw("CONCAT('payment-', payments.id) as sort_key"),
            ]);
    }

    private static function deliveries(int $contactId): Builder
    {
        return DB::table('deliveries')
            ->join('bookings', 'bookings.id', '=', 'deliveries.booking_id')
            ->where('bookings.contact_id', $contactId)
            ->select([
                DB::raw('deliveries.created_at as `at`'),
                DB::raw("'delivery' as kind"),
                DB::raw("JSON_OBJECT(
                    'kind', deliveries.kind,
                    'status', deliveries.status,
                    'to', CAST(deliveries.`to` AS JSON)
                ) as payload"),
                DB::raw("'booking' as link_type"),
                DB::raw('bookings.id as link_id'),
                DB::raw('COALESCE(bookings.reference, bookings.request_reference) as link_reference'),
                DB::raw("CONCAT('delivery-', deliveries.id) as sort_key"),
            ]);
    }

    private static function consents(int $contactId): Builder
    {
        return DB::table('consents')
            ->join('bookings', 'bookings.id', '=', 'consents.booking_id')
            ->where('bookings.contact_id', $contactId)
            ->where('consents.document', '!=', ConsentDocument::Marketing->value)
            ->select([
                DB::raw('consents.accepted_at as `at`'),
                DB::raw("'consent' as kind"),
                DB::raw("JSON_OBJECT(
                    'document', consents.document,
                    'version', consents.version,
                    'source', consents.source,
                    'withdrawn', consents.withdrawn
                ) as payload"),
                DB::raw("'booking' as link_type"),
                DB::raw('bookings.id as link_id'),
                DB::raw('COALESCE(bookings.reference, bookings.request_reference) as link_reference'),
                DB::raw("CONCAT('consent-', consents.id) as sort_key"),
            ]);
    }

    private static function registerConsents(int $contactId): Builder
    {
        return DB::table('contact_consents')
            ->where('contact_id', $contactId)
            ->select([
                DB::raw('contact_consents.captured_at as `at`'),
                DB::raw("'register' as kind"),
                DB::raw("JSON_OBJECT(
                    'purpose', contact_consents.purpose,
                    'granted', contact_consents.granted = 1,
                    'capture_point', contact_consents.capture_point
                ) as payload"),
                DB::raw('CAST(NULL AS CHAR) as link_type'),
                DB::raw('CAST(NULL AS UNSIGNED) as link_id'),
                DB::raw('CAST(NULL AS CHAR) as link_reference'),
                DB::raw("CONCAT('register-', contact_consents.id) as sort_key"),
            ]);
    }

    private static function behavioural(int $contactId): Builder
    {
        return DB::table('behavioural_events')
            ->leftJoin('itineraries', 'itineraries.code', '=', DB::raw("JSON_UNQUOTE(JSON_EXTRACT(behavioural_events.params, '$.itinerary_code'))"))
            ->leftJoin('departures', 'departures.id', '=', DB::raw("CAST(JSON_UNQUOTE(JSON_EXTRACT(behavioural_events.params, '$.departure_id')) AS UNSIGNED)"))
            ->leftJoin('properties', 'properties.id', '=', 'departures.property_id')
            ->where('behavioural_events.contact_id', $contactId)
            ->select([
                DB::raw('behavioural_events.occurred_at as `at`'),
                DB::raw("'behavioural' as kind"),
                DB::raw("JSON_SET(
                    JSON_OBJECT(
                        'name', behavioural_events.name,
                        'itinerary_name', itineraries.name,
                        'departure_date', DATE_FORMAT(departures.date, '%Y-%m-%d'),
                        'property_name', properties.name
                    ),
                    '$.params', CAST(behavioural_events.params AS JSON)
                ) as payload"),
                DB::raw('CAST(NULL AS CHAR) as link_type'),
                DB::raw('CAST(NULL AS UNSIGNED) as link_id'),
                DB::raw('CAST(NULL AS CHAR) as link_reference'),
                DB::raw("CONCAT('behavioural-', behavioural_events.id) as sort_key"),
            ]);
    }

    private static function tasks(int $contactId): Builder
    {
        return DB::table('change_history')
            ->join('crm_tasks', function ($join): void {
                $join->on('crm_tasks.id', '=', 'change_history.subject_id')
                    ->where('change_history.subject_type', '=', 'crm_task');
            })
            ->where('crm_tasks.contact_id', $contactId)
            ->where(function ($query): void {
                $query->whereIn('change_history.event', ['task.completed', 'task.auto_closed'])
                    ->orWhere(function ($raised): void {
                        $raised->where('change_history.event', 'task.raised')
                            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(change_history.after, '$.source')) = 'SYSTEM'");
                    });
            })
            ->select([
                DB::raw('change_history.created_at as `at`'),
                DB::raw("'task' as kind"),
                DB::raw("JSON_OBJECT(
                    'event', change_history.event,
                    'title', JSON_UNQUOTE(JSON_EXTRACT(change_history.after, '$.title')),
                    'fact', JSON_UNQUOTE(JSON_EXTRACT(change_history.after, '$.fact')),
                    'outcome', JSON_UNQUOTE(JSON_EXTRACT(change_history.after, '$.outcome'))
                ) as payload"),
                DB::raw("'task' as link_type"),
                DB::raw('crm_tasks.id as link_id'),
                DB::raw('CAST(NULL AS CHAR) as link_reference'),
                DB::raw("CONCAT('task-', change_history.id) as sort_key"),
            ]);
    }

    private static function activities(int $contactId): Builder
    {
        return DB::table('contact_activities')
            ->where('contact_id', $contactId)
            ->select([
                DB::raw('contact_activities.occurred_at as `at`'),
                DB::raw("'activity' as kind"),
                DB::raw("JSON_OBJECT(
                    'kind', contact_activities.kind,
                    'body', contact_activities.body
                ) as payload"),
                DB::raw('CAST(NULL AS CHAR) as link_type'),
                DB::raw('CAST(NULL AS UNSIGNED) as link_id'),
                DB::raw('CAST(NULL AS CHAR) as link_reference'),
                DB::raw("CONCAT('activity-', contact_activities.id) as sort_key"),
            ]);
    }

    private static function subjectRequests(int $contactId): Builder
    {
        return DB::table('change_history')
            ->join('subject_requests', function ($join): void {
                $join->on('subject_requests.id', '=', 'change_history.subject_id')
                    ->where('change_history.subject_type', '=', 'subject_request');
            })
            ->where('subject_requests.contact_id', $contactId)
            ->whereIn('change_history.event', ['subject_request.received', 'subject_request.closed'])
            ->select([
                DB::raw('change_history.created_at as `at`'),
                DB::raw("'subject_request' as kind"),
                DB::raw("JSON_OBJECT(
                    'event', change_history.event,
                    'type', JSON_UNQUOTE(JSON_EXTRACT(change_history.after, '$.type')),
                    'outcome', JSON_UNQUOTE(JSON_EXTRACT(change_history.after, '$.outcome'))
                ) as payload"),
                DB::raw("'subject_request' as link_type"),
                DB::raw('subject_requests.id as link_id'),
                DB::raw('CAST(NULL AS CHAR) as link_reference'),
                DB::raw("CONCAT('subject-', change_history.id) as sort_key"),
            ]);
    }

    private static function deals(int $contactId): Builder
    {
        return DB::table('change_history')
            ->join('deals', function ($join): void {
                $join->on('deals.id', '=', 'change_history.subject_id')
                    ->where('change_history.subject_type', '=', 'deal');
            })
            ->where('deals.contact_id', $contactId)
            ->whereIn('change_history.event', ['deal.created', 'deal.bound', 'deal.stage_changed'])
            ->select([
                DB::raw('change_history.created_at as `at`'),
                DB::raw("'deal' as kind"),
                DB::raw("JSON_OBJECT(
                    'event', change_history.event,
                    'from', JSON_UNQUOTE(JSON_EXTRACT(change_history.after, '$.from')),
                    'to', JSON_UNQUOTE(JSON_EXTRACT(change_history.after, '$.to')),
                    'title', JSON_UNQUOTE(JSON_EXTRACT(change_history.after, '$.title')),
                    'reason', change_history.reason,
                    'reference', JSON_UNQUOTE(JSON_EXTRACT(change_history.after, '$.reference'))
                ) as payload"),
                DB::raw("'deal' as link_type"),
                DB::raw('deals.id as link_id'),
                DB::raw('CAST(NULL AS CHAR) as link_reference'),
                DB::raw("CONCAT('deal-', change_history.id) as sort_key"),
            ]);
    }

    private static function merges(int $contactId): Builder
    {
        return DB::table('contact_merges')
            ->leftJoin('users', 'users.id', '=', 'contact_merges.merged_by')
            ->where(function ($query) use ($contactId): void {
                $query->where('contact_merges.survivor_id', $contactId)
                    ->orWhere('contact_merges.loser_id', $contactId);
            })
            ->select([
                DB::raw('contact_merges.merged_at as `at`'),
                DB::raw("'merge' as kind"),
                DB::raw("JSON_OBJECT(
                    'reason', contact_merges.reason,
                    'actor', COALESCE(users.name, 'System'),
                    'undone', contact_merges.undone_at IS NOT NULL
                ) as payload"),
                DB::raw("'contact' as link_type"),
                DB::raw('contact_merges.survivor_id as link_id'),
                DB::raw('CAST(NULL AS CHAR) as link_reference'),
                DB::raw("CONCAT('merge-', contact_merges.id) as sort_key"),
            ]);
    }

    private static function journeys(int $contactId): Builder
    {
        $enrolments = DB::table('journey_enrolments')
            ->join('journeys', 'journeys.id', '=', 'journey_enrolments.journey_id')
            ->where('journey_enrolments.contact_id', $contactId)
            ->select([
                DB::raw('journey_enrolments.enrolled_at as `at`'),
                DB::raw("'journey' as kind"),
                DB::raw("JSON_OBJECT('event', 'enrolled', 'name', journeys.name, 'status', journey_enrolments.status) as payload"),
                DB::raw('CAST(NULL AS CHAR) as link_type'),
                DB::raw('CAST(NULL AS UNSIGNED) as link_id'),
                DB::raw('CAST(NULL AS CHAR) as link_reference'),
                DB::raw("CONCAT('journey-enrol-', journey_enrolments.id) as sort_key"),
            ]);

        $sends = DB::table('journey_sends')
            ->join('journey_enrolments', 'journey_enrolments.id', '=', 'journey_sends.journey_enrolment_id')
            ->where('journey_enrolments.contact_id', $contactId)
            ->select([
                DB::raw('journey_sends.sent_at as `at`'),
                DB::raw("'journey' as kind"),
                DB::raw("JSON_OBJECT('event', 'sent', 'template_key', journey_sends.template_key, 'catalogue_key', journey_sends.catalogue_key, 'delivery_id', journey_sends.delivery_id) as payload"),
                DB::raw('CAST(NULL AS CHAR) as link_type'),
                DB::raw('CAST(NULL AS UNSIGNED) as link_id'),
                DB::raw('CAST(NULL AS CHAR) as link_reference'),
                DB::raw("CONCAT('journey-send-', journey_sends.id) as sort_key"),
            ]);

        return $enrolments->unionAll($sends);
    }

    private static function conversations(int $contactId): Builder
    {
        return DB::table('messages')
            ->join('conversations', 'conversations.id', '=', 'messages.conversation_id')
            ->where('conversations.contact_id', $contactId)
            ->select([
                DB::raw('messages.sent_at as `at`'),
                DB::raw("'conversation.message' as kind"),
                DB::raw("JSON_OBJECT(
                    'direction', messages.direction,
                    'subject', messages.subject
                ) as payload"),
                DB::raw("'conversation' as link_type"),
                DB::raw('conversations.id as link_id'),
                DB::raw('CAST(NULL AS CHAR) as link_reference'),
                DB::raw("CONCAT('conversation-message-', messages.id) as sort_key"),
            ]);
    }

    /**
     * @return array{
     *     at: string,
     *     kind: string,
     *     title: string,
     *     detail: string,
     *     link: array{type: string, id: int, reference: string|null}|null
     * }
     */
    private static function format(object $row): array
    {
        $payload = self::payload($row);
        $kind = (string) $row->kind;
        [$title, $detail] = match ($kind) {
            'booking' => self::bookingCopy($payload),
            'payment' => self::paymentCopy($payload),
            'delivery' => self::deliveryCopy($payload),
            'consent' => self::consentCopy($payload),
            'register' => self::registerCopy($payload),
            'deal' => self::dealCopy($payload),
            'task' => self::taskCopy($payload),
            'activity' => self::activityCopy($payload),
            'subject_request' => self::subjectRequestCopy($payload),
            'behavioural' => self::behaviouralCopy($payload),
            'merge' => self::mergeCopy($payload),
            'journey' => self::journeyCopy($payload),
            'conversation.message' => self::conversationCopy($payload),
            default => ['Event', ''],
        };

        $linkType = is_string($row->link_type ?? null) && $row->link_type !== '' ? $row->link_type : null;
        $linkId = isset($row->link_id) && is_numeric($row->link_id) ? (int) $row->link_id : null;

        return [
            'at' => Iso::utc(CarbonImmutable::parse((string) $row->at)),
            'kind' => $kind,
            'title' => $title,
            'detail' => $detail,
            'link' => $linkType !== null && $linkId !== null
                ? [
                    'type' => $linkType,
                    'id' => $linkId,
                    'reference' => is_string($row->link_reference ?? null) && $row->link_reference !== ''
                        ? $row->link_reference
                        : null,
                ]
                : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function payload(object $row): array
    {
        $raw = $row->payload ?? null;

        if (is_array($raw)) {
            return $raw;
        }

        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{0: string, 1: string}
     */
    private static function journeyCopy(array $payload): array
    {
        $event = is_string($payload['event'] ?? null) ? $payload['event'] : '';

        if ($event === 'sent') {
            $template = is_string($payload['template_key'] ?? null) ? $payload['template_key'] : 'message';

            return ['Journey message', $template];
        }

        $name = is_string($payload['name'] ?? null) ? $payload['name'] : 'Journey';
        $status = is_string($payload['status'] ?? null) ? $payload['status'] : '';

        return ['Enrolled in '.$name, $status];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{0: string, 1: string}
     */
    private static function conversationCopy(array $payload): array
    {
        $direction = is_string($payload['direction'] ?? null) ? $payload['direction'] : '';
        $subject = is_string($payload['subject'] ?? null) ? $payload['subject'] : '';
        $title = $direction === 'OUT' ? 'Reply sent' : 'Email received';

        return [$title, $subject];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{0: string, 1: string}
     */
    private static function bookingCopy(array $payload): array
    {
        $event = is_string($payload['event'] ?? null) ? $payload['event'] : '';
        $status = LegacyStatus::value(is_string($payload['status'] ?? null) ? $payload['status'] : '');
        $what = LegacyStatus::text(is_string($payload['what'] ?? null) ? $payload['what'] : '');

        $title = match ($event) {
            'booking.created' => 'Created',
            'booking.requested' => 'Requested',
            'booking.moved' => 'Moved',
            'booking.status_changed' => match ($status) {
                BookingStatus::Confirmed->value => 'Confirmed',
                BookingStatus::FullyPaid->value => 'Fully paid',
                BookingStatus::Cancelled->value => 'Cancelled',
                default => 'Status changed',
            },
            default => 'Booking',
        };

        $detail = $what !== ''
            ? $what
            : match ($event) {
                'booking.requested' => 'Booking requested',
                'booking.moved' => 'Booking moved to a new departure',
                default => '',
            };

        return [$title, $detail];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{0: string, 1: string}
     */
    private static function paymentCopy(array $payload): array
    {
        $kind = PaymentKind::tryFrom(is_string($payload['kind'] ?? null) ? $payload['kind'] : '');
        $status = PaymentStatus::tryFrom(is_string($payload['status'] ?? null) ? $payload['status'] : '');
        $amount = is_numeric($payload['amount'] ?? null) ? (int) $payload['amount'] : 0;

        $kindLabel = $kind?->label() ?? (string) ($payload['kind'] ?? 'Payment');
        $statusLabel = $status?->label() ?? (string) ($payload['status'] ?? '');

        return ['Payment', $kindLabel.' · '.Money::format($amount).' · '.$statusLabel];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{0: string, 1: string}
     */
    private static function deliveryCopy(array $payload): array
    {
        $kind = DeliveryKind::tryFrom(is_string($payload['kind'] ?? null) ? $payload['kind'] : '');
        $status = DeliveryStatus::tryFrom(is_string($payload['status'] ?? null) ? $payload['status'] : '');
        $to = $payload['to'] ?? [];
        $recipients = is_array($to)
            ? implode(', ', array_values(array_filter($to, fn (mixed $value): bool => is_string($value) && $value !== '')))
            : '';

        $parts = [
            $kind?->label() ?? (string) ($payload['kind'] ?? 'Delivery'),
            $status?->label() ?? (string) ($payload['status'] ?? ''),
        ];

        if ($recipients !== '') {
            $parts[] = $recipients;
        }

        return ['Delivery', implode(' · ', $parts)];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{0: string, 1: string}
     */
    private static function consentCopy(array $payload): array
    {
        $document = ConsentDocument::tryFrom(is_string($payload['document'] ?? null) ? $payload['document'] : '');
        $source = ConsentSource::tryFrom(is_string($payload['source'] ?? null) ? $payload['source'] : '');
        $withdrawn = (bool) ($payload['withdrawn'] ?? false);
        $version = is_string($payload['version'] ?? null) ? $payload['version'] : '';

        $parts = [
            $document instanceof ConsentDocument ? $document->label() : (string) ($payload['document'] ?? 'Consent'),
            $version,
            $source instanceof ConsentSource ? $source->value : (string) ($payload['source'] ?? ''),
            $withdrawn ? 'withdrawn' : 'accepted',
        ];

        return ['Consent', implode(' · ', array_values(array_filter($parts, fn (string $part): bool => $part !== '')))];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{0: string, 1: string}
     */
    /**
     * @param  array<string, mixed>  $payload
     * @return array{0: string, 1: string}
     */
    private static function dealCopy(array $payload): array
    {
        $event = is_string($payload['event'] ?? null) ? $payload['event'] : '';
        $to = is_string($payload['to'] ?? null) ? $payload['to'] : '';
        $from = is_string($payload['from'] ?? null) ? $payload['from'] : '';
        $reason = is_string($payload['reason'] ?? null) ? $payload['reason'] : '';
        $reference = is_string($payload['reference'] ?? null) ? $payload['reference'] : '';

        if ($event === 'deal.created') {
            $title = is_string($payload['title'] ?? null) ? $payload['title'] : '';

            return ['Deal opened', $title];
        }

        if ($event === 'deal.bound') {
            return ['Deal bound', $reference];
        }

        if ($event === 'deal.stage_changed' && $to === 'LOST') {
            return ['Marked lost', $reason];
        }

        $detail = $from !== '' ? $from.' → '.$to : $to;

        return ['Stage changed', $detail];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{0: string, 1: string}
     */
    private static function taskCopy(array $payload): array
    {
        $event = is_string($payload['event'] ?? null) ? $payload['event'] : '';
        $title = is_string($payload['title'] ?? null) ? $payload['title'] : '';
        $fact = is_string($payload['fact'] ?? null) ? $payload['fact'] : '';
        $outcome = is_string($payload['outcome'] ?? null) ? $payload['outcome'] : '';

        return match ($event) {
            'task.completed' => ['Task completed', $outcome],
            'task.auto_closed' => ['Task auto-closed', $fact !== '' ? $fact : $outcome],
            default => ['Task raised', $title],
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{0: string, 1: string}
     */
    private static function subjectRequestCopy(array $payload): array
    {
        $event = is_string($payload['event'] ?? null) ? $payload['event'] : '';
        $type = is_string($payload['type'] ?? null) ? $payload['type'] : '';
        $outcome = is_string($payload['outcome'] ?? null) ? $payload['outcome'] : '';

        if ($event === 'subject_request.closed') {
            return ['Subject request closed', trim($type.' '.$outcome)];
        }

        return ['Subject request received', $type];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{0: string, 1: string}
     */
    private static function activityCopy(array $payload): array
    {
        $kind = is_string($payload['kind'] ?? null) ? $payload['kind'] : '';
        $body = is_string($payload['body'] ?? null) ? $payload['body'] : '';
        $label = ActivityKind::tryFrom($kind)?->label() ?? 'Activity';

        return [$label, $body];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{0: string, 1: string}
     */
    private static function registerCopy(array $payload): array
    {
        $purpose = ConsentPurpose::tryFrom(is_string($payload['purpose'] ?? null) ? $payload['purpose'] : '');
        $point = ConsentCapturePoint::tryFrom(is_string($payload['capture_point'] ?? null) ? $payload['capture_point'] : '');
        $granted = (bool) ($payload['granted'] ?? false);

        $pointLabel = $point instanceof ConsentCapturePoint
            ? $point->label()
            : (string) ($payload['capture_point'] ?? '');
        $detail = $granted ? 'granted' : 'withdrawn';

        if ($pointLabel !== '') {
            $detail .= ' · '.$pointLabel;
        }

        return [
            $purpose instanceof ConsentPurpose ? $purpose->label() : 'Consent',
            $detail,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{0: string, 1: string}
     */
    private static function behaviouralCopy(array $payload): array
    {
        $name = BehaviouralEventName::tryFrom(is_string($payload['name'] ?? null) ? $payload['name'] : '');
        $params = is_array($payload['params'] ?? null) ? $payload['params'] : [];

        return [
            $name instanceof BehaviouralEventName ? $name->value : (string) ($payload['name'] ?? 'event'),
            BehaviouralEventDetail::make(
                $name instanceof BehaviouralEventName ? $name : BehaviouralEventName::PageView,
                $params,
                is_string($payload['itinerary_name'] ?? null) ? $payload['itinerary_name'] : null,
                is_string($payload['departure_date'] ?? null) ? $payload['departure_date'] : null,
                is_string($payload['property_name'] ?? null) ? $payload['property_name'] : null,
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{0: string, 1: string}
     */
    private static function mergeCopy(array $payload): array
    {
        $actor = is_string($payload['actor'] ?? null) ? $payload['actor'] : 'System';
        $reason = is_string($payload['reason'] ?? null) ? $payload['reason'] : '';
        $undone = (bool) ($payload['undone'] ?? false);

        $parts = array_values(array_filter([$actor, $reason, $undone ? 'undone' : '']));

        return ['Merged', implode(' · ', $parts)];
    }
}
