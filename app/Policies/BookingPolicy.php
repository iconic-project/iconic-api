<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Booking;
use App\Models\User;
use Illuminate\Auth\Access\Response;

final class BookingPolicy extends Policy
{
    public function viewAny(User $actor): bool
    {
        return $actor->hasPermission(Permission::PanelRms);
    }

    public function view(User $actor, Booking $booking): bool
    {
        return $actor->hasPermission(Permission::BookingsViewAll)
            || (int) $booking->owner_id === (int) $actor->id;
    }

    public function viewHistory(User $actor, Booking $booking): bool
    {
        return $this->view($actor, $booking);
    }

    public function viewAudit(User $actor): bool
    {
        return $actor->hasPermission(Permission::BookingsViewAll);
    }

    public function viewOwners(User $actor): bool
    {
        return $actor->hasPermission(Permission::RecordsActOnAny);
    }

    public function create(User $actor): bool
    {
        return $actor->hasPermission(Permission::BookingsCreate);
    }

    public function changeStatus(User $actor, Booking $booking): Response
    {
        if (! $actor->hasPermission(Permission::BookingsChangeStatus)) {
            return Response::deny();
        }

        return $this->ownsOrMayActOnAny($actor, $booking)
            ? Response::allow()
            : Response::deny('Blocked: own-records rule.');
    }

    public function frontDesk(User $actor, Booking $booking): Response
    {
        if (! $actor->hasPermission(Permission::BookingsFrontDesk)) {
            return Response::deny();
        }

        return $this->ownsOrMayActOnAny($actor, $booking)
            ? Response::allow()
            : Response::deny('Blocked: own-records rule.');
    }

    public function undoCheckIn(User $actor, Booking $booking): bool
    {
        $actor->loadMissing('role');

        return $actor->role->isAdmin();
    }

    public function move(User $actor, Booking $booking): Response
    {
        if (! $actor->hasPermission(Permission::BookingsMove)) {
            return Response::deny();
        }

        return $this->ownsOrMayActOnAny($actor, $booking)
            ? Response::allow()
            : Response::deny('Blocked: own-records rule.');
    }

    public function delete(User $actor, Booking $booking): bool
    {
        return $actor->hasPermission(Permission::BookingsDelete);
    }

    public function confirm(User $actor, Booking $booking): Response
    {
        if (! $actor->hasPermission(Permission::RequestsConfirm)) {
            return Response::deny();
        }

        return $this->ownsOrMayActOnAny($actor, $booking)
            ? Response::allow()
            : Response::deny('Blocked: own-records rule.');
    }

    public function release(User $actor, Booking $booking): Response
    {
        if (! $actor->hasPermission(Permission::RequestsRelease)) {
            return Response::deny();
        }

        return $this->ownsOrMayActOnAny($actor, $booking)
            ? Response::allow()
            : Response::deny('Blocked: own-records rule.');
    }

    public function recordPayment(User $actor, Booking $booking): bool
    {
        return $actor->hasPermission(Permission::PaymentsRecord);
    }

    public function overdueDecision(User $actor, Booking $booking): Response
    {
        if (! $actor->hasPermission(Permission::BookingsOverdueDecision)) {
            return Response::deny();
        }

        return $this->ownsOrMayActOnAny($actor, $booking)
            ? Response::allow()
            : Response::deny('Blocked: own-records rule.');
    }

    public function commissionApproval(User $actor, Booking $booking): bool
    {
        return $actor->hasPermission(Permission::CommissionsOverrideCap);
    }

    public function viewCommissions(User $actor): bool
    {
        return $actor->hasPermission(Permission::BookingsViewAll);
    }

    public function recordPayout(User $actor, Booking $booking): bool
    {
        return $actor->hasPermission(Permission::CommissionsRecordPayout);
    }

    public function recordConsent(User $actor, Booking $booking): Response
    {
        return $this->ownsOrMayActOnAny($actor, $booking)
            ? Response::allow()
            : Response::deny('Blocked: own-records rule.');
    }

    public function updateExtras(User $actor, Booking $booking): Response
    {
        return $this->ownsOrMayActOnAny($actor, $booking)
            ? Response::allow()
            : Response::deny('Blocked: own-records rule.');
    }

    public function updateFees(User $actor, Booking $booking): Response
    {
        return $this->updateExtras($actor, $booking);
    }

    public function updateBilling(User $actor, Booking $booking): Response
    {
        return $this->ownsOrMayActOnAny($actor, $booking)
            ? Response::allow()
            : Response::deny('Blocked: own-records rule.');
    }

    public function issueCompleteLink(User $actor, Booking $booking): Response
    {
        return $this->updateBilling($actor, $booking);
    }

    public function issueDocument(User $actor, Booking $booking): Response
    {
        return $this->updateBilling($actor, $booking);
    }

    public function updateGuests(User $actor, Booking $booking): Response
    {
        if (! $this->ownsOrMayActOnAny($actor, $booking)) {
            return Response::deny('Blocked: own-records rule.');
        }

        if ($this->writesSensitiveGuestNotes() && ! $actor->hasPermission(Permission::GuestsViewSensitive)) {
            return Response::deny();
        }

        return Response::allow();
    }

    public function update(User $actor, Booking $booking): Response
    {
        $notes = request()->exists('internal_notes');
        $owner = request()->exists('owner_id');

        if ($owner && ! $actor->hasPermission(Permission::RecordsActOnAny)) {
            return Response::deny();
        }

        if (($notes || ! $owner) && ! $this->ownsOrMayActOnAny($actor, $booking)) {
            return Response::deny('Blocked: own-records rule.');
        }

        return Response::allow();
    }

    private function writesSensitiveGuestNotes(): bool
    {
        return request()->exists('medical_note')
            || request()->exists('dietary_note')
            || request()->exists('accessibility_note');
    }
}
