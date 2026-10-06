<?php

declare(strict_types=1);

namespace App\Services\Config;

use App\Actions\Action;
use App\Enums\ConfigKind;
use App\Events\ConfigPublished;
use App\Exceptions\ConflictException;
use App\Models\ConfigVersion;
use App\Models\User;
use App\Support\Config\Change;
use App\Support\Config\Documents\RatesDocument;
use App\Support\History\History;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class ConfigPublisher extends Action
{
    public function __construct(private DepartureConfigChecks $departureChecks) {}

    /**
     * @param  array<string, mixed>  $document
     */
    public function publish(
        ConfigKind $kind,
        array $document,
        int $baseVersion,
        ?string $approvalReference,
        ?User $actor,
    ): ConfigVersion {
        try {
            return $this->transaction(function () use ($kind, $document, $baseVersion, $approvalReference, $actor): ConfigVersion {
                return $this->publishInsideTransaction(
                    $kind,
                    $document,
                    $baseVersion,
                    $approvalReference,
                    $actor,
                );
            });
        } catch (UniqueConstraintViolationException) {
            throw $this->conflictFromFreshRead($kind);
        }
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function publishInsideTransaction(
        ConfigKind $kind,
        array $document,
        int $baseVersion,
        ?string $approvalReference,
        ?User $actor,
    ): ConfigVersion {
        $modelClass = $kind->modelClass();
        $documentClass = $kind->documentClass();

        $current = $modelClass::query()
            ->orderByDesc('version')
            ->lockForUpdate()
            ->first();

        $currentVersion = $current instanceof ConfigVersion ? $current->version : 0;

        if ($baseVersion !== $currentVersion) {
            throw $this->staleConflict($currentVersion === 0 ? null : $currentVersion);
        }

        $this->validateDocument($document, $documentClass::rules());

        $typed = $documentClass::fromArray($document);
        $published = $current instanceof ConfigVersion
            ? $current->asDocument()
            : null;

        if ($kind === ConfigKind::Rates && $typed instanceof RatesDocument) {
            $yearErrors = $this->departureChecks->rateYearErrors(
                $typed,
                $published instanceof RatesDocument ? $published : null,
            );

            if ($yearErrors !== []) {
                $prefixed = [];

                foreach ($yearErrors as $path => $messages) {
                    $prefixed['document.'.$path] = $messages;
                }

                throw ValidationException::withMessages($prefixed);
            }
        }

        $publishErrors = $typed->publishErrors($published);

        if ($publishErrors !== []) {
            $prefixed = [];

            foreach ($publishErrors as $path => $messages) {
                $prefixed['document.'.$path] = $messages;
            }

            throw ValidationException::withMessages($prefixed);
        }

        $changes = $typed->changesAgainst($published);

        if ($changes === []) {
            throw ValidationException::withMessages([
                'document' => ['Nothing to publish — the document is identical to the published version.'],
            ]);
        }

        $approval = is_string($approvalReference) ? trim($approvalReference) : '';

        if ($typed->requiresApprovalReference($changes) && $approval === '') {
            throw ValidationException::withMessages([
                'approval_reference' => ['The approval reference field is required.'],
            ]);
        }

        $nextVersion = $currentVersion + 1;

        $row = new $modelClass([
            'version' => $nextVersion,
            'document' => $typed->toArray(),
            'changes' => array_map(
                fn (Change $change): array => $change->toArray(),
                $changes,
            ),
            'approval_reference' => $approval === '' ? null : $approval,
            'published_at' => now(),
            'created_by' => $actor?->id,
            'updated_by' => $actor?->id,
        ]);

        $row->save();

        History::record(
            $row,
            $kind->historyPrefix().'.published',
            after: [
                'version' => $nextVersion,
                'changes' => count($changes),
            ],
            reason: $approval === '' ? null : $approval,
            actor: $actor,
            system: $actor === null,
        );

        ConfigPublished::dispatch($kind, $row);

        return $row;
    }

    /**
     * @param  array<string, mixed>  $document
     * @param  array<string, mixed>  $rules
     */
    private function validateDocument(array $document, array $rules): void
    {
        $prefixed = [];

        foreach ($rules as $path => $rule) {
            $prefixed['document.'.$path] = $this->prefixDependentRules($rule);
        }

        Validator::validate(['document' => $document], $prefixed);
    }

    /**
     * Rules are declared against the document. Validation here wraps that
     * document, so a dependent rule has to name the same wrapped path.
     */
    private function prefixDependentRules(mixed $rule): mixed
    {
        if (is_string($rule)) {
            return $this->prefixDependentRule($rule);
        }

        if (! is_array($rule)) {
            return $rule;
        }

        return array_map(
            fn (mixed $item): mixed => is_string($item) ? $this->prefixDependentRule($item) : $item,
            $rule,
        );
    }

    private function prefixDependentRule(string $rule): string
    {
        $marker = 'required_without:';

        if (! str_starts_with($rule, $marker)) {
            return $rule;
        }

        $fields = array_map(
            fn (string $field): string => str_starts_with($field, 'document.') ? $field : 'document.'.$field,
            explode(',', substr($rule, strlen($marker))),
        );

        return $marker.implode(',', $fields);
    }

    private function staleConflict(?int $version): ConflictException
    {
        if ($version === null) {
            return new ConflictException(
                'Someone published a newer version while you were editing. Reload to see it; your changes were not saved.',
            );
        }

        return new ConflictException(
            "Someone published a newer version (v{$version}) while you were editing. Reload to see it; your changes were not saved.",
        );
    }

    private function conflictFromFreshRead(ConfigKind $kind): ConflictException
    {
        $winning = $kind->modelClass()::query()->orderByDesc('version')->first();

        return $this->staleConflict($winning instanceof ConfigVersion ? $winning->version : null);
    }
}
