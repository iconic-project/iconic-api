<?php

declare(strict_types=1);

namespace App\Http\Resources\Engine;

use App\Enums\ConsentDocument;
use App\Models\Booking;
use App\Models\Consent;
use App\Services\Config\CurrentConfig;
use App\Support\Complete\CompleteDue;
use App\Support\Complete\CompletePayability;
use App\Support\Countries;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * @mixin Booking
 */
class CompleteReservationResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array{
     *     bookings: list<CompleteBookingResource>,
     *     billing: array{billing_name: string|null, billing_address: string|null, billing_email: string|null, billing_phone: string|null},
     *     declarations: list<array{document: string, label: string, version: string, accepted: bool, required: bool}>,
     *     amount_due: int,
     *     amount_due_kind: string,
     *     amount_due_label: string,
     *     can_pay: bool,
     *     pay_url: string|null,
     *     countries: list<array{code: string, name: string}>
     * }
     *
     * @phpstan-return array{
     *     bookings: AnonymousResourceCollection,
     *     billing: array{billing_name: string|null, billing_address: string|null, billing_email: string|null, billing_phone: string|null},
     *     declarations: list<array{document: string, label: string, version: string, accepted: bool, required: bool}>,
     *     amount_due: int,
     *     amount_due_kind: string,
     *     amount_due_label: string,
     *     can_pay: bool,
     *     pay_url: string|null,
     *     countries: list<array{code: string, name: string}>
     * }
     */
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing([
            'property',
            'room.roomType',
            'guests',
            'consents',
            'paymentLinks',
            'group.bookings.property',
            'group.bookings.room.roomType',
            'group.bookings.guests',
        ]);

        $due = CompleteDue::for($this->resource);
        $versions = app(CurrentConfig::class)->businessRules()->consentVersions;
        $visible = $this->visibleBookings();

        return [
            'bookings' => CompleteBookingResource::collection($visible),
            'billing' => [
                'billing_name' => $this->billing_name,
                'billing_address' => $this->billing_address,
                'billing_email' => $this->billing_email,
                'billing_phone' => $this->billing_phone,
            ],
            'declarations' => array_map(
                function (ConsentDocument $document) use ($versions): array {
                    $current = $versions->for($document);
                    $accepted = $this->consents->contains(
                        fn (Consent $consent): bool => $consent->document === $document
                            && ! $consent->withdrawn
                            && $consent->version === $current,
                    );

                    return [
                        'document' => $document->value,
                        'label' => $document->label(),
                        'version' => $current,
                        'accepted' => $accepted,
                        'required' => $document->required(),
                    ];
                },
                ConsentDocument::checklist(),
            ),
            'amount_due' => $due->amount,
            'amount_due_kind' => $due->kind->value,
            'amount_due_label' => $due->kind->label(),
            'can_pay' => CompletePayability::canPay($this->resource, $versions, $due->payUrl),
            'pay_url' => $due->payUrl,
            'countries' => array_map(
                fn (string $code, string $name): array => ['code' => $code, 'name' => $name],
                array_keys(Countries::all()),
                array_values(Countries::all()),
            ),
        ];
    }

    /**
     * @return Collection<int, Booking>
     */
    private function visibleBookings(): Collection
    {
        if ($this->group_id === null || $this->group === null) {
            return collect([$this->resource]);
        }

        return $this->group->bookings->sortBy('id')->values();
    }
}
