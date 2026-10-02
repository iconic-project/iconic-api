# 16-04 — `StayDates` and the stay clock

**Repo:** iconic-api
**Depends on:** 16-01
**Read first:** 09 H1–H3, H10, H18; `app/Casts/CalendarDate.php`, `app/Support/BusinessTime.php`, `app/Support/BusinessHours.php`, `app/Support/Dates/*`, `Models/Departure::returnDate()`

## Why
Today every rule asks `$booking->departure->date`. After the migration it must ask the stay. One small, well-tested type stops 270 files from each inventing their own date maths.

## Build
1. `App\Support\Stays\StayDates` (final, immutable, `CarbonImmutable` inside):
   - `StayDates::of(string|CarbonInterface $checkIn, string|CarbonInterface $checkOut)` — throws `InvalidArgumentException` if `checkOut <= checkIn`.
   - `StayDates::forNights(checkIn, int $nights)`.
   - `checkIn()`, `checkOut()`, `nights(): int`, `eachNight(): iterable<CarbonImmutable>` (check_in … check_out−1), `lastNight()`, `contains(night): bool`, `overlaps(StayDates): bool`, `equals()`, `toArray(): array{check_in: string, check_out: string, nights: int}`.
   - No time zone logic inside — it is pure dates.
2. `App\Support\Stays\StayClock` (reads `BusinessTime` and the stay rules from task 05 through `CurrentConfig`):
   - `today(): CarbonImmutable` (property-local date; H18).
   - `daysUntilArrival(StayDates): int`, `daysSinceCheckOut(StayDates): int`.
   - `arrivalMoment(StayDates)`: check-in date at `stay.check_in_time` in business tz → UTC.
   - `checkOutMoment(StayDates)`: check-out date at `stay.check_out_time` → UTC.
   - `isArrivalDayOrLater(StayDates): bool`.
   Until task 05 is merged, read the times through a single private method marked `// TODO(16-05)`.
3. `App\Http\Requests\Concerns\ValidatesStay` trait: rules for `check_in` / `check_out` (`date_format:Y-m-d`, `after:check_in`) and a hook that builds `StayDates`. Max length validated against `stay.max_nights` (task 05).
4. `Departure::stayDates(): StayDates` — `StayDates::forNights($this->date, nights)`, so yacht code can start using the new type immediately. `returnDate()` delegates to it.

## Tests (unit, Pest)
- 1-night, 7-night, month-crossing, year-crossing, leap-day (2028-02-28 → 2028-03-01 = 2 nights).
- `eachNight` never yields `check_out`.
- `overlaps`: touching stays (`A.check_out == B.check_in`) do **not** overlap.
- `StayClock` around a DST change in the business time zone; `daysUntilArrival` on the arrival day = 0.

## Out of scope
Any caller migration except `Departure::returnDate()`.

## Done when
`StayDates` and `StayClock` have 100% line coverage and no other class does `addDays(nights)` on a stay.
