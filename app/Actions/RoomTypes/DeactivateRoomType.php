<?php

declare(strict_types=1);

namespace App\Actions\RoomTypes;

use App\Actions\Action;
use App\Enums\RoomTypeStatus;
use App\Models\RoomType;
use App\Support\History\History;
use App\Support\Rooms\FutureClaimGuard;

final class DeactivateRoomType extends Action
{
    public function __construct(private readonly FutureClaimGuard $claims) {}

    public function handle(RoomType $type): RoomType
    {
        return $this->transaction(function () use ($type): RoomType {
            if ($type->status === RoomTypeStatus::Inactive) {
                return $type;
            }

            $this->claims->assertNone(
                $type->rooms()->pluck('id'),
                'This room type has a future claim.',
            );

            $before = $type->status->value;
            $type->status = RoomTypeStatus::Inactive;
            $type->save();

            History::record($type, 'room_type.deactivated', ['status' => $before], ['status' => $type->status->value]);

            return $type;
        });
    }
}
