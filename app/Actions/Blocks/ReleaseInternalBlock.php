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

final class ReleaseInternalBlock extends Action
{
    public function __construct(private ClaimService $claims) {}

    public function handle(InternalBlock $block, string $note, User $actor): InternalBlock
    {
        if ($block->released_at !== null) {
            throw new ConflictException('This block is already released.');
        }

        return $this->transaction(function () use ($block, $note, $actor): InternalBlock {
            $this->claims->release($block, ReleaseReason::Released);

            $block->released_at = now();
            $block->released_by = $actor->id;
            $block->release_note = $note;
            $block->save();

            History::record(
                $block,
                'block.released',
                after: [
                    'released_at' => $block->released_at->toJSON(),
                    'release_note' => $note,
                ],
                reason: $note,
                actor: $actor,
            );

            return $block;
        });
    }
}
