<?php

declare(strict_types=1);

namespace App\Actions\Portal;

use App\Actions\Action;
use App\Actions\Bookings\CreateBookingRequest;
use App\Enums\PreferredChannel;
use App\Enums\ReferenceType;
use App\Models\AgencyUser;
use App\Models\Booking;
use App\Models\Group;
use App\Services\Config\CurrentConfig;
use App\Services\References\ReferenceService;
use App\Support\Bookings\ChannelSeedMap;
use App\Support\Engine\EngineBookingOwner;
use App\Support\History\History;
use App\Support\Portal\PortalRequestWords;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

final class SubmitPortalRequest extends Action
{
    public function __construct(
        private readonly CreateBookingRequest $requests,
        private readonly ReferenceService $references,
        private readonly CurrentConfig $config,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array{bookings: Collection<int, Booking>, message: string}
     */
    public function handle(AgencyUser $actor, array $data): array
    {
        $driver = Auth::getDefaultDriver();
        Auth::shouldUse('web');

        try {
            return $this->transaction(fn (): array => $this->submit($actor, $data));
        } finally {
            Auth::shouldUse($driver);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{bookings: Collection<int, Booking>, message: string}
     */
    private function submit(AgencyUser $actor, array $data): array
    {
        $channels = ChannelSeedMap::fromPrototype('AGENCY');
        /** @var list<array<string, mixed>> $rooms */
        $rooms = array_values(is_array($data['rooms'] ?? null) ? $data['rooms'] : []);
        $owner = EngineBookingOwner::user();
        $label = $actor->name.' ('.$actor->email.')';
        /** @var list<Booking> $created */
        $created = [];

        foreach ($rooms as $room) {
            $created[] = $this->requests->handle([
                'check_in' => $data['check_in'],
                'check_out' => $data['check_out'],
                'rooms' => [$room],
                'client' => $data['client'],
                'notes' => $data['notes'] ?? null,
                'travel_advisor' => true,
                'preferred_channel' => PreferredChannel::Email->value,
                'main_channel' => $channels['main'],
                'channel_of_origin' => $channels['origin'],
                'agency_id' => $actor->agency_id,
                'actor_label' => $label,
                'agency_user_id' => $actor->id,
                'client_of_record' => true,
            ], $owner);
        }

        $bookings = collect($created);
        $first = $bookings->first();

        if (! $first instanceof Booking) {
            throw ValidationException::withMessages([
                'rooms' => ['At least one room is required.'],
            ]);
        }

        $first->loadMissing('contact');

        if ($bookings->count() > 1) {
            $group = Group::query()->create([
                'reference' => $this->references->next(ReferenceType::Group),
                'name' => $first->contact->name.' group',
                'departure_id' => null,
                'coordinator_contact_id' => $first->contact_id,
            ]);

            History::record($group, 'group.created', after: [
                'reference' => $group->reference,
                'name' => $group->name,
                'departure_id' => null,
                'coordinator_contact_id' => $group->coordinator_contact_id,
            ], actorLabel: $label, extraContext: [
                'agency_user_id' => $actor->id,
            ]);

            Booking::query()->whereIn('id', $bookings->pluck('id')->all())->update([
                'group_id' => $group->id,
            ]);
        }

        $actor->loadMissing('agency');

        History::record($actor->agency, 'portal.request_created', after: [
            'references' => $bookings->map(fn (Booking $booking): ?string => $booking->request_reference)->values()->all(),
            'what' => 'Booking requested via the agent portal',
        ], actorLabel: $label, extraContext: [
            'agency_user_id' => $actor->id,
        ]);

        return [
            'bookings' => $bookings,
            'message' => PortalRequestWords::forStatus($first->status, $this->config->businessRules()->sla->responseHours),
        ];
    }
}
