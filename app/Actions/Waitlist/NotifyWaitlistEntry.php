<?php

declare(strict_types=1);

namespace App\Actions\Waitlist;

use App\Actions\Action;
use App\Enums\PreferredChannel;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Support\History\History;
use Illuminate\Validation\ValidationException;

final class NotifyWaitlistEntry extends Action
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(WaitlistEntry $entry, array $data, User $actor): WaitlistEntry
    {
        return $this->transaction(function () use ($entry, $data, $actor): WaitlistEntry {
            $entry->refresh();

            if ($entry->removed_at !== null) {
                throw ValidationException::withMessages([
                    'entry' => ['This waitlist entry has been removed.'],
                ]);
            }

            $channel = $data['channel'] instanceof PreferredChannel
                ? $data['channel']
                : PreferredChannel::from((string) $data['channel']);

            $entry->notified_at = now();
            $entry->notified_by = $actor->id;
            $entry->notified_channel = $channel;
            $entry->save();

            History::record($entry, 'waitlist.notified', after: [
                'channel' => $channel->value,
                'notified_by' => $actor->id,
            ], actor: $actor);

            return $entry->refresh()->load(['departure.property', 'contact', 'notifiedBy']);
        });
    }
}
