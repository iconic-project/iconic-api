<?php

declare(strict_types=1);

namespace App\Actions\Blocks;

use App\Actions\Action;
use App\Enums\ReleaseReason;
use App\Exceptions\ConflictException;
use App\Models\InternalBlock;
use App\Models\User;
use App\Services\Inventory\ClaimService;
use App\Support\History\History;
use App\Support\Stays\StayDates;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class ShortenInternalBlock extends Action
{
    public function __construct(private ClaimService $claims) {}

    public function handle(
        InternalBlock $block,
        string $startsOn,
        string $endsOn,
        string $reason,
        User $actor,
    ): InternalBlock {
        if ($block->released_at !== null) {
            throw new ConflictException('This block is already released.');
        }

        $oldStart = $block->starts_on->toDateString();
        $oldEnd = $block->ends_on->toDateString();
        $slice = $this->slice($oldStart, $oldEnd, $startsOn, $endsOn);

        return $this->transaction(function () use ($block, $startsOn, $endsOn, $oldStart, $oldEnd, $reason, $actor, $slice): InternalBlock {
            $this->claims->release($block, ReleaseReason::Released, null, $slice);

            $block->starts_on = self::date($startsOn);
            $block->ends_on = self::date($endsOn);
            $block->save();

            History::record(
                $block,
                'block.shortened',
                before: [
                    'starts_on' => $oldStart,
                    'ends_on' => $oldEnd,
                ],
                after: [
                    'starts_on' => $startsOn,
                    'ends_on' => $endsOn,
                ],
                reason: $reason,
                actor: $actor,
            );

            return $block;
        });
    }

    private static function date(string $value): CarbonImmutable
    {
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);

        if (! $date instanceof CarbonImmutable) {
            throw new InvalidArgumentException('Invalid calendar date ['.$value.'].');
        }

        return $date;
    }

    private function slice(string $oldStart, string $oldEnd, string $startsOn, string $endsOn): StayDates
    {
        if ($startsOn === $oldStart && $endsOn === $oldEnd) {
            throw ValidationException::withMessages([
                'ends_on' => ['The range did not change.'],
            ]);
        }

        $leading = $startsOn > $oldStart && $endsOn === $oldEnd;
        $trailing = $startsOn === $oldStart && $endsOn < $oldEnd && $endsOn > $oldStart;

        if ($leading) {
            return StayDates::of($oldStart, $startsOn);
        }

        if ($trailing) {
            return StayDates::of($endsOn, $oldEnd);
        }

        throw ValidationException::withMessages([
            'ends_on' => ['Shorten either the start or the end.'],
        ]);
    }
}
