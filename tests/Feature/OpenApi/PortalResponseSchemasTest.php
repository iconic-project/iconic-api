<?php

declare(strict_types=1);

use Dedoc\Scramble\Http\Middleware\RestrictedDocsAccess;
use Illuminate\Support\Facades\Gate;

/**
 * @param  array<string, mixed>  $spec
 * @return array<string, mixed>
 */
function portalOpenApiSchema(array $spec, string $name): array
{
    $schema = $spec['components']['schemas'][$name] ?? null;

    expect($schema)->toBeArray("schema {$name} is missing");

    if (! isset($schema['properties'])) {
        $arms = $schema['anyOf'] ?? $schema['oneOf'] ?? [];
        $schema = collect(is_array($arms) ? $arms : [])
            ->sortByDesc(fn (mixed $arm): int => is_array($arm) ? count($arm['properties'] ?? []) : 0)
            ->first() ?? $schema;
    }

    expect($schema['properties'] ?? null)->toBeArray("schema {$name} has no properties");
    expect($schema['properties'])->not->toBeEmpty("schema {$name} properties are empty");

    return $schema;
}

/**
 * @param  array<string, mixed>  $node
 * @return array<string, mixed>
 */
function portalOpenApiProperties(array $node): array
{
    if (isset($node['$ref']) && is_string($node['$ref'])) {
        return [];
    }

    $properties = $node['properties'] ?? null;

    expect($properties)->toBeArray();
    expect($properties)->not->toBeEmpty();

    return $properties;
}

/**
 * @param  array<string, mixed>  $node
 */
function portalSchemaRef(array $node, string $name): void
{
    $ref = $node['$ref'] ?? $node['allOf'][0]['$ref'] ?? $node['items']['$ref'] ?? $node['items']['allOf'][0]['$ref'] ?? null;

    if (! is_string($ref)) {
        foreach (['anyOf', 'oneOf'] as $union) {
            foreach ($node[$union] ?? [] as $arm) {
                if (! is_array($arm)) {
                    continue;
                }

                $nested = $arm['$ref'] ?? $arm['allOf'][0]['$ref'] ?? null;

                if (is_string($nested)) {
                    $ref = $nested;
                    break 2;
                }
            }
        }
    }

    expect($ref)->toBeString("{$name} is not a \$ref");
    expect($ref)->toContain($name);
}

