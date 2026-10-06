<?php

declare(strict_types=1);

namespace App\Actions\RoomTypes;

use App\Actions\Action;
use App\Models\RoomType;
use App\Services\Engine\EngineFeedVersion;
use App\Support\History\History;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

final class AddRoomTypePhoto extends Action
{
    public function handle(RoomType $type, UploadedFile $file, string $alt): RoomType
    {
        $newPath = $file->store('room-types', 'public');

        if (! is_string($newPath) || $newPath === '') {
            throw new RuntimeException('The room type image could not be stored.');
        }

        try {
            $type = $this->transaction(function () use ($type, $newPath, $alt): RoomType {
                $photos = $type->photos ?? [];
                $photos[] = ['path' => $newPath, 'alt' => $alt];
                $type->photos = $photos;
                $type->save();

                History::record(
                    $type,
                    'room_type.photo_added',
                    null,
                    ['path' => $newPath, 'alt' => $alt],
                );

                return $type;
            });
        } catch (Throwable $exception) {
            Storage::disk('public')->delete($newPath);

            throw $exception;
        }

        EngineFeedVersion::bump();

        return $type;
    }
}
