<?php

declare(strict_types=1);

namespace App\Actions\Waitlist;

use App\Actions\Action;
use App\Actions\Contacts\ResolveContact;
use App\Actions\Contacts\StitchEngineIdentity;
use App\Enums\CabinCategory;
use App\Enums\WaitlistSource;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Support\History\History;
use App\Support\Inventory\DepartureLocks;
use Illuminate\Validation\ValidationException;

final class AddWaitlistEntry extends Action
{
    public function __construct(
        private ResolveContact $contacts,
        private StitchEngineIdentity $identity,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, ?User $actor = null): WaitlistEntry
    {
        return $this->transaction(function () use ($data, $actor): WaitlistEntry {
            $departure = DepartureLocks::lock((int) $data['departure_id']);

            if (! $departure->waitlist_enabled) {
                throw ValidationException::withMessages([
                    'departure_id' => ['The waitlist is off for this departure.'],
                ]);
            }

            $contact = $this->contacts->handle(is_array($data['client'] ?? null) ? $data['client'] : []);

            $this->identity->handle(
                $contact,
                isset($data['session_id']) && is_string($data['session_id']) ? $data['session_id'] : null,
            );

            $category = $data['cabin_category'] instanceof CabinCategory
                ? $data['cabin_category']
                : CabinCategory::from((string) $data['cabin_category']);

            $source = $data['source'] ?? WaitlistSource::Rms;
            $source = $source instanceof WaitlistSource
                ? $source
                : WaitlistSource::from((string) $source);

            $entry = WaitlistEntry::query()->create([
                'departure_id' => $departure->id,
                'cabin_category' => $category,
                'contact_id' => $contact->id,
                'adults' => (int) $data['adults'],
                'children' => (int) $data['children'],
                'notes' => isset($data['notes']) && is_string($data['notes']) ? $data['notes'] : null,
                'source' => $source,
            ]);

            History::record($entry, 'waitlist.added', after: [
                'departure_id' => $entry->departure_id,
                'cabin_category' => $entry->cabin_category->value,
                'contact_id' => $entry->contact_id,
                'source' => $entry->source->value,
            ], actor: $actor, system: $actor === null);

            return $entry->refresh()->load(['departure.property', 'contact']);
        });
    }
}
