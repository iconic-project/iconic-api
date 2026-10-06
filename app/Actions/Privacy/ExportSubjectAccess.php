<?php

declare(strict_types=1);

namespace App\Actions\Privacy;

use App\Actions\Action;
use App\Enums\SubjectRequestStatus;
use App\Enums\SubjectRequestType;
use App\Models\BehaviouralEvent;
use App\Models\Booking;
use App\Models\Consent;
use App\Models\Contact;
use App\Models\ContactActivity;
use App\Models\ContactConsent;
use App\Models\ContactMerge;
use App\Models\CrmTask;
use App\Models\Delivery;
use App\Models\Document;
use App\Models\Group;
use App\Models\GuestResponse;
use App\Models\Payment;
use App\Models\SubjectRequest;
use App\Support\GuestExperience\ContactGuest;
use App\Support\Iso;
use App\Support\SensitiveFields;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use ZipArchive;

final class ExportSubjectAccess extends Action
{
    public const PASSENGER_NOTE = 'Passenger data held for travel is provided separately by the controller pending LEG-002.';

    public function handle(SubjectRequest $request): SubjectRequest
    {
        if ($request->type !== SubjectRequestType::Access) {
            throw new HttpException(422, 'Only an access request has an export.');
        }

        if ($request->status === SubjectRequestStatus::Rejected) {
            throw new HttpException(422, 'This request is already closed.');
        }

        $payload = $this->document($request->contact);
        $hits = SensitiveFields::keysIn($payload);

        if ($hits !== []) {
            throw new RuntimeException('Access export contained sensitive fields: '.implode(', ', $hits));
        }

        $relative = 'privacy/'.$request->id.'/access.zip';
        $this->writeZip($relative, $payload);

        $request->forceFill(['export_path' => $relative])->save();

        return $request->refresh();
    }

    /**
     * @return array<string, mixed>
     */
    public function document(Contact $contact): array
    {
        $bookingIds = $this->bookingIds($contact);

        return [
            'contact' => [
                'id' => $contact->id,
                'name' => $contact->name,
                'email' => $contact->email,
                'phone' => $contact->phone,
                'phone_e164' => $contact->phone_e164,
                'country' => $contact->country,
                'language' => $contact->language,
                'type' => $contact->type->value,
                'first_touch' => $contact->first_touch,
                'last_touch' => $contact->last_touch,
            ],
            'consent_register' => ContactConsent::query()
                ->where('contact_id', $contact->id)
                ->orderBy('id')
                ->get()
                ->map(fn (ContactConsent $row): array => [
                    'purpose' => $row->purpose->value,
                    'granted' => $row->granted,
                    'version' => $row->version,
                    'captured_at' => Iso::utc($row->captured_at),
                    'capture_point' => $row->capture_point->value,
                ])->all(),
            'booking_consents' => Consent::query()
                ->whereIn('booking_id', $bookingIds)
                ->orderBy('id')
                ->get()
                ->map(fn (Consent $row): array => [
                    'booking_id' => $row->booking_id,
                    'document' => $row->document->value,
                    'version' => $row->version,
                    'accepted_at' => Iso::utc($row->accepted_at),
                    'withdrawn' => $row->withdrawn,
                ])->all(),
            'bookings' => Booking::query()->withTrashed()->with(['payments'])->whereIn('id', $bookingIds)->orderBy('id')->get()
                ->map(function (Booking $booking): array {
                    return [
                        'reference' => $booking->reference ?? $booking->request_reference,
                        'check_in' => $booking->stay()->checkIn()->toDateString(),
                        'check_out' => $booking->stay()->checkOut()->toDateString(),
                        'status' => $booking->status->value,
                        'charges_total' => $booking->chargesTotal(),
                        'payments' => $booking->payments->map(fn (Payment $payment): array => [
                            'kind' => $payment->kind->value,
                            'amount' => $payment->amount,
                            'paid_at' => $payment->paid_at->toDateString(),
                            'status' => $payment->status->value,
                        ])->all(),
                    ];
                })->all(),
            'documents' => Document::query()->whereIn('booking_id', $bookingIds)->orderBy('id')->get()
                ->map(fn (Document $document): array => [
                    'kind' => $document->kind->value,
                    'version' => $document->version,
                    'number' => $document->number,
                    'issued_at' => Iso::utc($document->issued_at),
                ])->all(),
            'deliveries' => Delivery::query()->whereIn('booking_id', $bookingIds)->orderBy('id')->get()
                ->map(fn (Delivery $delivery): array => [
                    'kind' => $delivery->kind->value,
                    'status' => $delivery->status->value,
                    'at' => Iso::utc($delivery->created_at),
                ])->all(),
            'activities' => ContactActivity::query()->where('contact_id', $contact->id)->orderBy('id')->get()
                ->map(fn (ContactActivity $activity): array => [
                    'kind' => $activity->kind->value,
                    'body' => $activity->body,
                    'occurred_at' => Iso::utc($activity->occurred_at),
                ])->all(),
            'tasks' => CrmTask::query()->where('contact_id', $contact->id)->orderBy('id')->get()
                ->map(fn (CrmTask $task): array => [
                    'title' => $task->title,
                    'outcome' => $task->outcome,
                ])->all(),
            'behavioural_events' => BehaviouralEvent::query()->where('contact_id', $contact->id)->orderBy('id')->get()
                ->map(fn (BehaviouralEvent $event): array => [
                    'name' => $event->name->value,
                    'occurred_at' => Iso::utc($event->occurred_at),
                    'session_id' => $event->session_id,
                ])->all(),
            'merges' => ContactMerge::query()
                ->where('survivor_id', $contact->id)
                ->orWhere('loser_id', $contact->id)
                ->orderBy('id')
                ->get()
                ->map(fn (ContactMerge $merge): array => [
                    'id' => $merge->id,
                    'survivor_id' => $merge->survivor_id,
                    'loser_id' => $merge->loser_id,
                    'merged_at' => Iso::utc($merge->merged_at),
                    'undone_at' => $merge->undone_at !== null ? Iso::utc($merge->undone_at) : null,
                ])->all(),
            'passenger_data' => self::PASSENGER_NOTE,
            'survey_responses' => $this->surveyResponses($contact, $bookingIds),
        ];
    }

