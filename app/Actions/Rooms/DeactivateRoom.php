<?php

declare(strict_types=1);

namespace App\Actions\Rooms;

use App\Actions\Action;
use App\Enums\RoomStatus;
use App\Models\Room;
use App\Support\History\History;
use App\Support\Rooms\FutureClaimGuard;

final class DeactivateRoom extends Action
{
    public function __construct(private readonly FutureClaimGuard $claims) {}

    public function handle(Room $room): Room
    {
        return $this->transaction(function () use ($room): Room {
            if ($room->status === RoomStatus::Inactive) {
                return $room;
            }

            $this->claims->assertNone([$room->id], 'This room has a future claim.');

            $before = $room->status->value;
            $room->status = RoomStatus::Inactive;
            $room->save();

            History::record($room, 'room.deactivated', ['status' => $before], ['status' => $room->status->value]);

            return $room;
        });
    }
}
