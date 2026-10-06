<?php

declare(strict_types=1);

use App\Support\Config\Documents\EngineSettingsDocument;
use Database\Seeders\ConfigSeeder;
use Illuminate\Support\Facades\Validator;

test('rules reject cabin, property, ages, search months, locale and copy shape', function (): void {
    $invalid = engineSettingsDocument([
        'guests' => [
            'max_per_cabin' => 5,
            'max_per_property' => 0,
            'child_min_age' => 18,
            'child_max_age' => 18,
            'under_age_message' => '',
        ],
        'calendar' => [
            'default_search_from' => '2028-13',
            'default_search_to' => 'not-a-month',
            'default_adults' => 0,
            'horizon_months' => 5,
        ],
        'locale' => [
            'default' => 'es',
            'live' => ['es'],
            'currency' => 'EUR',
        ],
        'copy' => [
            'book_now_pay_later' => '',
        ],
    ]);
    $invalid['copy']['confirmation_steps'] = ['one', 'two'];

    $errors = Validator::make($invalid, EngineSettingsDocument::rules())->errors();

    expect($errors->has('guests.max_per_cabin'))->toBeTrue();
    expect($errors->has('guests.max_per_property'))->toBeTrue();
    expect($errors->has('guests.child_min_age'))->toBeTrue();
    expect($errors->has('guests.child_max_age'))->toBeTrue();
    expect($errors->has('guests.under_age_message'))->toBeTrue();
    expect($errors->has('calendar.default_search_from'))->toBeTrue();
    expect($errors->has('calendar.default_search_to'))->toBeTrue();
    expect($errors->has('calendar.default_adults'))->toBeTrue();
    expect($errors->has('calendar.horizon_months'))->toBeTrue();
    expect($errors->has('locale.default'))->toBeTrue();
    expect($errors->has('locale.live.0'))->toBeTrue();
    expect($errors->has('locale.currency'))->toBeTrue();
    expect($errors->has('copy.book_now_pay_later'))->toBeTrue();
    expect($errors->has('copy.confirmation_steps'))->toBeTrue();
});

test('the locale pins reject es', function (): void {
    $document = engineSettingsDocument();
    $document['locale']['default'] = 'es';
    $document['locale']['live'] = ['es'];

    $errors = Validator::make($document, EngineSettingsDocument::rules())->errors();

    expect($errors->has('locale.default'))->toBeTrue();
    expect($errors->has('locale.live.0'))->toBeTrue();
});

test('rules reject property over nine cabins, reversed child ages and search range', function (): void {
    $document = engineSettingsDocument([
        'guests' => [
            'max_per_cabin' => 1,
            'max_per_property' => 16,
            'child_min_age' => 12,
            'child_max_age' => 6,
        ],
        'calendar' => [
            'default_search_from' => '2028-06',
            'default_search_to' => '2028-01',
            'default_adults' => 10,
        ],
    ]);

    $errors = Validator::make($document, EngineSettingsDocument::rules())->errors();

    expect($errors->has('guests.max_per_property'))->toBeTrue();
    expect($errors->has('guests.child_max_age'))->toBeTrue();
    expect($errors->has('calendar.default_search_to'))->toBeTrue();
    expect($errors->has('calendar.default_adults'))->toBeTrue();
});

test('copyPaths classifies footnote and copy blocks as copy', function (): void {
    expect(EngineSettingsDocument::isCopyPath('fees.footnote'))->toBeTrue();
    expect(EngineSettingsDocument::isCopyPath('copy.book_now_pay_later'))->toBeTrue();
    expect(EngineSettingsDocument::isCopyPath('copy.unknown_block'))->toBeTrue();
    expect(EngineSettingsDocument::isCopyPath('guests.max_per_cabin'))->toBeFalse();
    expect(EngineSettingsDocument::isCopyPath('guests'))->toBeFalse();
});

test('warnings flag property capacity, under-age message and copy versus sla', function (): void {
    $this->seed(ConfigSeeder::class);

    $draft = engineSettingsDocument([
        'guests' => [
            'max_per_property' => 14,
            'under_age_message' => 'Under 5 not accommodated',
        ],
        'copy' => [
            'traveling_with_children' => 'A 15% discount applies to children aged 7–16.',
        ],
    ]);

    $messages = array_map(
        fn ($warning): string => $warning->message,
        EngineSettingsDocument::fromArray($draft)->warnings(null),
    );

    expect($messages)->toContain('The property guest cap will show 14 guests (follows max per property).');
    expect($messages)->toContain('Under-age message says 5 but children are accepted from age 6.');
    expect($messages)->toContain('"Traveling with children" mentions different ages than the guest rules (6–17).');
});

test('confirmation step 1 hours versus the business-rules sla is skipped when unpublished', function (): void {
    $draft = engineSettingsDocument([
        'copy' => [
            'confirmation_steps' => [
                'Within 48 hours a member of our team confirms your cabins.',
                'You receive your booking confirmation and deposit link (10%).',
                'After the deposit, we gather guest details.',
            ],
        ],
    ]);

    $messages = array_map(
        fn ($warning): string => $warning->message,
        EngineSettingsDocument::fromArray($draft)->warnings(null),
    );

    expect($messages)->not->toContain(
        'Confirmation step 1 says 48 h but the response SLA is 24 h.',
    );
});

test('warnings flag confirmation step 1 hours that differ from the business-rules sla', function (): void {
    $this->seed(ConfigSeeder::class);

    $draft = engineSettingsDocument([
        'copy' => [
            'confirmation_steps' => [
                'Within 48 hours a member of our team confirms your cabins.',
                'You receive your booking confirmation and deposit link (10%).',
                'After the deposit, we gather guest details.',
            ],
        ],
    ]);

    $messages = array_map(
        fn ($warning): string => $warning->message,
        EngineSettingsDocument::fromArray($draft)->warnings(null),
    );

    expect($messages)->toContain('Confirmation step 1 says 48 h but the response SLA is 24 h.');
});

test('copy with no numbers produces no copy-versus-rates or copy-versus-sla warnings', function (): void {
    $this->seed(ConfigSeeder::class);

    $draft = engineSettingsDocument([
        'guests' => [
            'under_age_message' => 'Too young to sail',
        ],
        'copy' => [
            'traveling_with_children' => 'Children are welcome when traveling with an adult.',
            'solo_and_triple' => 'Solo and triple occupancy are priced on the next step.',
            'pay_today' => 'Nothing is charged until you decide.',
            'confirmation_steps' => [
                'A member of our team confirms your cabins.',
                'You receive your booking confirmation and deposit link.',
                'After the deposit we gather guest details.',
            ],
        ],
    ]);

    $warnings = EngineSettingsDocument::fromArray($draft)->warnings(null);

    expect($warnings)->toBe([]);
});

test('png and tct fees are read-only and the footnote can still change', function (): void {
    $current = EngineSettingsDocument::fromArray(EngineSettingsDocument::initial());

    $tct = EngineSettingsDocument::initial();
    $tct['fees']['tct_pp'] = 21;
    expect(EngineSettingsDocument::fromArray($tct)->publishErrors($current))->toHaveKey('fees.tct_pp');

    $png = EngineSettingsDocument::initial();
    $png['fees']['png']['foreign_over_12'] = 201;
    expect(EngineSettingsDocument::fromArray($png)->publishErrors($current))->toHaveKey('fees.png');

    $note = EngineSettingsDocument::initial();
    $note['fees']['footnote'] = 'Hotel taxes are configured separately.';
    expect(EngineSettingsDocument::fromArray($note)->publishErrors($current))->toBe([]);
});