    /**
     * The contact's own answers only. A companion on the same booking is left out.
     *
     * @param  list<int>  $bookingIds
     * @return list<array{booking_reference: string, score: int, recommend: int|null, why: string|null, best: string|null, better: string|null, crew: string|null, responded_at: string|null}>
     */
    private function surveyResponses(Contact $contact, array $bookingIds): array
    {
        if ($bookingIds === []) {
            return [];
        }

        return GuestResponse::query()
            ->with(['guest', 'booking'])
            ->whereIn('booking_id', $bookingIds)
            ->orderBy('id')
            ->get()
            ->filter(fn (GuestResponse $response): bool => ContactGuest::guestIs($response->guest, $contact))
            ->map(fn (GuestResponse $response): array => [
                'booking_reference' => (string) ($response->booking->displayReference() ?? ''),
                'score' => $response->score,
                'recommend' => $response->recommend,
                'why' => $response->why,
                'best' => $response->best,
                'better' => $response->better,
                'crew' => $response->crew,
                'responded_at' => Iso::utc($response->responded_at),
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<int>
     */
    private function bookingIds(Contact $contact): array
    {
        $groupIds = Group::query()->where('coordinator_contact_id', $contact->id)->pluck('id');

        return Booking::query()
            ->withTrashed()
            ->where(function ($query) use ($contact, $groupIds): void {
                $query->where('contact_id', $contact->id)->orWhereIn('group_id', $groupIds);
            })
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function writeZip(string $relative, array $payload): void
    {
        $disk = Storage::disk('local');
        $disk->makeDirectory(dirname($relative));
        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
        $zipPath = $disk->path($relative);
        $zip = new ZipArchive;
        $opened = $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        if ($opened !== true) {
            throw new RuntimeException('The access export could not be stored.');
        }

        $zip->addFromString('access.json', $json);
        $zip->close();
    }
}
