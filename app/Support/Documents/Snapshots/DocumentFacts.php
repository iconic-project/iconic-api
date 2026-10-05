<?php

declare(strict_types=1);

namespace App\Support\Documents\Snapshots;

use App\Enums\BookingStatus;
use App\Enums\BookingType;
use App\Enums\PaymentKind;
use App\Enums\PngCategory;
use App\Models\Booking;
use App\Models\BookingExtra;
use App\Models\Guest;
use App\Models\Payment;
use App\Services\Config\CurrentConfig;
use App\Support\BusinessTime;
use App\Support\Payments\CancellationPenalty;
use App\Support\Payments\Ledger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

final class DocumentFacts
{
    public const INSURANCE = 'Travel insurance is the sole responsibility of the passenger. Iconic does not sell or intermediate travel insurance.';

    public const CAPTURED_AT_PAYMENT_LINK = '[captured at payment link]';

    public function __construct(
        public readonly Booking $booking,
        public readonly bool $fresh,
        public readonly int $vessel,
        public readonly int $feesCollected,
        public readonly int $extras,
        public readonly int $chargesTotal,
        public readonly int $paid,
        public readonly int $balance,
        public readonly int $cruiseOutstanding,
        public readonly int $depositAmount,
        public readonly int $informationTotal,
    ) {}

    public static function load(Booking $booking, bool $fresh): self
    {
        $booking->loadMissing([
            'departure.property',
            'departure.itinerary',
            'cabin',
            'contact',
            'group.coordinator',
            'agency',
            'guests',
            'extras',
            'payments',
        ]);

        $vessel = $booking->total;
        $fees = $fresh ? $booking->feesCollectedFresh() : $booking->feesCollectedTotal();
        $extras = $fresh ? $booking->extrasTotalFresh() : $booking->extrasTotal();
        $charges = $fresh ? $booking->chargesTotalFresh() : $booking->chargesTotal();
        $paid = $fresh ? Ledger::paidFresh($booking) : Ledger::paid($booking);
        $balance = $fresh ? $booking->balanceFresh() : $booking->balance();
        $cruise = $fresh ? $booking->cruiseOutstandingFresh() : $booking->cruiseOutstanding();

        return new self(
            $booking,
            $fresh,
            $vessel,
            $fees,
            $extras,
            $charges,
            $paid,
            $balance,
            $cruise,
            $booking->depositAmount(),
            self::informationFeeTotal($booking),
        );
    }

    /**
     * @return array<string, int>
     */
    public function totals(): array
    {
        return [
            'vessel' => $this->vessel,
            'fees_collected' => $this->feesCollected,
            'extras' => $this->extras,
            'charges_total' => $this->chargesTotal,
            'paid' => $this->paid,
            'balance' => $this->balance,
            'cruise_outstanding' => $this->cruiseOutstanding,
            'deposit_amount' => $this->depositAmount,
            'cruise_balance_amount' => $this->vessel - $this->depositAmount,
            'extras_and_fees' => $this->extras + $this->feesCollected,
            'information_total' => $this->informationTotal,
        ];
    }

    /**
     * @return array{kind: string, title: string, number: string|null, version: int, issued_at: string|null}
     */
    public function document(string $kind, string $title, int $version = 1): array
    {
        return [
            'kind' => $kind,
            'title' => $title,
            'number' => null,
            'version' => $version,
            'issued_at' => null,
        ];
    }

    public function nextVersion(string $kind): int
    {
        $max = (int) $this->booking->documents()
            ->where('kind', $kind)
            ->max('version');

        return $max + 1;
    }

    public function statusLine(): string
    {
        if ($this->balance === 0) {
            return 'PAID IN FULL';
        }

        if ($this->depositReceived()) {
            return 'DEPOSIT RECEIVED';
        }

        return 'AWAITING DEPOSIT';
    }

    public function depositReceived(): bool
    {
        if ($this->paid >= $this->depositAmount && $this->depositAmount > 0) {
            return true;
        }

        return $this->settledPayments()
            ->contains(fn (Payment $payment): bool => $payment->kind === PaymentKind::Deposit);
    }

