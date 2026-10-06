<?php

declare(strict_types=1);

namespace App\Services\Config;

use App\Enums\ConfigKind;
use App\Models\ConfigVersion;
use App\Support\Config\ConfigDocument;
use Illuminate\Support\Facades\Validator;

final class ConfigValidator
{
    /**
     * @param  array<string, mixed>  $document
     */
    public function check(ConfigKind $kind, array $document): ValidationReport
    {
        $documentClass = $kind->documentClass();
        $validator = Validator::make($document, $documentClass::rules());

        if ($validator->fails()) {
            /** @var array<string, list<string>> $errors */
            $errors = $validator->errors()->toArray();

            return new ValidationReport($errors, [], []);
        }

        $typed = $documentClass::fromArray($document);
        $published = $this->publishedDocument($kind);

        return new ValidationReport(
            [],
            $typed->warnings($published),
            $typed->changesAgainst($published),
        );
    }

    /**
     * @param  array<string, mixed>  $document
     */
    public function assertValid(ConfigKind $kind, array $document): void
    {
        $prefixed = [];

        foreach ($kind->documentClass()::rules() as $path => $rule) {
            $prefixed['document.'.$path] = $rule;
        }

        Validator::validate(['document' => $document], $prefixed);
    }

    private function publishedDocument(ConfigKind $kind): ?ConfigDocument
    {
        $modelClass = $kind->modelClass();
        $row = $modelClass::query()->orderByDesc('version')->first();

        if (! $row instanceof ConfigVersion) {
            return null;
        }

        return $row->asDocument();
    }
}
