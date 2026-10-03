<?php

declare(strict_types=1);

use App\Enums\ConfigKind;
use App\Http\Requests\StayRequest;
use App\Models\Departure;
use App\Services\Config\ConfigPublisher;
use App\Support\Stays\StayClock;
use App\Support\Stays\StayDates;
use Carbon\CarbonImmutable;
use Database\Seeders\ConfigSeeder;
use Illuminate\Validation\ValidationException;
use LogicException;

beforeEach(function (): void {
    $this->seed(ConfigSeeder::class);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
    config(['iconic.business_timezone' => 'Pacific/Galapagos']);
});

test('the arrival day is zero days away and check-in is 15:00 local', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-15 08:00:00', 'Pacific/Galapagos'));
    $clock = app(StayClock::class);
    $stay = StayDates::of('2026-06-15', '2026-06-22');

    expect($clock->today()->toDateString())->toBe('2026-06-15')
        ->and($clock->daysUntilArrival($stay))->toBe(0)
        ->and($clock->isArrivalDayOrLater($stay))->toBeTrue()
        ->and($clock->daysSinceCheckOut($stay))->toBe(-7)
        ->and($clock->arrivalMoment($stay)->format('Y-m-d H:i'))->toBe('2026-06-15 21:00')
        ->and($clock->checkOutMoment($stay)->format('Y-m-d H:i'))->toBe('2026-06-22 17:00')
        ->and($clock->maxNights())->toBe(30);
});

test('the day before arrival is not arrival day or later', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-14 23:30:00', 'Pacific/Galapagos'));
    $clock = app(StayClock::class);
    $stay = StayDates::of('2026-06-15', '2026-06-22');

    expect($clock->daysUntilArrival($stay))->toBe(1)
        ->and($clock->isArrivalDayOrLater($stay))->toBeFalse();
});

test('days since check-out count the check-out date as zero', function (): void {
    $clock = app(StayClock::class);
    $stay = StayDates::of('2026-06-15', '2026-06-22');

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-22 01:00:00', 'Pacific/Galapagos'));
    expect($clock->daysSinceCheckOut($stay))->toBe(0)
        ->and($clock->daysUntilArrival($stay))->toBe(-7)
        ->and($clock->isArrivalDayOrLater($stay))->toBeTrue();

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-23 12:00:00', 'Pacific/Galapagos'));
    expect($clock->daysSinceCheckOut($stay))->toBe(1);
});

test('a local check-in time keeps the offset on each side of a DST change', function (): void {
    config(['iconic.business_timezone' => 'America/New_York']);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-07 12:00:00', 'America/New_York'));
    $clock = app(StayClock::class);

    $before = StayDates::of('2026-03-07', '2026-03-08');
    $after = StayDates::of('2026-03-09', '2026-03-10');

    expect($clock->arrivalMoment($before)->format('Y-m-d H:i'))->toBe('2026-03-07 20:00')
        ->and($clock->arrivalMoment($after)->format('Y-m-d H:i'))->toBe('2026-03-09 19:00')
        ->and($clock->daysUntilArrival($after))->toBe(2)
        ->and($clock->checkOutMoment($before)->format('Y-m-d H:i'))->toBe('2026-03-08 15:00');
});

test('arrival moment uses the published check-in time', function (): void {
    app(ConfigPublisher::class)->publish(
        ConfigKind::BusinessRules,
        businessRulesDocument(['stay' => ['check_in_time' => '16:30']]),
        1,
        'test check-in time',
        adminUser(),
    );

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-15 08:00:00', 'Pacific/Galapagos'));
    $clock = app(StayClock::class);
    $stay = StayDates::of('2026-06-15', '2026-06-22');

    expect($clock->arrivalMoment($stay)->format('Y-m-d H:i'))->toBe('2026-06-15 22:30');
});

test('a departure stay is its date plus the itinerary nights', function (): void {
    $departure = Departure::factory()->create(['date' => '2026-03-01']);

    expect($departure->stayDates()->toArray())->toBe([
        'check_in' => '2026-03-01',
        'check_out' => '2026-03-08',
        'nights' => 7,
    ])->and($departure->returnDate()->toDateString())->toBe('2026-03-08');

    $departure->itinerary->update(['nights' => 0]);
    $departure->unsetRelation('itinerary');

    expect($departure->stayDates()->nights())->toBe(Departure::DEFAULT_NIGHTS)
        ->and($departure->returnDate()->toDateString())->toBe('2026-03-08');
});

test('stay fields reject a bad date, a reversed range and a stay past max nights', function (): void {
    $missing = stayProbe([]);
    expect(fn () => $missing->stayDates())->toThrow(LogicException::class);
    expect(fn () => $missing->validateResolved())->toThrow(ValidationException::class);

    $reversed = stayProbe(['check_in' => '2026-03-08', 'check_out' => '2026-03-01']);
    expect(fn () => $reversed->validateResolved())->toThrow(ValidationException::class);
    expect(fn () => $reversed->stayDates())->toThrow(LogicException::class);

    $tooLong = stayProbe(['check_in' => '2026-03-01', 'check_out' => '2026-04-01']);

    $rejected = false;

    try {
        $tooLong->validateResolved();
    } catch (ValidationException $exception) {
        $rejected = true;
        expect($exception->errors()['check_out'])->toBe(['A stay cannot be longer than 30 nights.']);
    }

    expect($rejected)->toBeTrue();

    expect(fn () => $tooLong->stayDates())->toThrow(LogicException::class);

    $ok = stayProbe(['check_in' => '2026-03-01', 'check_out' => '2026-03-31']);
    $ok->validateResolved();

    expect($ok->stayDates()->nights())->toBe(30)
        ->and($ok->stayDates()->checkIn()->toDateString())->toBe('2026-03-01');
});

/**
 * @param  array<string, string>  $payload
 */
function stayProbe(array $payload): StayRequest
{
    $request = StayRequest::create('/stays', 'POST', $payload);
    $request->setContainer(app());
    $request->setRedirector(app('redirect'));

    return $request;
}
