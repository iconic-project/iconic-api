<?php

declare(strict_types=1);

namespace App\Actions\RoomTypes;

use App\Actions\Action;
use App\Models\RoomType;
use App\Services\Engine\EngineFeedVersion;
use App\Support\History\History;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

final class UpdateRoomTypeContent extends Action
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(RoomType $type, array $data): RoomType
    {
        unset($data['code'], $data['property_id'], $data['status']);

        if (array_key_exists('photos', $data)) {
            $this->assertKnownPhotos($type, $data['photos']);
        }

        $removed = $this->removedPhotoPaths($type, $data['photos'] ?? null);
        $changed = false;

        $type = $this->transaction(function () use ($type, $data, $removed, &$changed): RoomType {
            $type->fill($data);

            if (! $type->isDirty()) {
                return $type;
            }

            $type->save();
            $changed = true;

            [$before, $after] = History::diff($type);

            if ($before !== []) {
                History::record($type, 'room_type.updated', $before, $after);
            }

            if ($removed !== []) {
                DB::afterCommit(static function () use ($removed): void {
                    Storage::disk('public')->delete($removed);
                });
            }

            return $type;
        });

        if ($changed) {
            EngineFeedVersion::bump();
        }

        return $type;
    }

    private function assertKnownPhotos(RoomType $type, mixed $photos): void
    {
        if (! is_array($photos)) {
            return;
        }

        $known = [];

        foreach ($type->photos ?? [] as $photo) {
            if ($photo['path'] !== '') {
                $known[$photo['path']] = true;
            }
        }

        foreach ($photos as $photo) {
            if (! is_array($photo)) {
                throw ValidationException::withMessages([
                    'photos' => ['Upload a photo. A path cannot be typed in.'],
                ]);
            }

            $path = $photo['path'] ?? null;

            if (! is_string($path) || $path === '' || ! isset($known[$path])) {
                throw ValidationException::withMessages([
                    'photos' => ['Upload a photo. A path cannot be typed in.'],
                ]);
            }
        }
    }

    /**
     * @return list<string>
     */
    private function removedPhotoPaths(RoomType $type, mixed $photos): array
    {
        if (! is_array($photos)) {
            return [];
        }

        $kept = [];

        foreach ($photos as $photo) {
            if (is_array($photo)) {
                $path = $photo['path'] ?? null;

                if (is_string($path) && $path !== '') {
                    $kept[$path] = true;
                }
            }
        }

        $removed = [];

        foreach ($type->photos ?? [] as $photo) {
            if ($photo['path'] !== '' && ! isset($kept[$photo['path']])) {
                $removed[] = $photo['path'];
            }
        }

        return $removed;
    }
}
