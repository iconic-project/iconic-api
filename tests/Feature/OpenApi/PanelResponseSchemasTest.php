<?php

declare(strict_types=1);

use Dedoc\Scramble\Http\Middleware\RestrictedDocsAccess;
use Illuminate\Support\Facades\Gate;

/**
 * @param  array<string, mixed>  $spec
 * @return array<string, mixed>
 */
function openApiSchema(array $spec, string $name): array
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
function openApiProperties(array $node): array
{
    if (isset($node['$ref']) && is_string($node['$ref'])) {
        return [];
    }

    $properties = $node['properties'] ?? null;

    expect($properties)->toBeArray();
    expect($properties)->not->toBeEmpty();

    return $properties;
}

test('panel-read OpenAPI schemas have properties', function (): void {
    Gate::define('viewApiDocs', fn (): bool => true);

    $response = $this->withoutMiddleware(RestrictedDocsAccess::class)
        ->getJson('/docs/api.json')
        ->assertOk();

    /** @var array<string, mixed> $spec */
    $spec = $response->json();

    foreach ([
        'DepartureResource',
        'InternalBlockResource',
        'CalendarGridResource',
        'GenerateSeasonResource',
        'DepartureMutationResource',
        'BookingResource',
        'ReservationQuoteResource',
        'ReservationCreatedResource',
        'BookingFormOptionsResource',
        'GroupResource',
        'ContactResource',
        'MovePreviewResource',
        'BookingAuditResource',
        'BookingRequestResource',
        'HoldResource',
        'WaitlistEntryResource',
        'CharterEnquiryResource',
        'PaymentResource',
        'PaymentOptionsResource',
        'RecordedPaymentResource',
        'PaymentLinkResource',
        'ReconciliationResource',
        'AgencyResource',
        'OfferResource',
        'CommissionResource',
        'RefundRequestResource',
        'ContactInResource',
        'ContactsInNationalitiesResource',
        'GuestResource',
        'BookingConsentResource',
        'ConsentResource',
        'BookingExtraResource',
        'DocumentResource',
        'DeliveryResource',
        'DocumentPlanRowResource',
        'CountryResource',
        'MaskedNoteResource',
        'CompleteLinkResource',
    ] as $name) {
        openApiSchema($spec, $name);
    }

    $rmsContact = openApiSchema($spec, 'ContactResource');
    expect($rmsContact['properties'])->toHaveKeys(['id', 'name', 'email', 'phone', 'country', 'preferred_channel']);
    expect($rmsContact['properties'])->not->toHaveKey('lifetime_value');

    $departure = openApiSchema($spec, 'DepartureResource');
    $availability = openApiProperties($departure['properties']['availability'] ?? []);
    $cabins = $availability['cabins']['items'] ?? $availability['cabins'] ?? null;
    expect($cabins)->toBeArray();

    $cabinItem = $cabins['items'] ?? $cabins;
    $claim = $cabinItem['properties']['claim'] ?? $cabinItem['anyOf'][0]['properties']['claim'] ?? null;

    if (isset($cabinItem['properties']['claim'])) {
        $claim = $cabinItem['properties']['claim'];
    } elseif (isset($cabinItem['anyOf'])) {
        foreach ($cabinItem['anyOf'] as $option) {
            if (isset($option['properties']['claim'])) {
                $claim = $option['properties']['claim'];
                break;
            }
        }
    }

    expect($claim)->toBeArray();

    $claimProps = $claim['properties'] ?? $claim['anyOf'][0]['properties'] ?? [];
    $holder = $claimProps['holder']['properties'] ?? [];
    expect($holder)->toHaveKey('detail');

    $index = $spec['paths']['/rms/departures']['get']
        ?? $spec['paths']['/api/rms/departures']['get']
        ?? null;
    expect($index)->toBeArray();

    $indexSchema = $index['responses']['200']['content']['application/json']['schema'] ?? [];
    $kpis = $indexSchema['properties']['meta']['properties']['kpis']['properties']
        ?? $indexSchema['properties']['meta']['properties']['kpis']
        ?? null;
    expect($kpis)->toBeArray();
    $kpiFields = $kpis['properties'] ?? $kpis;
    expect($kpiFields)->toHaveKeys([
        'on_sale_on_engine',
        'cabins_bookable',
        'showing_only_n_left',
        'full',
    ]);

    $unavailable = $spec['components']['responses']['CabinUnavailableException'] ?? null;
    expect($unavailable)->toBeArray();
    $body = $unavailable['content']['application/json']['schema']['properties'] ?? [];
    expect($body)->toHaveKey('unavailable');
    $item = $body['unavailable']['items']['properties'] ?? [];
    expect($item)->toHaveKeys(['cabin', 'held_by']);

    $blockStore = $spec['paths']['/rms/blocks']['post']
        ?? $spec['paths']['/api/rms/blocks']['post']
        ?? null;
    expect($blockStore)->toBeArray();
    $conflict = $blockStore['responses']['409'] ?? null;
    expect($conflict)->toBeArray();
    $conflictRef = $conflict['$ref'] ?? $conflict['content']['application/json']['schema']['$ref'] ?? null;
    expect($conflictRef)->toBeString();
    expect($conflictRef)->toContain('CabinUnavailableException');

    $booking = openApiSchema($spec, 'BookingResource');
    expect($booking['properties'])->toHaveKeys([
        'allowed_transitions',
        'request',
        'paid',
        'pledged',
        'payments_count',
        'overdue',
        'overdue_days',
        'wire_window_ends_at',
        'agency',
        'commission_pct',
        'commission_amount',
        'commission_approved',
        'commission_cap_pct',
        'refund',
        'payment_links',
        'guests_summary',
        'extras_total',
        'fees_collected_total',
        'png_collected',
        'tct_collected',
        'png_pending_count',
        'charges_total',
        'cruise_outstanding',
        'extras_due_at',
        'billing_name',
        'billing_address',
        'billing_email',
        'billing_phone',
        'promo_code',
        'online_deposit',
        'sold_on',
        'utm_first',
        'utm_last',
        'stay',
        'room',
        'room_type',
        'rate_plan',
        'night_lines',
        'tax_lines',
        'times',
    ]);
    $stay = openApiProperties($booking['properties']['stay'] ?? []);
    expect($stay)->toHaveKeys(['check_in', 'check_out', 'nights']);
    $times = openApiProperties($booking['properties']['times'] ?? []);
    expect($times)->toHaveKeys(['expected_arrival_time', 'checked_in_at', 'checked_out_at', 'no_show_at']);
    $guestsSummary = openApiProperties($booking['properties']['guests_summary'] ?? []);
    expect($guestsSummary)->toHaveKeys(['complete', 'total']);

    $payment = openApiSchema($spec, 'PaymentResource');
    expect($payment['properties'])->toHaveKeys([
        'reference',
        'date',
        'kind',
        'method',
        'amount',
        'status',
        'gateway_id',
        'recorded_by',
        'can_mark_wire',
        'wire_window_ends_at',
        'booking',
    ]);
    $paymentBooking = $payment['properties']['booking']['properties'] ?? [];
    expect($paymentBooking)->toHaveKeys(['id', 'display_reference', 'client']);

    $ledger = $spec['paths']['/rms/payments']['get']
        ?? $spec['paths']['/api/rms/payments']['get']
        ?? null;
    expect($ledger)->toBeArray();
    $ledgerSchema = $ledger['responses']['200']['content']['application/json']['schema'] ?? [];
    $paymentKpis = $ledgerSchema['properties']['meta']['properties']['kpis']['properties']
        ?? $ledgerSchema['properties']['meta']['properties']['kpis']
        ?? null;
    expect($paymentKpis)->toBeArray();
    $paymentKpiFields = $paymentKpis['properties'] ?? $paymentKpis;
    expect($paymentKpiFields)->toHaveKeys([
        'collected',
        'deposits',
        'pending',
        'pending_count',
        'overdue_count',
        'overdue_amount',
        'commission_accrued',
        'cabin_deposit_pct',
        'charter_deposit_pct',
        'cabin_balance_days',
        'commission_payable_days',
        'commission_cap_pct',
        'wire_window_hours',
    ]);

    $agency = openApiSchema($spec, 'AgencyResource');
    expect($agency['properties'])->toHaveKeys([
        'reference',
        'commission_pct',
        'status',
        'sla_business_days_elapsed',
        'sla_breached',
        'users',
        'bookings_count',
        'revenue',
        'commission_accrued',
        'held_bookings_count',
    ]);

    $agencies = $spec['paths']['/rms/agencies']['get']
        ?? $spec['paths']['/api/rms/agencies']['get']
        ?? null;
    expect($agencies)->toBeArray();
    $agenciesSchema = $agencies['responses']['200']['content']['application/json']['schema'] ?? [];
    $agencyKpis = $agenciesSchema['properties']['meta']['properties']['kpis']['properties']
        ?? $agenciesSchema['properties']['meta']['properties']['kpis']
        ?? null;
    expect($agencyKpis)->toBeArray();
    $agencyKpiFields = $agencyKpis['properties'] ?? $agencyKpis;
    expect($agencyKpiFields)->toHaveKeys([
        'approved_agencies',
        'registrations_to_review',
        'agency_revenue',
        'commission_accrued',
        'commission_payable',
        'commission_paid',
        'agency_approval_business_days',
        'commission_payable_days',
        'commission_cap_pct',
        'commission_default_pct',
    ]);

    $refunds = $spec['paths']['/rms/refunds']['get']
        ?? $spec['paths']['/api/rms/refunds']['get']
        ?? null;
    expect($refunds)->toBeArray();
    $refundsSchema = $refunds['responses']['200']['content']['application/json']['schema'] ?? [];
    $refundRules = $refundsSchema['properties']['meta']['properties']['rules']['properties']
        ?? $refundsSchema['properties']['meta']['properties']['rules']
        ?? null;
    expect($refundRules)->toBeArray();
    $refundRuleFields = $refundRules['properties'] ?? $refundRules;
    expect($refundRuleFields)->toHaveKeys(['refund_business_days']);

    $refund = openApiSchema($spec, 'RefundRequestResource');
    expect($refund['properties'])->toHaveKeys([
        'band_label',
        'refund_due',
        'penalty_amount',
        'can_approve',
        'can_execute',
    ]);

    $reconciliation = openApiSchema($spec, 'ReconciliationResource');
    expect($reconciliation['properties'])->toHaveKeys([
        'matched',
        'in_gateway_not_rms',
        'to_review',
        'counts',
        'meta',
        'note',
    ]);
    $reconCounts = $reconciliation['properties']['counts']['properties'] ?? [];
    expect($reconCounts)->toHaveKeys([
        'matched',
        'in_gateway_not_rms',
        'to_review',
        'gateway',
        'discrepancies',
    ]);
    $matchedItem = $reconciliation['properties']['matched']['items']['properties'] ?? [];
    expect($matchedItem)->toHaveKeys(['gateway', 'stripe_id', 'amount', 'date']);
    $bookingDeparture = $booking['properties']['departure']['properties'] ?? [];
    expect($bookingDeparture)->toHaveKeys(['itinerary_name', 'return_date', 'embark', 'festive']);

    $owners = $spec['paths']['/rms/bookings/owners']['get']
        ?? $spec['paths']['/api/rms/bookings/owners']['get']
        ?? null;
    expect($owners)->toBeArray();

    $groups = $spec['paths']['/rms/groups']['get']
        ?? $spec['paths']['/api/rms/groups']['get']
        ?? null;
    expect($groups)->toBeArray();
    $groupParams = collect($groups['parameters'] ?? [])
        ->mapWithKeys(fn (array $parameter): array => [($parameter['name'] ?? '') => $parameter]);
    expect($groupParams->keys()->all())->toContain('from', 'to');

    $transitionItems = $booking['properties']['allowed_transitions']['items'] ?? [];
    $transitionItemProps = is_array($transitionItems)
        ? ($transitionItems['properties'] ?? $transitionItems['anyOf'][0]['properties'] ?? [])
        : [];
    if ($transitionItemProps !== []) {
        expect($transitionItemProps)->toHaveKeys(['to', 'reason_required']);
    } else {
        expect($transitionItems)->toBeArray();
    }

    $quote = openApiSchema($spec, 'ReservationQuoteResource');
    expect($quote['properties'])->toHaveKey('terms');
    $terms = openApiProperties($quote['properties']['terms'] ?? []);
    expect($terms)->toHaveKeys(['balance_days', 'charter']);

    $formOptions = $spec['paths']['/rms/bookings/form-options']['get']
        ?? $spec['paths']['/api/rms/bookings/form-options']['get']
        ?? null;
    expect($formOptions)->toBeArray();
    $formOptionsSchema = openApiSchema($spec, 'BookingFormOptionsResource');
    expect($formOptionsSchema['properties'])->toHaveKeys(['commission', 'payments']);
    $formPayments = openApiProperties($formOptionsSchema['properties']['payments'] ?? []);
    expect($formPayments)->toHaveKey('wire_window_hours');
    $formCommission = openApiProperties($formOptionsSchema['properties']['commission'] ?? []);
    expect($formCommission)->toHaveKeys(['cap_pct', 'default_pct']);

    $paymentOptions = openApiSchema($spec, 'PaymentOptionsResource');
    expect($paymentOptions['properties'])->toHaveKeys(['kinds', 'methods']);
    $kindItem = $paymentOptions['properties']['kinds']['items']['properties']
        ?? $paymentOptions['properties']['kinds']['items']
        ?? [];
    $kindItemProps = $kindItem['properties'] ?? $kindItem;
    expect($kindItemProps)->toHaveKeys(['value', 'label', 'recordable']);
    $paymentOptionsPath = $spec['paths']['/rms/payments/options']['get']
        ?? $spec['paths']['/api/rms/payments/options']['get']
        ?? null;
    expect($paymentOptionsPath)->toBeArray();

    $created = openApiSchema($spec, 'ReservationCreatedResource');
    expect($created['properties'])->toHaveKey('bookings');

    $store = $spec['paths']['/rms/bookings']['post']
        ?? $spec['paths']['/api/rms/bookings']['post']
        ?? null;
    expect($store)->toBeArray();
    $storeBookings = $store['responses']['201']['content']['application/json']['schema']['properties']['bookings']['items']
        ?? $created['properties']['bookings']['items']
        ?? null;
    expect($storeBookings)->toBeArray();
    $storeBookingRef = $storeBookings['$ref'] ?? null;
    $storeBookingProps = $storeBookings['properties'] ?? [];
    if (is_string($storeBookingRef)) {
        expect($storeBookingRef)->toContain('BookingResource');
    } else {
        expect($storeBookingProps)->toHaveKeys(['id', 'status', 'type']);
    }

    $requests = $spec['paths']['/rms/requests']['get']
        ?? $spec['paths']['/api/rms/requests']['get']
        ?? null;
    expect($requests)->toBeArray();
    $requestSchema = $requests['responses']['200']['content']['application/json']['schema'] ?? [];
    $rules = $requestSchema['properties']['meta']['properties']['rules']['properties']
        ?? $requestSchema['properties']['meta']['properties']['rules']
        ?? null;
    expect($rules)->toBeArray();
    $ruleFields = $rules['properties'] ?? $rules;
    expect($ruleFields)->toHaveKeys([
        'near_term_business_hours',
        'long_lead_business_days',
        'near_term_max_days',
        'response_hours',
        'business_day_minutes',
        'cabin_deposit_pct',
    ]);

    $hold = openApiSchema($spec, 'HoldResource');
    expect($hold['properties'])->toHaveKeys(['booking_id', 'departure', 'remaining_business_minutes']);
    $holdDeparture = $hold['properties']['departure'] ?? [];
    expect($holdDeparture['type'] ?? $holdDeparture['properties'] ?? null)->not->toBe('string');
    expect($holdDeparture['properties'] ?? [])->toHaveKeys(['date', 'property']);
    expect($hold['properties']['remaining_business_minutes']['type'] ?? null)->toBe('integer');
    $holdRequest = $booking['properties']['request']['properties']['hold']['properties']
        ?? $booking['properties']['request']['properties']['hold']
        ?? [];
    $holdRequestProps = $holdRequest['properties'] ?? $holdRequest;
    expect($holdRequestProps)->toHaveKey('remaining_business_minutes');

    $holds = $spec['paths']['/rms/holds']['get']
        ?? $spec['paths']['/api/rms/holds']['get']
        ?? null;
    expect($holds)->toBeArray();
    $holdParams = collect($holds['parameters'] ?? [])
        ->mapWithKeys(fn (array $parameter): array => [($parameter['name'] ?? '') => $parameter]);
    expect($holdParams->keys()->all())->toContain('from', 'to');
    $holdsSchema = $holds['responses']['200']['content']['application/json']['schema'] ?? [];
    $holdsRules = $holdsSchema['properties']['meta']['properties']['rules']['properties']
        ?? $holdsSchema['properties']['meta']['properties']['rules']
        ?? null;
    expect($holdsRules)->toBeArray();
    $holdsRuleFields = $holdsRules['properties'] ?? $holdsRules;
    expect($holdsRuleFields)->toHaveKey('business_day_minutes');

    foreach ([
        'BookingStatus',
        'BookingType',
        'BookingSegment',
        'MainChannel',
        'ChannelOfOrigin',
        'PaymentKind',
        'PaymentMethod',
        'PaymentStatus',
        'AgencyStatus',
        'CommissionAccrualStatus',
        'RefundRequestStatus',
        'ConsentDocument',
        'DocumentKind',
        'DocumentPlanKind',
        'DocumentPlanStatus',
        'OfferType',
        'OfferChannel',
        'CharterEnquiryStatus',
    ] as $enum) {
        $schema = $spec['components']['schemas'][$enum] ?? null;
        expect($schema)->toBeArray("schema {$enum} is missing");
        expect($schema['enum'] ?? $schema['oneOf'] ?? $schema['anyOf'] ?? null)
            ->not->toBeNull("schema {$enum} is not an enum");
    }

    $transition = $spec['paths']['/rms/bookings/{booking}/transition']['post']
        ?? $spec['paths']['/api/rms/bookings/{booking}/transition']['post']
        ?? null;
    expect($transition)->toBeArray();
    expect($transition['responses']['409'] ?? null)->toBeArray();

    $move = $spec['paths']['/rms/bookings/{booking}/move']['post']
        ?? $spec['paths']['/api/rms/bookings/{booking}/move']['post']
        ?? null;
    expect($move)->toBeArray();
    expect($move['responses']['409'] ?? null)->toBeArray();

    $guest = openApiSchema($spec, 'GuestResource');
    expect($guest['properties'])->toHaveKeys([
        'passport_no',
        'medical_note',
        'dietary_note',
        'accessibility_note',
        'png_category',
        'png_category_label',
        'png_fee',
        'complete',
        'guardian',
        'is_minor_now',
        'age_at_departure',
    ]);
    expect($guest['properties']['passport_no']['type'] ?? null)->toBe(['string', 'null']);
    $noteSchema = openApiSchema($spec, 'MaskedNoteResource');
    expect($noteSchema['properties'])->toHaveKeys(['value', 'on_file']);
    expect($noteSchema['properties']['value']['type'] ?? null)->toBe(['string', 'null']);
    expect($noteSchema['properties']['on_file']['type'] ?? null)->toBe('boolean');
    expect(openApiSchema($spec, 'BookingConsentResource')['properties']['outdated']['type'] ?? null)->toBe('boolean');
    expect(openApiSchema($spec, 'ContactInResource')['properties']['can_act']['type'] ?? null)->toBe('boolean');
    foreach (['medical_note', 'dietary_note', 'accessibility_note'] as $note) {
        $ref = $guest['properties'][$note]['$ref'] ?? $guest['properties'][$note]['allOf'][0]['$ref'] ?? null;
        if (is_string($ref)) {
            expect($ref)->toContain('MaskedNoteResource');
        } else {
            $noteProps = openApiProperties($guest['properties'][$note] ?? []);
            expect($noteProps)->toHaveKeys(['value', 'on_file']);
        }
    }

    $guests = $spec['paths']['/rms/bookings/{booking}/guests']['get']
        ?? $spec['paths']['/api/rms/bookings/{booking}/guests']['get']
        ?? null;
    expect($guests)->toBeArray();
    $guestsSchema = $guests['responses']['200']['content']['application/json']['schema'] ?? [];
    $guestsProps = $guestsSchema['properties'] ?? [];
    expect($guestsProps)->toHaveKeys([
        'data',
        'complete_count',
        'total',
        'png_known_total',
        'png_pending_count',
        'max',
        'can_add',
        'issues',
    ]);
    $issueItem = $guestsProps['issues']['items']['properties'] ?? [];
    expect($issueItem)->toHaveKeys(['severity', 'code', 'guest_id', 'message']);

    $consents = $spec['paths']['/rms/bookings/{booking}/consents']['get']
        ?? $spec['paths']['/api/rms/bookings/{booking}/consents']['get']
        ?? null;
    expect($consents)->toBeArray();
    $consent = openApiSchema($spec, 'BookingConsentResource');
    expect($consent['properties'])->toHaveKeys([
        'document',
        'label',
        'required',
        'current_version',
        'outdated',
        'consent',
    ]);

    $bookingExtras = $spec['paths']['/rms/bookings/{booking}/extras']['get']
        ?? $spec['paths']['/api/rms/bookings/{booking}/extras']['get']
        ?? null;
    expect($bookingExtras)->toBeArray();
    $bookingExtrasSchema = $bookingExtras['responses']['200']['content']['application/json']['schema'] ?? [];
    $bookingExtrasProps = $bookingExtrasSchema['properties'] ?? [];
    expect($bookingExtrasProps)->toHaveKeys([
        'data',
        'extras_total',
        'png_collected',
        'tct_collected',
        'png_known_total',
        'png_pending_count',
        'tct_pp',
        'tct_count',
        'extras_due_hours',
        'extras_due_at',
    ]);
    $bookingExtra = openApiSchema($spec, 'BookingExtraResource');
    expect($bookingExtra['properties'])->toHaveKeys([
        'code',
        'name',
        'unit',
        'qty',
        'rate_usd',
        'amount',
        'note',
    ]);

    $extras = $spec['paths']['/rms/extras']['get']
        ?? $spec['paths']['/api/rms/extras']['get']
        ?? null;
    expect($extras)->toBeArray();
    $extrasVersions = $spec['paths']['/rms/extras/versions']['get']
        ?? $spec['paths']['/api/rms/extras/versions']['get']
        ?? null;
    expect($extrasVersions)->toBeArray();
    $extrasDetail = $spec['paths']['/rms/extras/versions/{version}']['get']
        ?? $spec['paths']['/api/rms/extras/versions/{version}']['get']
        ?? null;
    expect($extrasDetail)->toBeArray();

    $contactsIn = $spec['paths']['/rms/contacts-in']['get']
        ?? $spec['paths']['/api/rms/contacts-in']['get']
        ?? null;
    expect($contactsIn)->toBeArray();
    $contactsInParams = collect($contactsIn['parameters'] ?? [])
        ->mapWithKeys(fn (array $parameter): array => [($parameter['name'] ?? '') => $parameter]);
    expect($contactsInParams->keys()->all())->toContain('from', 'to');
    $contactIn = openApiSchema($spec, 'ContactInResource');
    expect($contactIn['properties'])->toHaveKeys([
        'display_reference',
        'charges_total',
        'contact',
        'travel_advisor',
        'owner',
        'can_act',
    ]);

    $nationalities = $spec['paths']['/rms/contacts-in/nationalities']['get']
        ?? $spec['paths']['/api/rms/contacts-in/nationalities']['get']
        ?? null;
    expect($nationalities)->toBeArray();
    $nationalitiesResource = openApiSchema($spec, 'ContactsInNationalitiesResource');
    expect($nationalitiesResource['properties'])->toHaveKeys([
        'nationalities',
        'unknown',
        'total_guests',
    ]);
    $nationalityItem = $nationalitiesResource['properties']['nationalities']['items']['properties'] ?? [];
    expect($nationalityItem)->toHaveKeys(['nationality', 'country_name', 'guests', 'bookings']);

    $countries = $spec['paths']['/rms/countries']['get']
        ?? $spec['paths']['/api/rms/countries']['get']
        ?? null;
    expect($countries)->toBeArray();
    $country = openApiSchema($spec, 'CountryResource');
    expect($country['properties'])->toHaveKeys(['code', 'name']);
    $countriesSchema = $countries['responses']['200']['content']['application/json']['schema'] ?? [];
    $countriesItems = $countriesSchema['items'] ?? $countriesSchema['properties']['data']['items'] ?? null;
    expect($countriesItems)->toBeArray();

    $documentKind = $spec['components']['schemas']['DocumentKind'] ?? [];
    expect($documentKind['enum'] ?? [])->toContain('WIRE_INSTRUCTIONS');

    $document = openApiSchema($spec, 'DocumentResource');
    expect($document['properties'])->toHaveKeys([
        'id',
        'booking_id',
        'kind',
        'kind_label',
        'number',
        'version',
        'reason',
        'payment_id',
        'issued_at',
        'file_sha256',
        'issued_by',
    ]);

    $planRow = openApiSchema($spec, 'DocumentPlanRowResource');
    expect($planRow['properties'])->toHaveKeys([
        'booking_id',
        'booking_reference',
        'client',
        'kind',
        'name',
        'recipient',
        'trigger',
        'date',
        'status',
        'document_id',
        'version',
        'delivery_id',
        'error',
        'payment_id',
        'reminder_days',
        'can_preview',
        'can_issue',
        'can_resend',
    ]);
    expect($planRow['properties']['can_preview']['type'] ?? null)->toBe('boolean');
    expect($planRow['properties']['can_issue']['type'] ?? null)->toBe('boolean');
    expect($planRow['properties']['can_resend']['type'] ?? null)->toBe('boolean');

    $delivery = openApiSchema($spec, 'DeliveryResource');
    expect($delivery['properties'])->toHaveKeys([
        'id',
        'booking_id',
        'document_id',
        'kind',
        'kind_label',
        'to',
        'cc',
        'subject',
        'status',
        'error',
        'blocked_reason',
        'sent_at',
        'triggered_by',
        'created_at',
        'warning',
    ]);
    expect($delivery['properties']['warning']['type'] ?? null)->toBe(['string', 'null']);

    $clientDocuments = $spec['paths']['/rms/documents']['get']
        ?? $spec['paths']['/api/rms/documents']['get']
        ?? null;
    expect($clientDocuments)->toBeArray();
    $clientDocumentParams = collect($clientDocuments['parameters'] ?? [])
        ->mapWithKeys(fn (array $parameter): array => [($parameter['name'] ?? '') => $parameter]);
    expect($clientDocumentParams->keys()->all())->toContain('from', 'to');
    $clientDocumentSchema = $clientDocuments['responses']['200']['content']['application/json']['schema'] ?? [];
    $filters = $clientDocumentSchema['properties']['meta']['properties']['filters']['properties']
        ?? $clientDocumentSchema['properties']['meta']['properties']['filters']
        ?? null;
    expect($filters)->toBeArray();
    $filterFields = $filters['properties'] ?? $filters;
    expect($filterFields)->toHaveKeys(['kinds', 'statuses']);
    $kindItem = $filterFields['kinds']['items']['properties'] ?? [];
    $statusItem = $filterFields['statuses']['items']['properties'] ?? [];
    expect($kindItem)->toHaveKeys(['value', 'label']);
    expect($statusItem)->toHaveKeys(['value', 'label']);

    foreach ([
        ['/rms/bookings/{booking}/documents/{kind}/issue', 'post', '201'],
        ['/rms/documents/{document}/send', 'post', '201'],
        ['/rms/payment-links/{paymentLink}/send', 'post', '201'],
        ['/rms/bookings/{booking}/wire-instructions/send', 'post', '201'],
    ] as [$path, $method, $status]) {
        $operation = $spec['paths'][$path][$method]
            ?? $spec['paths']['/api'.$path][$method]
            ?? null;
        expect($operation)->toBeArray("{$method} {$path} is missing");
        expect($operation['responses'][$status] ?? null)->toBeArray("{$method} {$path} has no {$status}");
        expect($operation['responses'][$status]['content']['application/json'] ?? null)
            ->toBeArray("{$method} {$path} {$status} has no JSON body");
    }

    foreach ([
        '/rms/bookings/{booking}/documents/{kind}/html',
        '/rms/bookings/{booking}/receipts/{payment}/html',
        '/rms/documents/{document}/html',
    ] as $htmlPath) {
        $html = $spec['paths'][$htmlPath]['get']
            ?? $spec['paths']['/api'.$htmlPath]['get']
            ?? null;
        expect($html)->toBeArray("GET {$htmlPath} is missing");
        $htmlContent = $html['responses']['200']['content'] ?? [];
        expect($htmlContent)->toHaveKey('text/html; charset=UTF-8');
        expect($htmlContent)->not->toHaveKey('application/json');
    }

    $file = $spec['paths']['/rms/documents/{document}/file']['get']
        ?? $spec['paths']['/api/rms/documents/{document}/file']['get']
        ?? null;
    expect($file)->toBeArray();
    expect($file['responses']['200']['content'] ?? [])->toHaveKey('application/pdf');
    expect($file['responses']['200']['content'] ?? [])->not->toHaveKey('application/json');

    $offer = openApiSchema($spec, 'OfferResource');
    expect($offer['properties'])->toHaveKeys([
        'benefit_label',
        'scope_label',
        'booking_window_label',
        'travel_window_label',
        'engine_placement',
        'live_departures_count',
        'status',
        'stored_status',
    ]);
    $offerType = $offer['properties']['type']['$ref'] ?? $offer['properties']['type']['allOf'][0]['$ref'] ?? null;
    $offerChannel = $offer['properties']['channel']['$ref'] ?? $offer['properties']['channel']['allOf'][0]['$ref'] ?? null;
    if (is_string($offerType)) {
        expect($offerType)->toContain('OfferType');
    } else {
        expect($offer['properties']['type']['type'] ?? null)->toBe('string');
    }
    if (is_string($offerChannel)) {
        expect($offerChannel)->toContain('OfferChannel');
    } else {
        expect($offer['properties']['channel']['type'] ?? null)->toBe('string');
    }

    $enquiry = openApiSchema($spec, 'CharterEnquiryResource');
    expect($enquiry['properties'])->toHaveKeys([
        'id',
        'preferred_from',
        'preferred_to',
        'departure',
        'guests',
        'contact',
        'message',
        'source',
        'status',
        'created_at',
    ]);

    $completeLink = openApiSchema($spec, 'CompleteLinkResource');
    expect($completeLink['properties'])->toHaveKey('url');
    expect($completeLink['properties']['url']['type'] ?? null)->toBe('string');
});