    public function cruiseReceived(): bool
    {
        return $this->cruiseOutstanding === 0;
    }

    public function invoiceDate(): string
    {
        $deposit = $this->settledPayments()
            ->first(fn (Payment $payment): bool => $payment->kind === PaymentKind::Deposit);

        if ($deposit instanceof Payment) {
            return $this->shortDate($deposit->paid_at);
        }

        return $this->shortDate(BusinessTime::now());
    }

    /**
     * @return array{name: string, address_lines: list<string>, email: string, website: string, ein: string}
     */
    public function issuer(): array
    {
        $entity = app(CurrentConfig::class)->businessRules()->legalEntity;

        return [
            'name' => $entity->name,
            'address_lines' => $entity->addressLines,
            'email' => $entity->email,
            'website' => $entity->website,
            'ein' => $entity->ein,
        ];
    }

    /**
     * @return array{bank_name: string, account_name: string, account_number: string, routing: string, swift: string, payment_reference: string, placeholders: bool}
     */
    public function bank(): array
    {
        $bank = app(CurrentConfig::class)->businessRules()->legalEntity->bank->toArray();
        $placeholders = in_array('[TBD]', $bank, true);

        return [
            ...$bank,
            'payment_reference' => ($this->booking->displayReference() ?? '').' — include in all transfers',
            'placeholders' => $placeholders,
        ];
    }

    /**
     * @return array{guest: string, address: string, email: string, phone: string|null, agent: array{name: string, contact: string}|null, group: array{reference: string, coordinator: string}|null}
     */
    public function billing(): array
    {
        $booking = $this->booking;
        $names = $this->guestNames();
        $guest = implode(' & ', array_slice($names, 0, 2));

        if ($guest === '') {
            $guest = $booking->contact->name;
        }

        $address = is_string($booking->billing_address) && trim($booking->billing_address) !== ''
            ? $booking->billing_address
            : self::CAPTURED_AT_PAYMENT_LINK;

        $email = $booking->billing_email ?? $booking->contact->email ?? '—';

        $phone = $booking->billing_phone
            ?? $booking->contact->phone;

        $agent = $booking->agency === null ? null : [
            'name' => $booking->agency->name,
            'contact' => $booking->agency->contact,
        ];

        $group = $booking->group === null ? null : [
            'reference' => $booking->group->reference,
            'coordinator' => $booking->group->coordinator->name,
        ];

        return [
            'guest' => $guest,
            'address' => $address,
            'email' => $email,
            'phone' => $phone,
            'agent' => $agent,
            'group' => $group,
        ];
    }

    /**
     * @return array{
     *     property: string,
     *     embark: string,
     *     disembark: string,
     *     departure_date: string,
     *     departure_date_short: string,
     *     return_date: string,
     *     nights: int,
     *     days: int,
     *     itinerary: string,
     *     guests: list<string>,
     *     guest_count: int,
     *     cabin_label: string,
     *     occupancy: string,
     *     charter: bool
     * }
     */
    public function cruise(): array
    {
        $booking = $this->booking;
        $itinerary = $booking->departure->itinerary;
        $departure = $booking->departure->date;
        $return = $booking->departure->returnDate();
        $names = $this->guestNames();
        $count = $names === [] ? $booking->adults + $booking->children : count($names);
        $nights = $itinerary->nights;
        $days = $itinerary->days > 0 ? $itinerary->days : $nights;

        return [
            'property' => $booking->departure->property->name,
            'embark' => $itinerary->embark,
            'disembark' => $itinerary->disembark,
            'departure_date' => $this->longDate($departure),
            'departure_date_short' => $this->shortDate($departure),
            'return_date' => $this->longDate($return),
            'nights' => $nights,
            'days' => $days,
            'itinerary' => $itinerary->name,
            'guests' => $names,
            'guest_count' => $count,
            'cabin_label' => $booking->cabinLabel(),
            'occupancy' => $this->occupancy($count),
            'charter' => $booking->type === BookingType::Charter,
        ];
    }

