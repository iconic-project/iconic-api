<?php

declare(strict_types=1);

namespace App\Actions\Properties;

use App\Actions\Action;
use App\Models\Property;
use App\Services\Engine\EngineFeedVersion;
use App\Support\History\History;

final class UpdatePropertyContent extends Action
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(Property $property, array $data): Property
    {
        unset($data['code'], $data['hero_image_path']);

        $changed = false;

        $property = $this->transaction(function () use ($property, $data, &$changed): Property {
            $property->fill($data);

            if (! $property->isDirty()) {
                return $property;
            }

            $property->save();
            $changed = true;

            [$before, $after] = History::diff($property);

            if ($before !== []) {
                History::record($property, 'property.updated', $before, $after);
            }

            return $property;
        });

        if ($changed) {
            EngineFeedVersion::bump();
        }

        return $property;
    }
}