test('sprint 11 response schemas name their enums and optional preference fields', function (): void {
    Gate::define('viewApiDocs', fn (): bool => true);

    $response = $this->withoutMiddleware(RestrictedDocsAccess::class)
        ->getJson('/docs/api.json')
        ->assertOk();

    /** @var array<string, mixed> $spec */
    $spec = $response->json();

    foreach ([
        'AlertKind',
        'AlertSeverity',
        'AlertNotificationStatus',
        'ManifestKind',
        'ManifestReason',
        'PreferenceSource',
        'PreferenceStatus',
        'PreferenceQuestionType',
        'GuestResponseSource',
        'CommissionAccrualStatus',
        'AgencyUserStatus',
    ] as $enum) {
        $schema = $spec['components']['schemas'][$enum] ?? null;
        expect($schema)->toBeArray("schema {$enum} is missing");
        expect($schema['enum'] ?? null)->toBeArray("schema {$enum} is not an enum")->not->toBeEmpty();
    }

    $alert = openApiSchema($spec, 'AlertResource');
    expect($alert['properties'])->toHaveKeys(['kind', 'severity', 'subject', 'notifications', 'may_acknowledge']);
    expect(sprint11SchemaRef($alert['properties']['kind']))->toContain('AlertKind');
    expect(sprint11SchemaRef($alert['properties']['severity']))->toContain('AlertSeverity');
    $notification = $alert['properties']['notifications']['items']['properties'] ?? [];
    expect(sprint11SchemaRef($notification['status'] ?? []))->toContain('AlertNotificationStatus');

    $list = openApiSchema($spec, 'AlertListResource');
    expect($list['properties']['meta']['properties']['counts']['properties'] ?? [])
        ->toHaveKeys(['INFO', 'WARN', 'CRITICAL']);
    $alertItem = $list['properties']['data']['items'] ?? [];
    $alertItemRef = sprint11SchemaRef($alertItem);
    if ($alertItemRef !== '') {
        expect($alertItemRef)->toContain('AlertResource');
    } else {
        expect(sprint11SchemaRef($alertItem['properties']['kind'] ?? []))->toContain('AlertKind');
        expect(sprint11SchemaRef($alertItem['properties']['severity'] ?? []))->toContain('AlertSeverity');
    }

    $kind = openApiSchema($spec, 'AlertKindResource');
    expect(sprint11SchemaRef($kind['properties']['kind']))->toContain('AlertKind');
    expect(sprint11SchemaRef($kind['properties']['severity']))->toContain('AlertSeverity');

    $version = openApiSchema($spec, 'ManifestVersionResource');
    expect(sprint11SchemaRef($version['properties']['kind']))->toContain('ManifestKind');
    expect(sprint11SchemaRef($version['properties']['reason']))->toContain('ManifestReason');

    $issued = openApiSchema($spec, 'ManifestIssuedResource');
    expect($issued['properties'])->toHaveKeys(['created', 'message', 'data']);
    expect(sprint11SchemaRef($issued['properties']['data']))->toContain('ManifestVersionResource');

    $generate = $spec['paths']['/rms/departures/{departure}/manifests/{kind}']['post']
        ?? $spec['paths']['/api/rms/departures/{departure}/manifests/{kind}']['post']
        ?? null;
    expect($generate)->toBeArray();
    foreach (['200', '201'] as $status) {
        $body = $generate['responses'][$status]['content']['application/json']['schema'] ?? [];
        expect(sprint11SchemaRef($body))->toContain('ManifestIssuedResource');
    }

    openApiSchema($spec, 'ManifestDepartureResource');

    $experience = openApiSchema($spec, 'DepartureGuestExperienceResource');
    $guest = $experience['properties']['guests']['items'] ?? [];
    expect($guest['properties'] ?? [])->toHaveKeys([
        'accessibility_provided',
        'emergency_contact_provided',
        'accessibility',
        'emergency_contact',
        'status',
        'source',
    ]);
    expect($guest['required'] ?? [])->not->toContain('accessibility');
    expect($guest['required'] ?? [])->not->toContain('emergency_contact');
    expect(sprint11SchemaRef($guest['properties']['status']))->toContain('PreferenceStatus');
    expect(sprint11SchemaRef($guest['properties']['source']))->toContain('PreferenceSource');

    $preferences = openApiSchema($spec, 'GuestPreferencesResource');
    $current = $preferences['properties']['current'] ?? [];
    $currentProps = $current['properties'] ?? $current['anyOf'][0]['properties'] ?? [];
    $currentRequired = $current['required'] ?? $current['anyOf'][0]['required'] ?? [];
    expect($currentProps)->toHaveKeys(['accessibility_provided', 'accessibility', 'source']);
    expect($currentRequired)->not->toContain('accessibility');
    expect($currentRequired)->not->toContain('emergency_contact');
    expect(sprint11SchemaRef($currentProps['source'] ?? []))->toContain('PreferenceSource');

    $question = openApiSchema($spec, 'PreferenceQuestionResource');
    expect(sprint11SchemaRef($question['properties']['type']))->toContain('PreferenceQuestionType');

    $surveyQuestion = openApiSchema($spec, 'SurveyQuestionResource');
    expect($surveyQuestion['properties'] ?? null)->toHaveKeys(['key', 'label', 'type', 'min', 'max']);
    expect(sprint11SchemaRef($surveyQuestion['properties']['type']))->toContain('SurveyQuestionType');

    $nps = openApiSchema($spec, 'NpsViewResource');
    expect($nps['properties'])->toHaveKeys(['kpis', 'responses', 'facts']);
    expect($nps['properties']['responses']['items']['properties'] ?? [])->toHaveKeys([
        'booking_reference',
        'guest',
        'score',
        'score_class',
    ]);

    $staff = openApiSchema($spec, 'GuestResponseResource');
    expect($staff['properties'])->toHaveKeys(['id', 'guest_id', 'score', 'source']);
    expect(sprint11SchemaRef($staff['properties']['source']))->toContain('GuestResponseSource');

    $picker = openApiSchema($spec, 'GuestExperienceDepartureResource');
    expect($picker['properties'])->toHaveKeys(['departure_id', 'date', 'property', 'passengers']);

    $surveyGuest = openApiSchema($spec, 'SurveyGuestResource');
    expect($surveyGuest['properties'])->toHaveKeys(['guest_id', 'name', 'cabin', 'responded']);
    expect($surveyGuest['properties'])->not->toHaveKeys(['passport_no', 'medical_note', 'dob', 'nationality']);

    $commission = openApiSchema($spec, 'CommissionResource');
    expect($commission['properties']['payout'] ?? null)->toBeArray();
    expect(sprint11SchemaRef($commission['properties']['status']))->toContain('CommissionAccrualStatus');

    $preview = openApiSchema($spec, 'AgencyPortalPreviewResource');
    expect($preview['properties'])->toHaveKeys(['bookings', 'commissions', 'net_rates', 'sales_materials']);
    $previewCommission = $preview['properties']['commissions']['items']['properties']['status'] ?? [];
    expect(sprint11SchemaRef($previewCommission))->toContain('CommissionAccrualStatus');

    $agency = openApiSchema($spec, 'AgencyResource');
    expect($agency['properties'])->toHaveKey('users');
    $userStatus = $agency['properties']['users']['items']['properties']['status'] ?? [];
    expect(sprint11SchemaRef($userStatus))->toContain('AgencyUserStatus');
    $userStatusSchema = $spec['components']['schemas']['AgencyUserStatus'] ?? null;
    expect($userStatusSchema)->toBeArray();
    expect($userStatusSchema['enum'] ?? null)->toEqualCanonicalizing([
        'INVITE_ON_APPROVAL',
        'INVITE_ON_PORTAL_LAUNCH',
        'ACTIVE',
        'DISABLED',
    ]);
});