    /**
     * @return list<array{concept: string, qty: string, rate: int|null, amount: int}>
     */
    public function vesselRows(): array
    {
        $rows = [];

        foreach ($this->booking->price_lines as $line) {
            $rows[] = [
                'concept' => $line['label'],
                'qty' => '',
                'rate' => null,
                'amount' => $line['amount'],
            ];
        }

        return $rows;
    }

    /**
     * @return array{collected: list<array{concept: string, qty: string, rate: int|null, amount: int}>, information: list<array{concept: string, qty: string, rate: int|null, amount: int}>}
     */
    public function feeRows(): array
    {
        $png = $this->pngRows();
        $tct = $this->tctRow();
        $collected = [];
        $information = [];

        if ($this->booking->png_collected) {
            $collected = array_merge($collected, $png);
        } else {
            $information = array_merge($information, $png);
        }

        if ($tct !== null) {
            if ($this->booking->tct_collected) {
                $collected[] = $tct;
            } else {
                $information[] = $tct;
            }
        }

        return [
            'collected' => $collected,
            'information' => $information,
        ];
    }

    /**
     * @return list<array{concept: string, qty: string, rate: int|null, amount: int}>
     */
    public function extrasRows(): array
    {
        return $this->booking->extras
            ->map(fn (BookingExtra $extra): array => [
                'concept' => $extra->name.($extra->note !== null && $extra->note !== '' ? ' — '.$extra->note : ''),
                'qty' => (string) $extra->qty,
                'rate' => $extra->rate_usd,
                'amount' => $extra->amount(),
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array{date: string, method: string, amount: int, reference: string}>
     */
    public function paymentRows(): array
    {
        return $this->settledPayments()
            ->map(fn (Payment $payment): array => [
                'date' => $this->shortDate($payment->paid_at),
                'method' => $payment->method->label(),
                'amount' => $payment->amount,
                'reference' => $payment->reference,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array{
     *     extras_due_hours: int,
     *     extras_due_date: string,
     *     deposit_pct: int,
     *     balance_pct: int,
     *     deposit_received: bool,
     *     cruise_received: bool,
     *     on_board_note: bool,
     *     cancellation: string
     * }
     */
    public function schedule(): array
    {
        $rules = app(CurrentConfig::class)->businessRules();
        $hours = $rules->payments->extrasDueHours;
        $bands = $rules->bands;
        $labels = [];

        foreach ($bands as $band) {
            $labels[] = CancellationPenalty::label($band, $bands).': '.$band->penaltyPct.'% penalty';
        }

        return [
            'extras_due_hours' => $hours,
            'extras_due_date' => $this->shortDate($this->booking->extrasDueAt()),
            'deposit_pct' => $this->booking->deposit_pct,
            'balance_days' => $this->booking->balance_days,
            'balance_pct' => 100 - $this->booking->deposit_pct,
            'deposit_received' => $this->depositReceived(),
            'cruise_received' => $this->cruiseReceived(),
            'on_board_note' => in_array($this->booking->status, [
                BookingStatus::InHouse,
                BookingStatus::CheckedOut,
            ], true),
            'cancellation' => implode(' · ', $labels),
        ];
    }

    /**
     * @return list<string>
     */
    public function guestNames(): array
    {
        return $this->booking->guests
            ->filter(fn (Guest $guest): bool => $guest->first_name !== '' || $guest->last_name !== '')
            ->map(fn (Guest $guest): string => $guest->displayName())
            ->values()
            ->all();
    }

    public function leadGuest(): ?Guest
    {
        return $this->booking->guests->first(
            fn (Guest $guest): bool => $guest->is_lead && ($guest->first_name !== '' || $guest->last_name !== ''),
        ) ?? $this->booking->guests->first(
            fn (Guest $guest): bool => $guest->first_name !== '' || $guest->last_name !== '',
        );
    }

    public function leadName(): string
    {
        return $this->leadGuest()?->displayName() ?? $this->booking->contact->name;
    }

    public static function bookingHasTransferVoucher(Booking $booking): bool
    {
        $booking->loadMissing('extras');
        $catalogue = app(CurrentConfig::class)->extras();

        foreach ($booking->extras as $extra) {
            if ($catalogue->find($extra->code)?->triggersTransferVoucher === true) {
                return true;
            }
        }

        return false;
    }

    public function hasTransferVoucherExtra(): bool
    {
        return self::bookingHasTransferVoucher($this->booking);
    }

    public function hasPreCruiseHotel(): bool
    {
        return $this->booking->extras->contains(
            fn (BookingExtra $extra): bool => $extra->code === 'HPRE',
        );
    }

    public function wireAmount(): int
    {
        if ($this->booking->status === BookingStatus::PendingPayment) {
            return $this->depositAmount;
        }

        return $this->balance;
    }

    /**
     * @return Collection<int, Payment>
     */
    public function settledPayments(): Collection
    {
        return $this->booking->payments
            ->filter(fn (Payment $payment): bool => $payment->status->countsAsPaid())
            ->sortBy([
                fn (Payment $payment): string => $payment->paid_at->toDateString(),
                fn (Payment $payment): int => $payment->id,
            ])
            ->values();
    }

    public function paidThrough(Payment $payment): int
    {
        return (int) $this->settledPayments()
            ->filter(function (Payment $row) use ($payment): bool {
                $date = $row->paid_at->toDateString();
                $cut = $payment->paid_at->toDateString();

                return $date < $cut || ($date === $cut && $row->id <= $payment->id);
            })
            ->sum(fn (Payment $row): int => $row->amount);
    }

    public function shortDate(CarbonImmutable $date): string
    {
        return $date->format('j M Y');
    }

    public function longDate(CarbonImmutable $date): string
    {
        return $date->format('l, F j, Y');
    }

    /**
     * @return list<array{concept: string, qty: string, rate: int|null, amount: int}>
     */
    private function pngRows(): array
    {
        $exempt = app(CurrentConfig::class)->engineSettings()->fees->png->exemptUnderAge;
        $groups = [];

        foreach ($this->booking->guests as $guest) {
            if (! $guest->png_category instanceof PngCategory || $guest->png_fee === null) {
                continue;
            }

            $label = 'PNG Entry Fee — '.$guest->png_category->label($exempt);
            $groups[$label] ??= ['concept' => $label, 'count' => 0, 'rate' => $guest->png_fee, 'amount' => 0];
            $groups[$label]['count']++;
            $groups[$label]['amount'] += $guest->png_fee;
        }

        return array_values(array_map(fn (array $row): array => [
            'concept' => $row['concept'],
            'qty' => (string) $row['count'],
            'rate' => $row['rate'],
            'amount' => $row['amount'],
        ], $groups));
    }

    /**
     * @return array{concept: string, qty: string, rate: int|null, amount: int}|null
     */
    private function tctRow(): ?array
    {
        $count = $this->booking->guests->count();

        if ($count === 0) {
            $count = $this->booking->adults + $this->booking->children;
        }

        if ($count === 0) {
            return null;
        }

        $rate = $this->booking->tct_collected
            ? (int) ($this->booking->tct_rate_usd ?? app(CurrentConfig::class)->engineSettings()->fees->tctPp)
            : app(CurrentConfig::class)->engineSettings()->fees->tctPp;

        return [
            'concept' => 'TCT — Transit Control Card / INGALA',
            'qty' => (string) $count,
            'rate' => $rate,
            'amount' => $rate * $count,
        ];
    }

    private static function informationFeeTotal(Booking $booking): int
    {
        $total = 0;

        if (! $booking->png_collected) {
            $total += (int) $booking->guests->sum(fn (Guest $guest): int => $guest->png_fee ?? 0);
        }

        if (! $booking->tct_collected) {
            $count = $booking->guests->count();
            if ($count === 0) {
                $count = $booking->adults + $booking->children;
            }
            $rate = app(CurrentConfig::class)->engineSettings()->fees->tctPp;
            $total += $rate * $count;
        }

        return $total;
    }

    private function occupancy(int $count): string
    {
        return match ($count) {
            1 => 'Single occupancy',
            2 => 'Double occupancy',
            3 => 'Triple occupancy',
            default => $count.' occupancy',
        };
    }
}
