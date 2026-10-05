<?php

declare(strict_types=1);

namespace App\Actions\Agencies;

use App\Actions\Action;
use App\Actions\Contacts\ResolveContact;
use App\Enums\AgencyStatus;
use App\Enums\AgencyUserStatus;
use App\Enums\ContactType;
use App\Enums\ReferenceType;
use App\Models\Agency;
use App\Models\AgencyUser;
use App\Models\User;
use App\Services\Config\CurrentConfig;
use App\Services\References\ReferenceService;
use App\Support\History\History;

final class RegisterAgency extends Action
{
    public function __construct(
        private readonly ReferenceService $references,
        private readonly CurrentConfig $config,
        private readonly ResolveContact $contacts,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, User $actor): Agency
    {
        return $this->transaction(function () use ($data, $actor): Agency {
            $rules = $this->config->businessRules();
            $pct = isset($data['commission_pct']) && $data['commission_pct'] !== ''
                ? (int) $data['commission_pct']
                : $rules->commission->defaultPct;
            $terms = isset($data['payment_terms']) && is_string($data['payment_terms']) && trim($data['payment_terms']) !== ''
                ? trim($data['payment_terms'])
                : $rules->commission->payableDaysAfterCheckOut.' days after check-out · wire';

            $agency = Agency::query()->create([
                'reference' => $this->references->next(ReferenceType::Agency),
                'name' => $data['name'],
                'contact' => $data['contact'] ?? '',
                'email' => $data['email'],
                'country' => $data['country'] ?? null,
                'network' => $data['network'] ?? null,
                'commission_pct' => $pct,
                'payment_terms' => $terms,
                'status' => AgencyStatus::Pending,
                'requested_at' => now(),
            ]);

            $contactName = ($data['contact'] ?? '') !== '' ? (string) $data['contact'] : (string) $data['name'];

            AgencyUser::query()->create([
                'agency_id' => $agency->id,
                'name' => $contactName,
                'email' => $data['email'],
                'status' => AgencyUserStatus::InviteOnApproval,
            ]);

            $this->contacts->handle([
                'name' => $contactName,
                'email' => $data['email'],
                'country' => isset($data['country']) ? (string) $data['country'] : null,
                'type' => ContactType::TravelAgent,
            ]);

            History::record($agency, 'agency.registered', after: [
                'reference' => $agency->reference,
                'name' => $agency->name,
                'email' => $agency->email,
                'commission_pct' => $agency->commission_pct,
                'status' => $agency->status->value,
                'user' => [
                    'name' => $contactName,
                    'email' => $data['email'],
                    'status' => AgencyUserStatus::InviteOnApproval->value,
                ],
            ], actor: $actor);

            return $agency->fresh(['users']) ?? $agency;
        });
    }
}
