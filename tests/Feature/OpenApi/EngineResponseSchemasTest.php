<?php

declare(strict_types=1);

use Dedoc\Scramble\Http\Middleware\RestrictedDocsAccess;
use Illuminate\Support\Facades\Gate;

/**
 * @param  array<string, mixed>  $spec
 * @return array<string, mixed>
 */
function engineOpenApiSchema(array $spec, string $name): array
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
 */
function engineSchemaRef(array $node, string $name): void
{
    $ref = $node['$ref'] ?? $node['allOf'][0]['$ref'] ?? $node['items']['$ref'] ?? $node['items']['allOf'][0]['$ref'] ?? null;

    expect($ref)->toBeString("{$name} is not a \$ref");
    expect($ref)->toContain($name);
}

test('engine OpenAPI schemas have properties', function (): void {
    Gate::define('viewApiDocs', fn (): bool => true);

    $response = $this->withoutMiddleware(RestrictedDocsAccess::class)
        ->getJson('/docs/api.json')
        ->assertOk();

    /** @var array<string, mixed> $spec */
    $spec = $response->json();

    foreach ([
        'PromoCheckResource',
        'StayRoomsQuoteResource',
        'CheckoutCreatedResource',
        'CheckoutExtendedResource',
        'CheckoutSubmittedResource',
        'CheckoutStatusResource',
        'EngineCountryResource',
        'EngineWaitlistResource',
        'CompleteReservationResource',
        'CompleteBookingResource',
        'CompleteGuestResource',
        'EngineEventsAcceptedResource',
        'QuestionnaireResource',
        'SurveyResource',
        'MarketingLeadResource',
        'UnsubscribeResource',
    ] as $name) {
        engineOpenApiSchema($spec, $name);
    }

    foreach (['EnginePropertyResource', 'EngineAvailabilityResource', 'EngineCalendarResource', 'EngineStayQuoteResource'] as $passthrough) {
        expect($spec['components']['schemas'][$passthrough] ?? null)->toBeArray("schema {$passthrough} is missing");
    }

    foreach (array_keys($spec['components']['schemas'] ?? []) as $schemaName) {
        expect($schemaName)->not->toMatch('/Yacht|Cabin|Itinerary|Voyage|Departure|FeedResource/');
    }

    foreach (['/engine/feed', '/api/engine/feed', '/engine/departures', '/engine/itineraries', '/engine/yachts'] as $gone) {
        expect($spec['paths'])->not->toHaveKey($gone);
    }

    $propertyPath = $spec['paths']['/engine/property']['get']
        ?? $spec['paths']['/api/engine/property']['get']
        ?? null;
    expect($propertyPath)->toBeArray();
    engineSchemaRef(
        $propertyPath['responses']['200']['content']['application/json']['schema'] ?? [],
        'EnginePropertyResource',
    );

    $availabilityPath = $spec['paths']['/engine/availability']['get']
        ?? $spec['paths']['/api/engine/availability']['get']
        ?? null;
    expect($availabilityPath)->toBeArray();
    engineSchemaRef(
        $availabilityPath['responses']['200']['content']['application/json']['schema'] ?? [],
        'EngineAvailabilityResource',
    );

    $complete = engineOpenApiSchema($spec, 'CompleteReservationResource');
    expect($complete['properties'])->toHaveKeys([
        'bookings',
        'billing',
        'declarations',
        'amount_due',
        'amount_due_kind',
        'amount_due_label',
        'can_pay',
        'pay_url',
        'countries',
    ]);
    engineSchemaRef($complete['properties']['bookings'] ?? [], 'CompleteBookingResource');

    $guest = engineOpenApiSchema($spec, 'CompleteGuestResource');
    expect($guest['properties'])->toHaveKeys(['passport_on_file']);
    expect($guest['properties'])->not->toHaveKey('passport_no');
    expect($guest['properties']['passport_on_file']['type'] ?? null)->toBe('boolean');

    $created = engineOpenApiSchema($spec, 'CheckoutCreatedResource');
    expect($created['properties'])->toHaveKey('quote');

    $status = engineOpenApiSchema($spec, 'CheckoutStatusResource');
    expect($status['properties'])->toHaveKeys([
        'status',
        'path',
        'email',
        'bookings',
        'stripe_checkout_session_id',
        'stripe_expires_at',
    ]);

    $countries = $spec['paths']['/engine/countries']['get']
        ?? $spec['paths']['/api/engine/countries']['get']
        ?? null;
    expect($countries)->toBeArray();
    engineSchemaRef(
        $countries['responses']['200']['content']['application/json']['schema'] ?? [],
        'EngineCountryResource',
    );

    $submit = $spec['paths']['/engine/checkout/{token}/submit']['post']
        ?? $spec['paths']['/api/engine/checkout/{token}/submit']['post']
        ?? null;
    expect($submit)->toBeArray();
    $conflict = $submit['responses']['409'] ?? null;
    expect($conflict)->toBeArray();
    $conflictRef = $conflict['$ref'] ?? null;
    expect($conflictRef)->toBeString();
    expect($conflictRef)->toContain('PriceChangedException');

    $priceChanged = $spec['components']['responses']['PriceChangedException'] ?? null;
    expect($priceChanged)->toBeArray();
    $quote = $priceChanged['content']['application/json']['schema']['properties']['quote'] ?? [];
    $quoteRef = $quote['$ref'] ?? $quote['allOf'][0]['$ref'] ?? null;
    expect($quoteRef)->toBeString();
    expect($quoteRef)->toContain('StayRoomsQuoteResource');

    $storeEvents = $spec['components']['schemas']['StoreEngineEventsRequest'] ?? null;
    expect($storeEvents)->toBeArray();
    expect($storeEvents['properties'] ?? null)->toHaveKeys(['session_id', 'events']);
    $eventName = $storeEvents['properties']['events']['items']['properties']['name']['enum'] ?? [];
    expect($eventName)->not->toBeEmpty();
    expect($eventName)->not->toContain('identity.stitched');

    $questionnaire = $spec['components']['schemas']['UpdateQuestionnaireRequest'] ?? null;
    expect($questionnaire)->toBeArray();
    expect($questionnaire['properties'] ?? null)->toHaveKey('answers');

    $survey = $spec['components']['schemas']['StoreSurveyResponseRequest'] ?? null;
    expect($survey)->toBeArray();
    expect($survey['properties'] ?? null)->toHaveKeys(['score', 'rec', 'why', 'best', 'better', 'crew']);
    expect($survey['properties'] ?? null)->not->toHaveKey('recommend');

    $surveyPage = engineOpenApiSchema($spec, 'SurveyResource');
    expect($surveyPage['properties'] ?? null)->toHaveKey('questions');
    $surveyQuestion = $surveyPage['properties']['questions']['items']['properties']
        ?? $surveyPage['properties']['questions']['items']['allOf'][0]['properties']
        ?? [];
    expect($surveyQuestion)->toHaveKeys(['key', 'label', 'type', 'min', 'max']);

    $lead = engineOpenApiSchema($spec, 'MarketingLeadResource');
    expect($lead['properties'])->toHaveKey('accepted');
    expect(array_keys($lead['properties']))->toBe(['accepted']);

    $unsubscribe = engineOpenApiSchema($spec, 'UnsubscribeResource');
    expect($unsubscribe['properties'])->toHaveKeys(['valid', 'already_unsubscribed']);
    expect(array_keys($unsubscribe['properties']))->toBe(['valid', 'already_unsubscribed']);

    $leadPath = $spec['paths']['/engine/marketing-leads']['post']
        ?? $spec['paths']['/api/engine/marketing-leads']['post']
        ?? null;
    expect($leadPath)->toBeArray();
    engineSchemaRef(
        $leadPath['responses']['200']['content']['application/json']['schema'] ?? [],
        'MarketingLeadResource',
    );

    $unsubscribePath = $spec['paths']['/engine/unsubscribe/{token}']['get']
        ?? $spec['paths']['/api/engine/unsubscribe/{token}']['get']
        ?? null;
    expect($unsubscribePath)->toBeArray();
    engineSchemaRef(
        $unsubscribePath['responses']['200']['content']['application/json']['schema'] ?? [],
        'UnsubscribeResource',
    );

    $unsubscribePost = $spec['paths']['/engine/unsubscribe/{token}']['post']
        ?? $spec['paths']['/api/engine/unsubscribe/{token}']['post']
        ?? null;
    expect($unsubscribePost)->toBeArray();
    engineSchemaRef(
        $unsubscribePost['responses']['200']['content']['application/json']['schema'] ?? [],
        'UnsubscribeResource',
    );
    expect($unsubscribePost['requestBody'] ?? null)->toBeNull();

    expect($spec['components']['schemas']['StoreMarketingLeadRequest']['properties'] ?? null)->toHaveKeys([
        'email',
        'first_name',
        'consent',
        'version',
        'session_id',
    ]);
});
