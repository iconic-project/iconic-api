<?php

declare(strict_types=1);

namespace App\Actions\Privacy;

use App\Actions\Action;
use App\Actions\Crm\CloseTask;
use App\Enums\ActivityKind;
use App\Enums\SubjectRequestStatus;
use App\Enums\SubjectRequestType;
use App\Models\BehaviouralEvent;
use App\Models\Booking;
use App\Models\Contact;
use App\Models\ContactActivity;
use App\Models\CrmTask;
use App\Models\ErasureLog;
use App\Models\Group;
use App\Models\GuestResponse;
use App\Models\RefundRequest;
use App\Models\SubjectRequest;
use App\Models\User;
use App\Services\Config\CurrentConfig;
use App\Support\BusinessTime;
use App\Support\GuestExperience\ContactGuest;
use App\Support\History\History;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class EraseContact extends Action
{
    public function __construct(
        private readonly CloseTask $close,
        private readonly CurrentConfig $config,
    ) {}

    public function handle(SubjectRequest $request, User $actor, string $verifiedHow, string $confirmation): SubjectRequest
    {
        if ($request->type !== SubjectRequestType::Erasure) {
            throw new HttpException(422, 'Only an erasure request can erase a contact.');
        }

        if ($request->status !== SubjectRequestStatus::Open) {
            throw new HttpException(422, 'This request is already closed.');
        }

        $contact = $request->contact;
        $email = Contact::normalizeEmail($contact->email);

        if ($email === null || Contact::normalizeEmail($confirmation) !== $email) {
            throw new HttpException(422, 'Type the contact email to confirm erasure.');
        }

        $this->guard($contact);

        $months = $this->config->businessRules()->retention->passportMonthsAfterCheckOut;
        $outcome = 'Erased the contact. Kept issued documents, payments, the booking consent log, the consent register and the bookings, which stay linked to this contact id. Guest passport data is anonymised '.$months.' months after check-out; medical notes follow the published retention window. Survey text (why, best, better, crew, call_notes) was cleared on this contact\'s own responses; the score was kept. A later booking with the same email is a new contact and inherits no consent.';

        return $this->transaction(function () use ($request, $actor, $verifiedHow, $contact, $email, $outcome): SubjectRequest {
            $sessions = BehaviouralEvent::query()
                ->where('contact_id', $contact->id)
                ->pluck('session_id');

            BehaviouralEvent::query()
                ->where('contact_id', $contact->id)
                ->orWhereIn('session_id', $sessions)
                ->delete();

            ContactActivity::query()->where('contact_id', $contact->id)->delete();
            ContactActivity::query()->create([
                'contact_id' => $contact->id,
                'kind' => ActivityKind::Note,
                'body' => 'Erased on '.BusinessTime::now()->toDateString(),
                'occurred_at' => Carbon::now(),
            ]);

            GuestResponse::query()
                ->with('guest')
                ->whereIn('booking_id', $this->bookingIds($contact))
                ->orderBy('id')
                ->get()
                ->each(function (GuestResponse $response) use ($email): void {
                    if (! ContactGuest::matches($response->guest->email, $email)) {
                        return;
                    }

                    $response->forceFill([
                        'why' => null,
                        'best' => null,
                        'better' => null,
                        'crew' => null,
                        'call_notes' => null,
                    ])->save();
                });

            ErasureLog::query()->create([
                'contact_id' => $contact->id,
                'email_sha256' => hash('sha256', $email),
                'erased_at' => Carbon::now(),
                'erased_by' => $actor->id,
            ]);

            $contact->forceFill([
                'name' => 'Erased contact #'.$contact->id,
                'email' => null,
                'phone' => null,
                'phone_e164' => null,
                'first_touch' => null,
                'last_touch' => null,
                'country' => null,
            ])->save();

            $request->forceFill([
                'status' => SubjectRequestStatus::Completed,
                'verified_how' => $verifiedHow,
                'outcome' => $outcome,
                'completed_at' => Carbon::now(),
                'completed_by' => $actor->id,
            ])->save();

            History::record($request, 'subject_request.closed', after: [
                'type' => $request->type->value,
                'outcome' => $outcome,
            ], actor: $actor);

            $task = CrmTask::query()->where('idempotency_key', 'subject:'.$request->id)->first();

            if ($task instanceof CrmTask) {
                $this->close->autoClose($task, 'the subject request closed');
            }

            return $request->refresh();
        });
    }

    private function guard(Contact $contact): void
    {
        $today = BusinessTime::now()->toDateString();
        $bookings = Booking::query()->withTrashed()->with('property')->whereIn('id', $this->bookingIds($contact))->get();

        foreach ($bookings as $booking) {
            if ($booking->stay()->checkOut()->toDateString() >= $today) {
                throw new HttpException(409, 'This contact has a booking whose return date has not passed.');
            }
        }

        $openRefund = RefundRequest::query()
            ->whereIn('booking_id', $bookings->pluck('id'))
            ->get()
            ->contains(fn (RefundRequest $refund): bool => $refund->status->isOpen());

        if ($openRefund) {
            throw new HttpException(409, 'This contact has an open refund request.');
        }
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
}
