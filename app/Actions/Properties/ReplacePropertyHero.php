<?php

declare(strict_types=1);

namespace App\Actions\Properties;

use App\Actions\Action;
use App\Models\Property;
use App\Services\Engine\EngineFeedVersion;
use App\Support\History\History;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

final class ReplacePropertyHero extends Action
{
    public function handle(Property $property, UploadedFile $file, string $alt): Property
    {
        $newPath = $file->store('properties', 'public');

        if (! is_string($newPath) || $newPath === '') {
            throw new RuntimeException('The property image could not be stored.');
        }

        $previousPath = $property->hero_image_path;
        $previousAlt = $property->hero_alt;

        try {
            $property = $this->transaction(function () use ($property, $newPath, $alt, $previousPath, $previousAlt): Property {
                $property->hero_image_path = $newPath;
                $property->hero_alt = $alt;
                $property->save();

                History::record(
                    $property,
                    'property.hero_replaced',
                    ['hero_image_path' => $previousPath, 'hero_alt' => $previousAlt],
                    ['hero_image_path' => $newPath, 'hero_alt' => $alt],
                );

                if (is_string($previousPath) && $previousPath !== '' && $previousPath !== $newPath) {
                    DB::afterCommit(static function () use ($previousPath): void {
                        Storage::disk('public')->delete($previousPath);
                    });
                }

                return $property;
            });
        } catch (Throwable $exception) {
            Storage::disk('public')->delete($newPath);

            throw $exception;
        }

        EngineFeedVersion::bump();

        return $property;
    }
}
