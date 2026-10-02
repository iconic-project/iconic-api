<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Services\Config\ConfigRegistry;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ConfigSeeder extends Seeder
{
    public const APPROVAL_REFERENCE = 'Initial values — Procesos Comerciales v5 and Iconic decisions of 12 Sep 2026';

    public function __construct(private readonly ConfigRegistry $registry) {}

    public function run(): void
    {
        foreach ($this->registry->kinds() as $kind) {
            $modelClass = $kind->modelClass();

            if ($modelClass::query()->exists()) {
                continue;
            }

            $initial = $this->registry->initialDocument($kind);

            if ($initial === null) {
                continue;
            }

            $documentClass = $kind->documentClass();
            $validator = Validator::make($initial, $documentClass::rules());

            if ($validator->fails()) {
                throw ValidationException::withMessages($validator->errors()->toArray());
            }

            $typed = $documentClass::fromArray($initial);

            $modelClass::query()->create([
                'version' => 1,
                'document' => $typed->toArray(),
                'changes' => [],
                'approval_reference' => self::APPROVAL_REFERENCE,
                'published_at' => now(),
                'created_by' => null,
                'updated_by' => null,
            ]);
        }
    }
}
