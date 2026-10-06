<?php

declare(strict_types=1);

namespace App\Actions\Waitlist;

use App\Actions\Action;
use App\Actions\Contacts\ResolveContact;
use App\Actions\Contacts\StitchEngineIdentity;
use App\Enums\WaitlistSource;
use App\Models\RoomType;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Support\History\History;
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
            $type = RoomType::query()->whereKey((int) $data['room_type_id'])->lockForUpdate()->first();

            if (! $type instanceof RoomType || ! $type->waitlist_enabled) {
                throw ValidationException::withMessages([
                    'room_type' => ['The waitlist is off for this room type.'],
                ]);
            }

            $contact = $this->contacts->handle(is_array($data['client'] ?? null) ? $data['client'] : []);

            $this->identity->handle(
                $contact,
                isset($data['session_id']) && is_string($data['session_id']) ? $data['session_id'] : null,
            );

            $source = $data['source'] ?? WaitlistSource::Rms;
            $source = $source instanceof WaitlistSource
                ? $source
                : WaitlistSource::from((string) $source);

            $entry = WaitlistEntry::query()->create([
                'room_type_id' => $type->id,
                'check_in' => $data['check_in'],
                'check_out' => $data['check_out'],
                'contact_id' => $contact->id,
                'adults' => (int) $data['adults'],
                'children' => (int) $data['children'],
                'notes' => isset($data['notes']) && is_string($data['notes']) ? $data['notes'] : null,
                'source' => $source,
            ]);

            History::record($entry, 'waitlist.added', after: [
                'room_type_id' => $entry->room_type_id,
                'check_in' => $entry->check_in->toDateString(),
                'check_out' => $entry->check_out->toDateString(),
                'contact_id' => $entry->contact_id,
                'source' => $entry->source->value,
            ], actor: $actor, system: $actor === null);

            return $entry->refresh()->load(['roomType.property', 'contact']);
        });
    }
}
