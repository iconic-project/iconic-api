<?php

declare(strict_types=1);

use App\Support\Config\Documents\BusinessRulesDocument;
use Illuminate\Support\Facades\Validator;

test('the demo stay section passes validation', function (): void {
    expect(Validator::make(BusinessRulesDocument::initial(), BusinessRulesDocument::rules())->fails())->toBeFalse();
});

test('each stay field is rejected on its own', function (): void {
    $cases = [
        'stay.check_in_time' => ['stay' => ['check_in_time' => '3pm']],
        'stay.check_out_time' => ['stay' => ['check_out_time' => '25:00']],
        'stay.no_show_cutoff_time' => ['stay' => ['no_show_cutoff_time' => '24:00']],
        'stay.min_nights' => ['stay' => ['min_nights' => 0]],
        'stay.max_nights' => ['stay' => ['max_nights' => 366]],
        'stay.max_rooms_per_booking' => ['stay' => ['max_rooms_per_booking' => 0]],
        'stay.check_in_requires_full_payment' => ['stay' => ['check_in_requires_full_payment' => 'yes']],
        'stay.booking_horizon_days' => ['stay' => ['booking_horizon_days' => 29]],
    ];

    foreach ($cases as $field => $override) {
        $errors = Validator::make(businessRulesDocument($override), BusinessRulesDocument::rules())->errors();

        expect($errors->has($field))->toBeTrue();
    }

    $tooManyRooms = Validator::make(
        businessRulesDocument(['stay' => ['max_rooms_per_booking' => 51]]),
        BusinessRulesDocument::rules(),
    );
    expect($tooManyRooms->errors()->has('stay.max_rooms_per_booking'))->toBeTrue();

    $tooFar = Validator::make(
        businessRulesDocument(['stay' => ['booking_horizon_days' => 1096]]),
        BusinessRulesDocument::rules(),
    );
    expect($tooFar->errors()->has('stay.booking_horizon_days'))->toBeTrue();

    $maxBelowMin = Validator::make(
        businessRulesDocument(['stay' => ['min_nights' => 10, 'max_nights' => 5]]),
        BusinessRulesDocument::rules(),
    );
    expect($maxBelowMin->errors()->has('stay.max_nights'))->toBeTrue();
    expect($maxBelowMin->errors()->first('stay.max_nights'))->toBe('Maximum nights must be at least the minimum nights.');
});

test('check-out later than check-in warns that the room cannot turn over the same day', function (): void {
    $document = BusinessRulesDocument::fromArray(businessRulesDocument([
        'stay' => ['check_in_time' => '15:00', 'check_out_time' => '16:00'],
    ]));

    $paths = array_map(fn ($warning) => $warning->path, $document->warnings(null));

    expect($paths)->toContain('stay.check_out_time');
});

test('the demo check-out time does not warn', function (): void {
    $document = BusinessRulesDocument::fromArray(BusinessRulesDocument::initial());
    $paths = array_map(fn ($warning) => $warning->path, $document->warnings(null));

    expect($paths)->not->toContain('stay.check_out_time');
});