test('portal OpenAPI schemas have properties and name their enums', function (): void {
    Gate::define('viewApiDocs', fn (): bool => true);

    $response = $this->withoutMiddleware(RestrictedDocsAccess::class)
        ->getJson('/docs/api.json')
        ->assertOk();

    /** @var array<string, mixed> $spec */
    $spec = $response->json();

    foreach ([
        'PortalMeResource',
        'PortalAgencyMeResource',
        'PortalStayRatesResource',
        'PortalAvailabilityResource',
        'PortalCalendarResource',
        'PortalBookingResource',
        'PortalCommissionResource',
        'PortalRequestResource',
        'PortalRequestCreatedResource',
        'PortalSalesMaterialResource',
        'SalesMaterialResource',
        'PortalActivityResource',
        'AcceptInviteRequest',
        'PortalLoginRequest',
        'PortalForgotPasswordRequest',
        'PortalResetPasswordRequest',
        'StorePortalRequestRequest',
        'SuspendAgencyPortalRequest',
        'ResumeAgencyPortalRequest',
        'StoreSalesMaterialRequest',
        'CreatePortalPaymentLinkRequest',
    ] as $name) {
        portalOpenApiSchema($spec, $name);
    }

    $agencyMe = portalOpenApiSchema($spec, 'PortalAgencyMeResource');
    $agency = portalOpenApiProperties($agencyMe['properties']['agency']);
    portalSchemaRef($agency['status'], 'AgencyStatus');
    expect($agencyMe['properties']['materials_exist']['type'] ?? null)->toBe('boolean');

    $rates = portalOpenApiSchema($spec, 'PortalStayRatesResource');
    expect($rates['properties'])->toHaveKeys(['commission_pct', 'seasons', 'room_types', 'room_rates', 'rate_plans', 'length_of_stay', 'supplements']);

    $ratesResponse = $spec['paths']['/portal/rates']['get']['responses'][200]['content']['application/json']['schema'] ?? [];
    expect($ratesResponse)->toBeArray();
    portalSchemaRef($ratesResponse, 'PortalStayRatesResource');

    $availability = portalOpenApiSchema($spec, 'PortalAvailabilityResource');
    expect($availability['properties']['check_in']['type'] ?? null)->toBe('string');
    expect($availability['properties']['commission_pct']['type'] ?? null)->toBe('integer');
    expect($availability['properties']['room_types']['type'] ?? null)->toBe('array');

    $calendar = portalOpenApiSchema($spec, 'PortalCalendarResource');
    expect($calendar['properties']['from']['type'] ?? null)->toBe('string');
    expect($calendar['properties']['nights']['type'] ?? null)->toBe('array');

    $portalBooking = portalOpenApiSchema($spec, 'PortalBookingResource');
    portalSchemaRef($portalBooking['properties']['status'], 'BookingStatus');
    expect($portalBooking['properties']['id']['type'] ?? null)->toBe('integer');
    expect($portalBooking['properties']['open_payment_kinds']['type'] ?? null)->toBe('array');

    portalSchemaRef(portalOpenApiSchema($spec, 'PortalCommissionResource')['properties']['status'], 'CommissionAccrualStatus');

    $portalRequest = portalOpenApiSchema($spec, 'PortalRequestResource');
    portalSchemaRef($portalRequest['properties']['status'], 'BookingStatus');
    expect($portalRequest['properties']['id']['type'] ?? null)->toBe('integer');
    expect($portalRequest['properties'])->toHaveKey('payment_state');
    expect($portalRequest['properties']['open_payment_kinds']['type'] ?? null)->toBe('array');

    $created = portalOpenApiSchema($spec, 'PortalRequestCreatedResource');
    portalSchemaRef($created['properties']['status'], 'BookingStatus');
    expect($created['properties']['references']['type'] ?? null)->toBe('array');

    portalSchemaRef(portalOpenApiSchema($spec, 'PortalSalesMaterialResource')['properties']['kind'], 'SalesMaterialKind');
    portalSchemaRef(portalOpenApiSchema($spec, 'SalesMaterialResource')['properties']['kind'], 'SalesMaterialKind');
    expect(portalOpenApiSchema($spec, 'StorePortalRequestRequest')['properties'])->toHaveKey('check_in');
    expect(portalOpenApiSchema($spec, 'StorePortalRequestRequest')['properties'])->toHaveKey('rooms');
    portalSchemaRef(portalOpenApiSchema($spec, 'StoreSalesMaterialRequest')['properties']['kind'], 'SalesMaterialKind');

    $activity = portalOpenApiSchema($spec, 'PortalActivityResource');
    expect($activity['properties'])->toHaveKeys(['at', 'event', 'agency_user', 'references', 'material']);

    expect(portalOpenApiSchema($spec, 'SuspendAgencyPortalRequest')['properties'])->toHaveKey('reason');
    expect(portalOpenApiSchema($spec, 'ResumeAgencyPortalRequest')['properties'])->toHaveKey('reason');

    $paymentLink = $spec['paths']['/portal/bookings/{booking}/payment-link']['post']
        ?? $spec['paths']['/api/portal/bookings/{booking}/payment-link']['post']
        ?? null;
    expect($paymentLink)->toBeArray();
    portalSchemaRef(
        $paymentLink['responses']['201']['content']['application/json']['schema'] ?? [],
        'PaymentLinkResource',
    );
    portalSchemaRef(
        portalOpenApiSchema($spec, 'CreatePortalPaymentLinkRequest')['properties']['kind'] ?? [],
        'PaymentKind',
    );

    foreach ([
        'AgencyStatus',
        'BookingStatus',
        'CommissionAccrualStatus',
        'SalesMaterialKind',
    ] as $enum) {
        $schema = $spec['components']['schemas'][$enum] ?? null;
        expect($schema)->toBeArray("schema {$enum} is missing");
        expect($schema['enum'] ?? [])->not->toBeEmpty();
    }
});