/**
 * @param  array<string, mixed>  $node
 */
function sprint11SchemaRef(array $node): string
{
    $ref = $node['$ref'] ?? $node['allOf'][0]['$ref'] ?? null;

    if (is_string($ref)) {
        return $ref;
    }

    foreach (['anyOf', 'oneOf'] as $union) {
        foreach ($node[$union] ?? [] as $arm) {
            if (! is_array($arm)) {
                continue;
            }

            $nested = sprint11SchemaRef($arm);

            if ($nested !== '') {
                return $nested;
            }
        }
    }

    return '';
}

test('sprint 12 report, metric, waitlist and charter schemas name their enums', function (): void {
    Gate::define('viewApiDocs', fn (): bool => true);

    $response = $this->withoutMiddleware(RestrictedDocsAccess::class)
        ->getJson('/docs/api.json')
        ->assertOk();

    /** @var array<string, mixed> $spec */
    $spec = $response->json();

    $metrics = openApiSchema($spec, 'MetricsResource');
    expect($metrics['properties'])->toHaveKeys(['window', 'scope', 'metrics']);
    $metricBody = $metrics['properties']['metrics']['properties'] ?? [];
    expect($metricBody)->toHaveKey('occupancy');
    $definition = $metricBody['occupancy']['properties']['definition']['properties'] ?? [];
    expect($definition)->toHaveKeys(['sentence', 'filters_on', 'excludes']);

    $definitionRow = openApiSchema($spec, 'ReportDefinitionResource');
    expect($definitionRow['properties'])->toHaveKeys(['key', 'title', 'sentence', 'permission', 'formats', 'allowed']);

    $run = openApiSchema($spec, 'ReportRunResource');
    expect($run['properties'])->toHaveKeys(['status', 'formats', 'window_from', 'window_to']);
    expect(sprint11SchemaRef($run['properties']['status']))->toContain('ReportRunStatus');

    $subscription = openApiSchema($spec, 'ReportSubscriptionResource');
    expect(sprint11SchemaRef($subscription['properties']['cadence']))->toContain('ReportCadence');

    $waitlist = openApiSchema($spec, 'WaitlistEntryResource');
    expect($waitlist['properties'])->toHaveKeys(['auto_notified', 'position']);
    expect(sprint11SchemaRef($waitlist['properties']['cabin_category']))->toContain('CabinCategory');

    $enquiry = openApiSchema($spec, 'CharterEnquiryResource');
    expect($enquiry['properties'])->toHaveKeys(['proposal', 'sla_breached', 'booking']);
    expect(sprint11SchemaRef($enquiry['properties']['status']))->toContain('CharterEnquiryStatus');

    foreach (['ReportRunStatus', 'ReportCadence', 'CharterEnquiryStatus', 'CharterProposalState'] as $enum) {
        $schema = $spec['components']['schemas'][$enum] ?? null;
        expect($schema)->toBeArray();
        expect($schema['enum'] ?? [])->not->toBeEmpty();
    }

    expect($spec['components']['schemas']['StoreReportRunRequest']['properties'] ?? null)->not->toBeEmpty();
    expect($spec['components']['schemas']['UpdateReportSubscriptionRequest']['properties'] ?? null)->toHaveKey('active');
    expect($spec['components']['schemas']['UpdateCharterEnquiryRequest']['properties'] ?? null)->toHaveKeys(['status', 'reason']);
    expect($spec['components']['schemas']['IssueCharterProposalRequest']['properties'] ?? null)->toHaveKey('reason');
});
