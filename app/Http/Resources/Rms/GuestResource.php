<?php

declare(strict_types=1);

namespace App\Http\Resources\Rms;

use App\Enums\Permission;
use App\Models\Guest;
use App\Models\User;
use App\Support\Guests\Age;
use App\Support\Guests\Masking;
use App\Support\Iso;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Guest
 */
class GuestResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array{
     *     id: int,
     *     booking_id: int,
     *     position: int,
     *     is_lead: bool,
     *     first_name: string,
     *     last_name: string,
     *     display_name: string,
     *     dob: string|null,
     *     nationality: string|null,
     *     ecuador_resident: bool,
     *     passport_no: string|null,
     *     passport_expiry: string|null,
     *     email: string|null,
     *     insurance_declared: bool,
     *     medical_note: MaskedNoteResource,
     *     dietary_note: MaskedNoteResource,
     *     accessibility_note: MaskedNoteResource,
     *     age_at_departure: int|null,
     *     is_minor_now: bool,
     *     png_fee: int|null,
     *     complete: bool,
     *     guardian: array{name: string|null, relationship: string|null, consented_at: string|null}|null
     * }
     */
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing('booking');

        $actor = $request->user();
        $canViewSensitive = $actor instanceof User
            && $actor->hasPermission(Permission::GuestsViewSensitive);
        $age = Age::at($this->dob, $this->booking->check_in);
        $minor = $this->isMinorNow();

        return [
            'id' => $this->id,
            'booking_id' => $this->booking_id,
            'position' => $this->position,
            'is_lead' => $this->is_lead,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'display_name' => $this->displayName(),
            'dob' => $this->dob?->toDateString(),
            'nationality' => $this->nationality,
            'ecuador_resident' => $this->ecuador_resident,
            'passport_no' => Masking::passport($this->passport_no, $canViewSensitive),
            'passport_expiry' => $this->passport_expiry?->toDateString(),
            'email' => $this->email,
            'insurance_declared' => $this->insurance_declared,
            'medical_note' => new MaskedNoteResource(Masking::note($this->medical_note, $canViewSensitive)),
            'dietary_note' => new MaskedNoteResource(Masking::note($this->dietary_note, $canViewSensitive)),
            'accessibility_note' => new MaskedNoteResource(Masking::note($this->accessibility_note, $canViewSensitive)),
            'age_at_departure' => $age,
            'is_minor_now' => $minor,
            'png_fee' => $this->png_fee,
            'complete' => $this->isComplete(),
            'guardian' => $minor ? [
                'name' => $this->guardian_name,
                'relationship' => $this->guardian_relationship,
                'consented_at' => Iso::utc($this->guardian_consented_at),
            ] : null,
        ];
    }
}
