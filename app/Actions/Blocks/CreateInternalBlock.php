<?php

declare(strict_types=1);

namespace App\Actions\Blocks;

use App\Actions\Action;
use App\Enums\ClaimKind;
use App\Enums\ReferenceType;
use App\Exceptions\CabinUnavailableException;
use App\Models\Cabin;
use App\Models\CabinClaim;
use App\Models\Departure;
use App\Models\InternalBlock;
use App\Services\Inventory\ClaimService;
use App\Services\References\ReferenceService;
use App\Support\Blocks\ConflictMessage;
use App\Support\History\History;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class CreateInternalBlock extends Action
{
    public function __construct(
        private ClaimService $claims,
        private ReferenceService $references,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws CabinUnavailableException
     */
    public function handle(array $data): InternalBlock
    {
        $scopes = $this->resolveScopes($data['departures']);

        try {
            return $this->transaction(function () use ($data, $scopes): InternalBlock {
                $block = InternalBlock::query()->create([
                    'reference' => $this->references->next(ReferenceType::Block),
                    'reason' => $data['reason'],
                    'notes' => $data['notes'] ?? null,
                ]);

                foreach ($scopes as $index => $scope) {
                    try {
                        $this->claims->claim(
                            $scope['departure'],
                            $scope['cabins'],
                            $block,
                            ClaimKind::Block,
                        );
                    } catch (CabinUnavailableException $exception) {
                        throw $this->conflictsAcross(
                            $exception,
                            $scope['departure'],
                            array_slice($scopes, $index + 1),
                            $block,
                        );
                    }
                }

                History::record($block, 'block.created', after: [
                    'reason' => $block->reason->value,
                    'notes' => $block->notes,
                    'departures' => array_map(
                        fn (array $scope): array => [
                            'departure_id' => $scope['departure']->id,
                            'cabin_codes' => $scope['cabins']->pluck('code')->values()->all(),
                        ],
                        $scopes,
                    ),
                ]);

                return $block;
            });
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'departures' => [$exception->getMessage()],
            ]);
        }
    }

    /**
     * @param  list<array{departure_id: int, cabin_codes: list<string>|string}>  $departures
     * @return list<array{departure: Departure, cabins: Collection<int, Cabin>}>
     */
    private function resolveScopes(array $departures): array
    {
        $scopes = [];

        foreach ($departures as $row) {
            $departure = Departure::query()
                ->with(['property.cabins', 'property'])
                ->findOrFail((int) $row['departure_id']);

            $codes = $row['cabin_codes'];
            $cabins = $departure->property->cabins;

            if ($codes !== 'ALL') {
                $wanted = is_array($codes) ? $codes : [];
                $cabins = $cabins
                    ->filter(fn (Cabin $cabin): bool => in_array($cabin->code, $wanted, true))
                    ->values();
            }

            $scopes[] = [
                'departure' => $departure,
                'cabins' => $cabins->sortBy('sort')->values(),
            ];
        }

        return $scopes;
    }

    /**
     * @param  list<array{departure: Departure, cabins: Collection<int, Cabin>}>  $remaining
     */
    private function conflictsAcross(
        CabinUnavailableException $first,
        Departure $failed,
        array $remaining,
        InternalBlock $block,
    ): CabinUnavailableException {
        $unavailable = [];
        $lines = [];

        foreach ($first->unavailable as $row) {
            $unavailable[] = $row;
            $kind = ClaimKind::from($row['held_by']['kind']);
            $lines[] = ConflictMessage::line($failed, $row['cabin']['label'], $kind);
        }

        foreach ($this->scanRemaining($remaining, $block) as $item) {
            $unavailable[] = $item['unavailable'];
            $lines[] = $item['line'];
        }

        return new CabinUnavailableException($unavailable, ConflictMessage::join($lines));
    }

    /**
     * Plain SELECT of remaining requested pairs. No FOR UPDATE / lock in share mode.
     *
     * @param  list<array{departure: Departure, cabins: Collection<int, Cabin>}>  $remaining
     * @return list<array{unavailable: array{cabin: array{id: int, code: string, label: string}, held_by: array{kind: string, holder_type: string, reference: string|null}}, line: string}>
     */
    private function scanRemaining(array $remaining, InternalBlock $block): array
    {
        if ($remaining === []) {
            return [];
        }

        $query = CabinClaim::query()
            ->whereNull('released_at')
            ->where(function ($outer) use ($block): void {
                $outer->where('holder_type', '!=', $block->getMorphClass())
                    ->orWhere('holder_id', '!=', $block->id);
            })
            ->where(function ($outer) use ($remaining): void {
                foreach ($remaining as $scope) {
                    $outer->orWhere(function ($inner) use ($scope): void {
                        $inner->where('departure_id', $scope['departure']->id)
                            ->whereIn('cabin_id', $scope['cabins']->pluck('id'));
                    });
                }
            })
            ->with(['cabin', 'departure.property', 'holder']);

        $items = [];

        foreach ($query->get() as $claim) {
            if ($this->isExpiredHold($claim)) {
                continue;
            }

            $kind = $claim->kind;
            $holder = $claim->holder;

            $items[] = [
                'unavailable' => [
                    'cabin' => [
                        'id' => $claim->cabin->id,
                        'code' => $claim->cabin->code,
                        'label' => $claim->cabin->label,
                    ],
                    'held_by' => [
                        'kind' => $kind->value,
                        'holder_type' => $claim->holder_type,
                        'reference' => $this->holderReference($holder),
                    ],
                ],
                'line' => ConflictMessage::line($claim->departure, $claim->cabin->label, $kind),
            ];
        }

        return $items;
    }

    private function isExpiredHold(CabinClaim $claim): bool
    {
        return $claim->kind === ClaimKind::Hold
            && $claim->expires_at !== null
            && $claim->expires_at->isPast();
    }

    private function holderReference(mixed $holder): ?string
    {
        if (! $holder instanceof Model) {
            return null;
        }

        if (method_exists($holder, 'historyLabel')) {
            $label = $holder->historyLabel();

            if (is_string($label) && $label !== '') {
                return $label;
            }
        }

        $reference = $holder->getAttribute('reference');

        return is_string($reference) && $reference !== '' ? $reference : null;
    }
}
