# Sprint 19 · Report

Each task appends its section below.

## Task 01 · Stay columns on bookings, backfill

### What was built

Bookings now carry a stay. Migration `2026_10_05_160001_add_stay_columns_to_bookings` adds `property_id`, `room_type_id`, `check_in`, `check_out`, `nights`, `rate_plan_code`, `night_lines`, `tax_lines`, `child_ages`, `expected_arrival_time`, `checked_in_at`, `checked_out_at`, and `no_show_at`, plus indexes on `(property_id, check_in)`, `(property_id, check_out)`, and `(status, check_in)`.

Backfill runs in chunks of 200. Check-in is the departure date, check-out and nights come from `Departure::stayDates()`, and `property_id` comes from the departure. A room copies `room.room_type_id`. `rate_plan_code`, `night_lines`, and `tax_lines` stay null; the frozen yacht price remains in `price_lines`. The update is a query-builder write, so it does not bump `updated_at` or fire model events. Status values are rewritten first (`ON_BOARD` → `IN_HOUSE`, `COMPLETED` → `CHECKED_OUT`) so the enum cast can load the rows. History rows are not rewritten.

After backfill, `property_id`, `check_in`, `check_out`, and `nights` are NOT NULL. `departure_id` is nullable and still restricted on delete. `room_type_id` stays nullable.

`BookingStatus` cases are `InHouse`, `CheckedOut`, and `NoShow`. `holdsInventory()` is true for `InHouse` and `NoShow` (only Released, Cancelled, and CancelledPostpaid free the room). `isConfirmedOrLater()` includes in house, checked out, and no-show. Transitions are Fully paid → in house, and in house → checked out, guarded by the stay's check-in and check-out.

`Booking::stay()` reads the raw date columns. `balanceDueDate()`, its SQL twin `dueDateSql()`, and `extrasDueAt()` measure from check-in. Document send dates and the refund "days before" clock use check-in. Hold expiry at the three request/checkout writers is given the stay check-in, which for a yacht departure is the departure date. `ChangeHistoryResource`, `BookingAuditResource`, and the contact timeline map stored `ON_BOARD` / `COMPLETED` (and the spaced labels) to the new labels without changing the stored row.

`BookingResource` adds `stay`, `room`, `room_type`, `rate_plan` (the code, or null), `night_lines`, `tax_lines`, and `times`. `departure` and `cabin` stay. The bookings list eager-loads `cabin.roomType`, `roomType`, and `property`, and the resource reuses a loaded cabin as `room`, so the list query count does not grow with extra rows.

### Charter without a room

Yacht fixture `ANK-2026-0012` (CHARTER, cab ALL, no room) on ANAMARA takes the property's first room type by `sort`, then `id`: **SUITE** (sort 0, before OWNER at sort 1).

### Files touched

- `database/migrations/2026_10_05_160001_add_stay_columns_to_bookings.php`
- `app/Support/Bookings/BackfillBookingStays.php`
- `app/Support/Bookings/StayFromDeparture.php`
- `app/Support/Bookings/LegacyStatus.php`
- `app/Support/Bookings/Transitions.php`
- `app/Models/Booking.php`
- `app/Enums/BookingStatus.php`
- `app/Http/Resources/Rms/BookingResource.php`
- `app/Http/Resources/Rms/ChangeHistoryResource.php`
- `app/Http/Resources/Rms/BookingAuditResource.php`
- `app/Http/Controllers/Rms/BookingController.php`
- `app/Actions/Bookings/MoveBooking.php`
- `app/Actions/Bookings/CreateBookingRequest.php`
- `app/Actions/Checkout/SubmitEngineCheckout.php`
- `app/Actions/Portal/SubmitPortalRequest.php`
- `app/Actions/Refunds/CreateRefundRequest.php`
- `app/Support/Documents/DocumentPlan.php`
- `app/Support/Documents/Snapshots/DocumentFacts.php`
- `app/Console/Commands/DocumentsDueCommand.php`
- `app/Console/Commands/VoyageStatusCommand.php`
- `app/Console/Commands/NpsSurveyCommand.php`
- `app/Support/Schedule/IconicSchedule.php`
- `app/Support/Operations/VoyageStatus.php`
- `app/Support/Crm/ContactTimeline.php`
- `app/Support/Crm/DealStages.php`
- `app/Support/Crm/DealStageMap.php`
- `app/Support/Crm/ContactDerived.php`
- `app/Support/Crm/TaskSweep.php`
- `app/Support/Commissions/Accrual.php`
- `app/Support/Journeys/JourneyEngine.php`
- `app/Support/Manifests/ManifestRoster.php`
- `app/Support/Metrics/CommercialMetrics.php`
- `app/Support/Waitlist/WaitlistOffers.php`
- `app/Actions/GuestExperience/RecordGuestResponse.php`
- `phpunit.xml` (test process `memory_limit` 1G)
- `tests/Feature/Bookings/BookingStayColumnsTest.php`
- `tests/Unit/Enums/BookingStatusTest.php`
- `tests/Feature/OpenApi/PanelResponseSchemasTest.php`
- `tests/Feature/Bookings/TransitionBookingTest.php`
- `tests/Feature/Bookings/BookingStatusInventoryTest.php`
- `tests/Unit/Support/Bookings/TransitionsTest.php`
- `tests/Feature/Operations/VoyageStatusTest.php`
- `tests/Feature/Crm/PipelineTest.php`
- `tests/Feature/Crm/JourneysTest.php`
- and the other call sites that named `BookingStatus::OnBoard` or `BookingStatus::Completed`
- `tests/e2e/scenarios/bookings/BKG-06-transitions-cancel.md`
- `tests/e2e/scenarios/crm/CAMP-01-opening-27-redeemed.md`
- `tests/e2e/fixtures/reference-values.md`

### Deviations

`room_type_id` is not in the NOT NULL list. A charter whose property has no room type cannot be given one. Backfill throws and names the booking. The creating hook leaves the column null so a factory departure with no room types can still insert.

`Booking::copyStayFromDeparture()` runs on `creating` and copies the departure onto the stay columns. Existing yacht writers (reservation, request, engine checkout, portal request, demo seeders, factory) still insert after the columns are required. Task 02 replaces that write path. `MoveBooking` copies the same columns when the departure or room changes, so the balance date stays aligned.

`down()` maps `NO_SHOW` to `CANCELLED`. That loses the no-show. `IN_HOUSE` returns to `ON_BOARD` and `CHECKED_OUT` to `COMPLETED`.

`deposit_due_on` is not derived from check-in. On the yacht seed it stays null. The equivalence test asserts that, and that balance due, cancellation band, and hold expiry match the departure date.

`child_ages` is stored and not exposed on `BookingResource`. `rate_plan` is the code string.

`NoShow` holds inventory. Releasing the room on mark-no-show is task 03.

`phpunit.xml` sets `memory_limit` to 1G. At 512M, `php artisan test` dies inside the architecture suite while parsing `app/`.

### Open questions

None. The charter fallback above is the one the task asked to flag.

### Notes for later

Task 02 should stop writing stays by copying a departure. Task 03 owns check-in, check-out, and no-show timestamps, and releasing a no-show. Task 05 owns the remaining money clocks (commission accrual, NPS, retention, journeys). Task 07 owns the panel, which still title-cases `ON_BOARD` and `COMPLETED`. `docs/requirements/examples/hotel-seed-data.json` still has a booking status `ON_BOARD`; that file is not loaded as bookings yet.

`composer check` is not green on this tree. This task's stay, transition, voyage, document, refund, and list-query tests pass. Larastan reports no errors. The failures are in files this task did not change:

- `SystemRoleTest` — manager defaults now include `bookings.override_restrictions` and `inventory.manage_restrictions`.
- `DemoAgenciesSeederTest` — agency count is 4, the test expects 3.
- `BusinessRulesEndpointsTest` — registry size is 101, the test expects 99.
- `EngineSettingsSeederTest` — seeded `details_note` says "Iconic"; the engine source string still says "Anakata".
- `CabinClaimsTest` — `internal_blocks.property_id` has no default on the long-block insert.
- `SensitiveEncryptedCastTest` — a rotated sensitive key throws `RuntimeException`, the test expects `DecryptException`.
- Pint: 11 feature tests have unused or unordered imports (`BookingAuditTest`, `CreateBookingRequestTest`, `WaitlistAutomationTest`, `WaitlistTest`, `EngineFeedTest`, `AvailabilityEndpointsTest`, `DepartureLocksTest`, `RoomEndpointsTest`, `CommercialMetricsTest`, `PortalAvailabilityLabelsTest`, `PortalRequestsTest`).

Full `php artisan test`: 6 failed, 1552 passed.

## Task 02 · Create bookings, requests and groups on stays

### What was built

Staff can sell a stay. `POST /api/rms/bookings` with `check_in` runs `CreateStayReservation`. One room is one booking. Two or more rooms are one group and N bookings, in one transaction, with `booking.created` on each booking and `group.created` on the group. A room may carry its own `check_in` and `check_out`. Those dates replace the top-level stay for that room.

Each room is checked with `Restrictions::evaluate`. All reasons come back on `stay` unless `override_restrictions` is set, the actor has `bookings.override_restrictions`, and a reason is present. `StayQuoter` freezes `price_lines`, `night_lines`, `tax_lines`, `rates_version_id`, `deposit_pct`, and `balance_days` from the rate plan. An explicit `room_id` is claimed with `ClaimService::claim`. A missing `room_id` is claimed with `claimType`, so the allocator picks. `expected_total` must match the sum of the room totals or the response is 409 with the new quote and no row is written.

`CreateBookingRequest` delegates to `CreateStayReservation::request` when `check_in` is set. That path is one room, status `REQUESTED`, `ClaimKind::Hold`, and hold expiry from `BusinessHours::holdExpiry` using the stay check-in.

`GET /api/rms/bookings/form-options` now includes active room types (occupancy limits) and rate plans. `check_in` and `check_out` add each type's restriction reasons. One date without the other is 422.

A Wednesday 2-night stay (2026-02-04 to 2026-02-06, LOW, total 200) sells through the API. The test clock is 2026-02-01 because the published seasons do not cover October 2026 and a claim cannot start in the past.

### Files touched

- `app/Actions/Bookings/CreateStayReservation.php`
- `app/Actions/Bookings/CreateBookingRequest.php`
- `app/Http/Controllers/Rms/BookingController.php`
- `app/Http/Requests/Rms/QuoteReservationRequest.php`
- `app/Http/Requests/Rms/StoreReservationRequest.php`
- `app/Support/Bookings/BookingFormOptions.php`
- `app/Http/Resources/Rms/BookingFormOptionsResource.php`
- `app/Support/Inventory/StaffStayRestrictions.php`
- `app/Support/Commissions/FreezeCommission.php`
- `app/Exceptions/PriceChangedException.php`
- `app/Models/Group.php`
- `database/migrations/2026_10_05_170001_make_groups_departure_id_nullable.php`
- `tests/Feature/Bookings/CreateStayReservationTest.php`

### Deviations

The yacht create and quote path stays in the controller when `check_in` is absent. Existing feature tests and the panel still post `departure_id`. Removing that validation would fail the yacht suite. The stay path does not call `ReservationQuoter` or `LegacyDepartureClaims`. Task 08 deletes the adapter.

The guest block stays `client`, the key `StoreReservationRequest` already requires. The task text said `contact`.

`groups.departure_id` is nullable. A stay group has no departure. Yacht groups still set it.

`booking.total` is the room quote total, the same number the quote endpoint returns as `total`. Price drift compares `expected_total` to that sum.

The booking type stays `CABIN`. There is no stay-specific type in the enum.

A request with a room count other than 1 is 422. A sale allows 1 to `stay.max_rooms_per_booking`.

`ValidatesStay` applies only on the stay branch of `QuoteReservationRequest`. The yacht branch still requires a departure and cabins.

Commission offers still need a departure and a cabin category. A stay passes neither, so stay commission is the agency percent only. `FreezeCommission` already marks room-type pricing as later work.

### Open questions

None.

### Notes for later

Task 07 still sends `departure_id` from the panel. Task 08 removes the yacht create path. Browser scenarios HBKG-01 … HBKG-10 belong with the panel and the sprint close, not this API task. Task 03 owns check-in, check-out, and no-show. Task 06 owns the agency hold queue on stays.

`tests/Feature/Bookings/CreateStayReservationTest.php`: 11 passed. Yacht create, yacht request, stay quote, and form-options tests still pass (38 passed across those files). Larastan reports no errors on the files this task changed. The six pre-existing suite failures listed under task 01 were not re-run and were not changed.

## Task 03 · Check-in, check-out, no-show; night audit

### What was built

Front desk is the only writer of `IN_HOUSE`, `CHECKED_OUT`, and `NO_SHOW`. The generic transition endpoint returns 422 for those targets and names the dedicated route.

`POST /api/rms/bookings/{booking}/check-in` runs `CheckInBooking`. The stay must be on or after the arrival day. The booking must be `FULLY_PAID`, or `CONFIRMED` when `stay.check_in_requires_full_payment` is false. `at` defaults to now. A back-dated `at` must be at or after arrival-day midnight in the property zone and not after now. An optional `room_id` releases the current claims and claims the new active room for the whole stay in the same transaction. One history row, `booking.checked_in`, notes the room. Status becomes `IN_HOUSE` and `checked_in_at` is set.

`POST /api/rms/bookings/{booking}/check-out` runs `CheckOutBooking` from `IN_HOUSE` only. When today is before check-out and after check-in, it is an early departure: `ModifyStay::shorten` credits the unused nights in the same transaction and a reason is required. `checked_out_at` is set and the status becomes `CHECKED_OUT`. A check-out after `stay.check_out_time` on the check-out date is recorded as late and is not charged.

`POST /api/rms/bookings/{booking}/no-show` runs `MarkNoShow` from `CONFIRMED` or `FULLY_PAID`, after the arrival day, or on the arrival day at or after `stay.no_show_cutoff_time`. Nights from check-in + 1 are released. The 0-day cancellation band becomes one `NO_SHOW` price line. No payment row is written. Status becomes `NO_SHOW`.

`POST /api/rms/bookings/{booking}/undo-check-in` is Admin only, same local day as `checked_in_at`, reason required. It restores the status stored on the check-in history and clears `checked_in_at`.

Permission `bookings.front_desk` is granted to Admin, Manager, and Sales Exec. The policy also applies the own-records rule. Undo is `role->isAdmin()`, not that permission.

`iconic:night-audit` replaces `iconic:voyage-status` on the schedule. It runs one minute after `stay.no_show_cutoff_time`. Seeded cutoff 23:59 becomes `0 0 * * *`. It raises three alerts and three front-desk tasks: arrivals not checked in, in house past check-out, departures today not checked out. It writes no booking status. `iconic:voyage-status` remains as an alias that prints a deprecation line and runs the same audit. The class `VoyageStatus` only delegates.

A guest arriving at 02:00 on the arrival day can be checked in. Night audit, run twice, leaves status counts unchanged.

### Files touched

- `app/Actions/Bookings/CheckInBooking.php`
- `app/Actions/Bookings/CheckOutBooking.php`
- `app/Actions/Bookings/MarkNoShow.php`
- `app/Actions/Bookings/UndoCheckIn.php`
- `app/Actions/Bookings/ModifyStay.php`
- `app/Support/Bookings/FrontDeskLock.php`
- `app/Support/Bookings/Transitions.php`
- `app/Support/Stays/StayClock.php`
- `app/Support/Operations/NightAudit.php`
- `app/Support/Operations/VoyageStatus.php`
- `app/Support/Schedule/IconicSchedule.php`
- `app/Console/Commands/NightAuditCommand.php`
- `app/Console/Commands/VoyageStatusCommand.php`
- `app/Http/Controllers/Rms/BookingController.php`
- `app/Http/Requests/Rms/CheckInBookingRequest.php`
- `app/Http/Requests/Rms/CheckOutBookingRequest.php`
- `app/Http/Requests/Rms/MarkNoShowRequest.php`
- `app/Http/Requests/Rms/UndoCheckInRequest.php`
- `app/Policies/BookingPolicy.php`
- `app/Enums/Permission.php`
- `app/Enums/SystemRole.php`
- `app/Enums/ReleaseReason.php`
- `app/Enums/AlertKind.php`
- `app/Enums/TaskKind.php`
- `app/Support/Alerts/AlertKeys.php`
- `app/Support/Alerts/AlertRegistry.php`
- `app/Support/Automations/AutomationCatalogue.php`
- `app/Listeners/SendOnBookingStatusChanged.php`
- `app/Support/Crm/TaskSweep.php`
- `routes/api/rms.php`
- `database/migrations/2026_10_05_180001_grant_front_desk.php`
- `tests/Feature/Bookings/FrontDeskTest.php`
- `tests/Feature/Bookings/TransitionBookingTest.php`
- `tests/Unit/Support/Bookings/TransitionsTest.php`
- `tests/Feature/Operations/VoyageStatusTest.php`
- `tests/Feature/GuestExperience/NpsTest.php`
- `tests/Feature/Crm/SyncJobsTest.php`
- `tests/Unit/Enums/SystemRoleTest.php`
- `tests/Feature/Alerts/AlertsTest.php`
- `tests/e2e/scenarios/crm/CRM-09-sync-jobs-retry.md`
- `tests/e2e/fixtures/reference-values.md`

### Deviations

`ModifyStay` is shorten-only. Task 04 owns extend, shift, room-type change, the preview routes, penalty waiver, and reopening `FULLY_PAID`. Early departure calls `ModifyStay::shorten` inside the checkout transaction. Removed nights are credited at the sold `night_lines` total. The price line is `EARLY_DEPARTURE` with a negative amount. History is `booking.stay_shortened` and then `booking.checked_out`. The release reason is `RELEASED`.

Early departure on the arrival day is 422: "Early departure on the arrival day would leave no night." A stay cannot have zero nights. The credit path is the middle day of a two-night stay.

The no-show charge is `Rounding::halfUp(total * band0.penalty_pct / 100)`. Seed bands are 120 days at 5%, 90 days at 50%, and 0 days at 100%, so the charge equals the room total. `price_lines` is replaced by one `{code: NO_SHOW, label: No-show charge, amount: charge}` and `total` is set to that charge. `night_lines` stay frozen. The old price lines remain only in history as the previous total, not as the line list. No `Payment` row is written. Release reason is `ReleaseReason::NoShow` (`NO_SHOW`). A one-night stay has nothing to release, because check-in + 1 is already the check-out.

A room change on check-in releases every claim with `ReleaseReason::Moved`, claims the new active room at the same property for the whole stay, and updates `room_id` and `room_type_id`. It does not reprice and does not write `booking.moved`. A night already in the past cannot be claimed, so the test moves the room on the arrival day.

Check-in and check-out `at` must be at or after local midnight of the arrival day and at or before now. Late check-out is `at` after `StayClock::checkOutMoment`. The history text is "Checked out · late check-out recorded, not charged".

`UndoCheckIn` reads the previous status from the latest `booking.checked_in` history `before.status`. It does not move the room back. The same-day rule uses the property zone.

A stay booking has a null `departure_id`. `BookingMutationLock` would lock departure 0. `FrontDeskLock` locks the booking row when `departure_id` is null, and otherwise uses `BookingMutationLock`.

Night audit does not raise the old confirmed-at-departure alert. The three new `AlertKind` values share one `TaskKind::FrontDesk`. Tasks are due at `BusinessTime::now()`. The audience permission is `bookings.front_desk`. Raise is idempotent on the existing alert and task keys.

The schedule time is read from `CurrentConfig` when `IconicSchedule::register` runs. If the database is not ready, it falls back to `BusinessRulesDocument::initial()['stay']['no_show_cutoff_time']`. One minute is added as schedule slack. `alreadyRegistered` means the first boot wins. `iconic:voyage-status` warns that it is deprecated and then runs `NightAudit`. `VoyageStatus::ACTOR` is kept.

Sales Exec is granted `bookings.front_desk` in `SystemRole` and in the migration, with `TODO(OPEN: 19-03)`. Admin `isAdmin()` already allows every permission. The migration still adds the permission to the Admin role JSON. Admin `defaultPermissions()` stays empty.

`SystemRoleTest` expected lists now match the enum, including the pre-existing `bookings.override_restrictions` and `inventory.manage_restrictions` plus front desk. That assertion was one of the red checks listed under task 01.

`dateGuardAllows` now always returns true. Front-desk date checks live on the desk actions. `Transitions::illegalMessage` appends the endpoint pointer.

Cruise invoice snapshots need a departure. `SendOnBookingStatusChanged` returns without issuing documents when `departure_id` is null. `TODO(OPEN: 19-03)` marks hotel documents as not issued on these transitions. `TaskSweep::raisePostTripCall` still uses the departure return date and returns when `departure_id` is null. `TODO(OPEN: 19-05)` leaves that clock to task 05. Yacht NPS tests now check in and check out through the desk actions instead of `iconic:voyage-status`.

`NO_SHOW` still reports `holdsInventory()`, so the waitlist listener does not offer the released nights. The nights themselves are released.

### Open questions

Sales Exec front desk is granted pending client confirmation (`TODO(OPEN: 19-03)`).

### Notes for later

Task 04 expands `ModifyStay`. Task 05 should measure the post-trip call from check-out. Task 07 is the panel front desk. HBKG browser scenarios stay with the panel and the sprint close. The night-audit clock is fixed at process boot. Hotel document snapshots are not built here.

`tests/Feature/Bookings/FrontDeskTest.php`: 9 passed. `tests/Feature/Operations/VoyageStatusTest.php`: 2 passed. Transition, schedule, NPS, document-trigger, and alert-kind tests that this task changed also passed (73 passed in that batch after the alert count was set to 16). Larastan reports no errors on the files this task changed. The full suite was not re-run. The other pre-existing failures listed under task 01 were not changed.

## Task 04 · Modify a stay and move a room

### What was built

`ModifyStay` extends, shortens, shifts, or reprices a stay in one transaction. It locks through `FrontDeskLock`. Added nights are claimed on the current room when they are free. When they are not, and the caller did not pin a room, and the stay has not started, the action moves the whole stay onto the first other active room of the same type that is free for every night of the new stay. Any night still unavailable is a 409 and the booking is unchanged. Removed nights are released.

Sold `night_lines` stay on nights that remain. Added nights are priced one night at a time on the current rates version and the booking's plan, each line storing `rates_version_id`. The price summary is rebuilt from the kept and added night lines. Removed nights drop out of that sum, which is the credit. A `modification_penalty` line is the cancellation-band percent of the sold total of the removed nights, using days from today to the booking's current check-in. `modification_fee_usd` is one `modification_fee` line per call when it is greater than zero. `tax_lines` move by the night or money delta only. `total` stays the room total and does not include charged taxes, matching sale.

Length of stay: the band for the new night count applies to the sum of the added nights' pre-discount totals only. A 2-night stay extended to 7 nights (4–11 February, standard double, two adults) is 668: kept 200, added 520, 10 percent of 520 is 52. `TODO(OPEN: HQ8)` asks the client to confirm this rule. A discount that already lived only on the old summary line is not carried forward, because that discount is not inside the frozen night line.

`FULLY_PAID` becomes `CONFIRMED` when the new total is higher. That target is now legal in `Transitions`. The status change is inside the one `booking.stay_modified` history row (before and after stay, room, total, and status) and `BookingStatusChanged` is dispatched. `IN_HOUSE` stays `IN_HOUSE`. When the amount already paid is above the new total, a pending `RefundRequest` is written for paid minus the new total and `RefundRequested` is dispatched. Nothing is refunded. That path also writes `refund.requested`. The in-house extension has no refund, so it is one history entry.

`MoveRoom` keeps the dates and the sold price. `reprice: true` delegates to `ModifyStay`. `POST /api/rms/bookings/{id}/modify/preview` and `POST …/modify` are the stay change. `move` and `move/preview` call `MoveRoom` when `room_id` is sent without `departure_id`. A `departure_id` still uses `MoveBooking`.

`bookings.waive_penalty` is granted to Admin and Manager. The penalty is waived only when `reason_code` is `GUEST_FRIENDLY` and the actor has that permission. Sales Exec can still modify. A stay can be changed while it is requested, pending payment, confirmed, fully paid, or in house.

### Files touched

- `app/Actions/Bookings/ModifyStay.php`
- `app/Actions/Bookings/MoveRoom.php`
- `app/Http/Controllers/Rms/BookingController.php`
- `app/Http/Requests/Rms/PreviewModifyStayRequest.php`
- `app/Http/Requests/Rms/ModifyStayRequest.php`
- `app/Http/Requests/Rms/PreviewMoveBookingRequest.php`
- `app/Http/Requests/Rms/MoveBookingRequest.php`
- `app/Http/Resources/Rms/ModifyStayPreviewResource.php`
- `app/Enums/Permission.php`
- `app/Enums/SystemRole.php`
- `app/Support/Bookings/Transitions.php`
- `database/migrations/2026_10_05_190001_grant_waive_penalty.php`
- `routes/api/rms.php`
- `tests/Feature/Bookings/ModifyStayTest.php`
- `tests/Feature/Bookings/TransitionBookingTest.php`
- `tests/Unit/Enums/SystemRoleTest.php`
- `tests/Unit/Support/Bookings/TransitionsTest.php`

### Deviations

Early departure still uses `ModifyStay::shorten()`. Check-out does not take the cancellation penalty or the modification fee.

A yacht `move` that sends `departure_id` stays on `MoveBooking` until task 08. `confirm_total` is required only with `departure_id`. A room-only move requires `reason` and does not require `confirm_total`.

An in-house stay cannot change rooms. Past nights cannot be claimed again, and a split-room stay is out of scope (H5, HQ4). Same-room extension claims only the added nights.

The overpayment row is created here. It does not call `CreateRefundRequest`, which prices a full cancellation against `booking.total` and would apply a second penalty. `band_source` is `CABIN`. `band_min_days` is stored as 0.

An operational room move does not charge `modification_fee_usd`. The fee applies on `ModifyStay`, including `reprice: true`.

The lock is `FrontDeskLock`. Stay bookings have a null `departure_id`, and that lock already covers them.

### Open questions

`TODO(OPEN: HQ8)` — confirm that the length-of-stay band of the new length applies only to added nights, and that a previous length-of-stay summary line is not kept.

### Notes for later

Task 05 owns the money clock. Task 07 is the panel. Task 08 can delete the yacht move. HBKG browser scenarios stay with the panel and the sprint close.

`tests/Feature/Bookings/ModifyStayTest.php`: 13 passed. Front desk, yacht move, transition, and system-role tests that this task touches also passed. Larastan reports no errors on the files this task changed. The full suite was not re-run.

## Task 05 · Money clock on arrival

### Inventory (before the change)

`grep -n "departure->date\|returnDate()\|departure_id"` on `app/Support/{Payments,Refunds,Commissions,Agencies,Bookings}`, `app/Actions/{Payments,Refunds,Commissions,Bookings}`, and `app/Console`:

| Place | What it used |
|---|---|
| `Support/Payments/PaymentsKpis.php` | departure date in the overdue subquery and the visible-booking date window |
| `Support/Commissions/Accrual.php` | payable date from `returnDate()` |
| `Support/Commissions/CommissionKpis.php` | departure join, filter on departure date |
| `Support/Agencies/AgencyBookingWindow.php` | `departure->date` |
| `Support/Agencies/PortalPreview.php` | `departure->date` written as `departure_date` |
| `Actions/Payments/RecordPayment.php`, `MarkWireReceived.php`, `SettleGatewayPayment.php` | `BookingMutationLock` on `departure_id` |
| `Actions/Refunds/ExecuteRefund.php` | same lock |
| `Actions/Commissions/RecordCommissionPayout.php`, `DecideCommissionCap.php` | same lock |
| `Actions/Bookings/DecideOverdue.php` | due date capped at the departure date, same lock |
| `Console/Commands/NpsSurveyCommand.php` | `returnDate()` |
| `Console/Commands/RetentionCommand.php` | booking `returnDate()`, manifest `returnDate()` |

`Support/Refunds` and `FlagOverdueCommand` had no hits. `FlagOverdue` already uses `Booking::scopeOverdue()`. Balance reminders already use `balanceDueDate()` (task 01).

Left as legacy reads: `BookingMutationLock`, `FrontDeskLock`, and the yacht writers `CreateReservation`, `CreateBookingRequest`, `MoveBooking`, `UpdateBooking`, `DeleteBooking`, `UpdateBookingBilling`, `TransitionBooking`. `CreateStayReservation` writes `departure_id` null.

### What was built

Balance due stays `check_in − balance_days` (override first). Reminders are `payments.balance_reminder_days` before that date. A Thursday arrival on 2026-02-05 with 21 balance days is due 2026-01-15. Seed reminders 21 and 7 fall on 2025-12-25 and 2026-01-08.

`FlagOverdue` and `DecideOverdue` still flag Confirmed and On hold (agency) with a cruise balance past the due date. `DecideOverdue` caps the new due date at arrival. The lock is `FrontDeskLock`. A pay-at-hotel booking (`deposit_pct` 0 and `balance_days` 0) has balance due on check-in and is not overdue before check-out. `isOverdue()` and `overdueDateSql()` both apply that. On the check-out date the flag can be written.

Cancellation days run from today to check-in. `CancellationBands` picks the rate plan's cancellation set, else `CHARTER` or `STANDARD`. Yacht `band_source` stays CHARTER or CABIN. `ModifyStay::penaltyPct()` uses the same set.

Commission payable date is `check_out + commission.payable_days_after_check_out`. Migration `2026_10_05_200001_rename_commission_payable_days` republishes the business rules with the same number (30). `fromArray()` still reads the old key. Accrual is Payable only for `CHECKED_OUT` once that date has arrived. A no-show stays `EarnedOnCompletion`. `TODO(OPEN: 19-05)` — the rules do not say whether a collected no-show charge accrues commission.

`AgencyBookingWindow` filters on check-in. Portal preview still sends the JSON key `departure_date`; the value is check-in.

NPS due is `StayClock::postStayAt`: `checked_out_at` when set, otherwise check-out at `stay.check_out_time`, plus `nps.survey_hours_after_return`. That key is not renamed. Retention of a booking uses `stay()->checkOut()`. A manifest uses the departure stay's check-out, which equals the old return date. Retention keys are not renamed.

### Short lead

No code sets the balance due date to creation plus `payments.wire_window_hours`. The formula was left as it is. When `check_in − balance_days` is already past, the outstanding amount is the full total and the booking is overdue. An `AwaitingWire` payment still ends at `created_at` plus the wire window (72 hours in the seed).

### Yacht values

A backfilled yacht stay copies check-in from the departure date and check-out from `returnDate()`. Commission payable for departure 2027-11-14 stays 2027-12-21. Refund days-before for the existing fixture stays 484.

### Files touched

- `app/Models/Booking.php`
- `app/Support/Stays/StayClock.php`
- `app/Support/Payments/CancellationBands.php`
- `app/Support/Payments/PaymentsKpis.php`
- `app/Support/Commissions/Accrual.php`
- `app/Support/Commissions/CommissionKpis.php`
- `app/Support/Agencies/AgencyBookingWindow.php`
- `app/Support/Agencies/PortalPreview.php`
- `app/Support/Config/Documents/CommissionRules.php`
- `app/Support/Config/Documents/BusinessRulesDocument.php`
- `app/Support/BusinessRules/Registry.php`
- `app/Support/GuestExperience/NpsDashboard.php`
- `app/Support/Reports/ReportQueries.php`
- `app/Support/Metrics/CommercialMetrics.php`
- `app/Actions/Bookings/DecideOverdue.php`
- `app/Actions/Bookings/ModifyStay.php`
- `app/Actions/Payments/RecordPayment.php`
- `app/Actions/Payments/MarkWireReceived.php`
- `app/Actions/Payments/SettleGatewayPayment.php`
- `app/Actions/Refunds/CreateRefundRequest.php`
- `app/Actions/Refunds/ExecuteRefund.php`
- `app/Actions/Commissions/RecordCommissionPayout.php`
- `app/Actions/Commissions/DecideCommissionCap.php`
- `app/Actions/Agencies/RegisterAgency.php`
- `app/Http/Controllers/Rms/AgencyController.php`
- `app/Http/Resources/Rms/BusinessRulesCurrentResource.php`
- `app/Http/Resources/Rms/ConfigVersionDetailResource.php`
- `app/Console/Commands/NpsSurveyCommand.php`
- `app/Console/Commands/RetentionCommand.php`
- `database/migrations/2026_10_05_200001_rename_commission_payable_days.php`
- `tests/Feature/Bookings/MoneyClockTest.php`
- `tests/Feature/GuestExperience/NpsTest.php`
- `tests/Feature/Payments/PaymentIndexTest.php`
- `tests/Feature/Config/BusinessRulesDocumentTest.php`
- `tests/Feature/Config/BusinessRulesSeederTest.php`
- `tests/e2e/fixtures/reference-values.md`

### Deviations

Only the commission config key is renamed. NPS and retention keys stay. Their date sources moved.

The portal JSON key `departure_date` stays so the portal schema does not break. The value is check-in.

`ReportQueries` and `CommercialMetrics` still join departures. The property name on the commission rule changed. A stay-only booking drops out of those reports.

### Open questions

`TODO(OPEN: 19-05)` — does a collected no-show charge accrue commission? Until that is answered, `NO_SHOW` does not become Payable.

### Notes for later

09 H10 also renames `nps.survey_hours_after_return`, the retention `*_after_cruise` keys, and the document clocks. Sprint 21 already owns the NPS key rename. `TaskSweep` still measures the post-trip call from the departure return date (`TODO(OPEN: 19-05)` from task 03).

`tests/Feature/Bookings/MoneyClockTest.php`: 5 passed. Commission accrual, overdue, NPS, retention, payment index, modify-stay, agency portal, and refund-creation tests that this task touches also passed. Larastan reports no errors on the application files this task changed. The full suite was not re-run.

## Task 06 · Agency holds and the request queue on stays

### What was built

A stay request already claims room-nights through `ClaimService` (`HoldType::Request`). Confirming or releasing that stay no longer goes through `LegacyDepartureClaims`. The lock is `FrontDeskLock`. An over-cap stay is `ON_HOLD_AGENCY` with `ClaimKind::Booking` room-nights. Releasing it frees those nights through `ClaimService`. That status is not a timed hold, so the expire job does not touch it.

A stay request hold still expires in the same transaction as `HoldExpired`. The request stays `REQUESTED`. Confirm converts a live hold. After expiry, confirm re-claims the original room when it is free for every night and the stay restrictions still pass. If that room is gone and another active room of the type is free, `POST /api/rms/requests/{id}/confirm/preview` offers it. Confirm does not take that room unless the body sends its `room_id`.

The request queue sorts by `check_in`, then id. `from` and `to` filter `check_in`. SLA fields and `meta.rules` are unchanged. Each row has `stay`, `nights`, `room_type`, `rooms_count`, and `copy`. It does not send `departure` or `cabin_label`. `RequestSummary::line` is `2 rooms · Thu 5 – Sun 8 Mar 2026 · 3 nights` (5 March 2026 is a Thursday; 5 March 2028 is a Sunday).

### Files touched

- `app/Support/Bookings/RequestSummary.php`
- `app/Support/Bookings/ConfirmRequestRooms.php`
- `app/Actions/Bookings/TransitionBooking.php`
- `app/Http/Controllers/Rms/RequestController.php`
- `app/Http/Requests/Rms/ConfirmRequestRequest.php`
- `app/Http/Resources/Rms/BookingRequestResource.php`
- `app/Http/Resources/Rms/ConfirmRequestPreviewResource.php`
- `routes/api/rms.php`
- `tests/Feature/Bookings/StayHoldsTest.php`
- `tests/e2e/scenarios/bookings/BKG-09-request-queue.md`
- `tests/e2e/scenarios/bookings/BKG-10-expired-request-hold.md`

### Deviations

Yacht request create, yacht confirm, and yacht sale still call `LegacyDepartureClaims`. Task 08 deletes that adapter. A yacht confirm after expiry still re-claims the same cabin or returns 409. It does not offer another cabin.

`ON_HOLD_AGENCY` stays a sold booking claim. It is created and released on a stay. It is not expired or converted by the hold clock.

The sample year in the task is 2028. That 5 March is a Sunday, so the copy test uses 2026, when 5 March is a Thursday and 8 March is a Sunday.

### Open questions

None.

### Notes for later

The panel queue still reads `departure` and `cabin_label`. Task 07 has to render `stay`, `nights`, `room_type`, `rooms_count`, and the confirm preview. Until then, `/rms/reservations/booking-requests` errors. BKG-09 and BKG-10 note that. Other scenarios that only open the queue (WEB-07, CRM-06, PREQ-01, PREQ-02) hit the same page.

`tests/Feature/Bookings/StayHoldsTest.php`: 5 passed. Request queue, hold expiry, transition, and stay-create tests also passed. Larastan reports no errors on the application files this task changed. The full suite was not re-run.

## Task 07 · Panel: bookings and front desk

### What was built

Staff create a stay from the bookings page. The form uses `AnkStayInput`, then one or more rooms (type, adults, child ages, rate plan, optional room that is free for the whole stay). Dates can differ per room. The quote is the server quote: night lines, taxes, deposit, and `expected_total`. Restriction failures are listed. Override is a permission plus a reason. A group name is sent when there is more than one room.

The bookings list columns are reference, guest, stay (dates and a nights pill), room, type, status, and balance due. Filters are arriving between, in house on, departing between, status, owner, and channel. Status labels for the three stay statuses are "In house", "Checked out", and "No-show".

The booking drawer shows Check in, Check out, No-show, Modify stay, and Move room only when `allowed_actions` from the API includes that key. Modify stay is preview, then confirm with a reason. Night lines are on the overview. History was already a tab.

Reservations → Front desk is a date (default today) with Arrivals, In house, and Departures, each with a count, expected arrival time, balance due, room, and check-in when the API allows it. Open night-audit alerts for arrivals and departures are listed. A calendar bar opens the same booking drawer.

The request queue column shows the stay `copy` line. It no longer reads `departure` or `cabin_label`.

### Files touched

API, because the panel cannot list a stay or know which buttons are legal without them:

- `app/Support/Bookings/FrontDeskActions.php`
- `app/Http/Resources/Rms/BookingResource.php`
- `app/Http/Controllers/Rms/BookingController.php`
- `app/Http/Requests/Rms/IndexBookingsRequest.php`
- `app/Models/Booking.php`
- `app/Models/Room.php`
- `app/Http/Controllers/Rms/RoomController.php`
- `app/Http/Requests/Rms/IndexRoomsRequest.php`
- `app/Support/Bookings/BookingFormOptions.php`
- `app/Http/Resources/Rms/BookingFormOptionsResource.php`
- `app/Http/Resources/Rms/GroupResource.php`
- `tests/Feature/Bookings/CreateStayReservationTest.php`

Panel:

- `app/components/bookings/stayBooking.ts`
- `app/components/bookings/StayReservationModal.vue`
- `app/components/bookings/StayDeskActions.vue`
- `app/components/bookings/bookingHelpers.ts`
- `app/components/bookings/BookingPanel.vue`
- `app/components/calendar/NightBookingDrawer.vue`
- `app/pages/rms/reservations/bookings.vue`
- `app/pages/rms/reservations/front-desk.vue`
- `app/pages/rms/reservations/booking-requests.vue`
- `app/layouts/default.vue`
- `app/navigation/rms.ts`
- `i18n/locales/en.json`
- `tests/unit/stayBooking.test.ts`
- `tests/unit/bookingHelpers.test.ts`

Types:

- `iconic-ui/app/types/bookings.ts`

Scenarios:

- `tests/e2e/scenarios/bookings/BKG-09-request-queue.md`
- `tests/e2e/scenarios/bookings/BKG-10-expired-request-hold.md`

### Deviations

The task repo is the panel. The bookings index inner-joined `departures`, so a stay never appeared. The index now filters `check_in` / `check_out` and orders by `check_in`. `from` and `to` on that index are arrival dates. Yacht check-in is the departure date, so the old window still matches a yacht booking. Contacts-in and documents still use `departingBetween` on `departures.date`.

`allowed_actions` is new on `BookingResource`: `check_in`, `check_out`, `no_show`, `modify_stay`, `move_room`. The panel does not recompute those rules. Form options now include stay bounds (`min_nights`, `max_nights`, `max_rooms_per_booking`, `booking_horizon_days`) so the calendar is not a hard-coded length. `GET /api/rms/properties/{id}/rooms?free_from&free_to` omits rooms with an active claim on any night of `[check_in, check_out)`. A group with no departure serialises `departure: null`.

The header New reservation button opens the stay modal. The yacht `NewReservationModal` remains for the occupancy calendar.

Confirm-after-expiry still does not show the alternative room in the panel. The queue column no longer crashes.

### Open questions

None.

### Notes for later

Task 08 still owns the yacht adapter and the HBKG browser scenarios. The panel confirm action does not call `POST /api/rms/requests/{id}/confirm/preview`.

`CreateStayReservationTest` index, free-room, and form-options assertions passed. `BookingReadTest` passed. Panel `stayBooking` and `bookingHelpers` unit tests passed. Larastan reports no errors on the application files this task changed. The full Pest suite was not re-run. Panel `nuxt typecheck` still fails on existing `yacht` fields and two CRM stage selects; the files this task added are not in that list.

The browser opened the panel login. The sign-in request to `http://localhost:8000/sanctum/csrf-cookie` failed with no response, so the create → check-in → check-out path was not clicked through.

## Task 08 · Switch seed mode, delete the adapter, sprint close

### What was built

`HotelSeeder` now seeds the fixture restrictions, then the fixture bookings, through the stay actions. `CreateStayReservation` sells or requests each row. Confirmed rows are transitioned. Fully paid, in-house, and checked-out rows get a settled balance payment for the charge total. In-house rows are checked in. Checked-out rows (`COMPLETED` in the fixture) are checked in and then checked out. Cancelled rows are cancelled, which releases the nights. `ON_BOARD` is in house. `COMPLETED` is checked out. The clock is moved to each check-in so a past stay can be claimed, then restored. Create history is System because the seeder does not authenticate. Check-in, check-out, and the payment action record Carolina, the actor those actions require.

GRP-001 is one sale of three rooms with different dates. Each booking's `reference` (or `request_reference` for a request) is then set to the fixture id (`HTL-001` …).

The default seed mode is `hotel` in `config/iconic.php`, `.env.example`, `.env.testing.example`, `tests/e2e/environment/api.env`, and `tests/e2e/environment/api.testing.env`. `yacht` still runs the yacht seeders.

`LegacyDepartureClaims` is deleted. Yacht claim sites call `ClaimService` with the departure's stay dates and still lock the departure row. `CabinConflict` rebuilds the cabin 409 payload those screens already read.

HBKG-01 … HBKG-10 are in `tests/e2e/scenarios/hotel/`. BKG-01 … BKG-12 are retired. `LEDGER.md` records the P1 run.

### Files touched

- `database/seeders/HotelSeeder.php`
- `database/seeders/SeedHotelBookings.php`
- `database/seeders/DatabaseSeeder.php`
- `database/seeders/DemoBookingsSeeder.php`
- `database/seeders/DemoAgenciesSeeder.php`
- `app/Services/Inventory/LegacyDepartureClaims.php` (deleted)
- `app/Support/Inventory/CabinConflict.php`
- `app/Actions/Bookings/CreateReservation.php`
- `app/Actions/Bookings/CreateBookingRequest.php`
- `app/Actions/Bookings/MoveBooking.php`
- `app/Actions/Bookings/TransitionBooking.php`
- `app/Actions/Checkout/CreateCheckoutSession.php`
- `config/iconic.php`
- `.env.example`
- `.env.testing.example`
- `tests/e2e/environment/api.env`
- `tests/e2e/environment/api.testing.env`
- `README.md`
- `tests/Feature/Hotels/HotelFixtureTest.php`
- tests that called the adapter (claim argument is now the stay)
- `tests/e2e/scenarios/hotel/HBKG-01` … `HBKG-10`
- `tests/e2e/scenarios/hotel/HINV-01-timeline-shows-the-hotel.md`
- `tests/e2e/scenarios/hotel/HRATE-01-price-check-reference-stays.md`
- `tests/e2e/scenarios/bookings/BKG-*.md`
- `tests/e2e/scenarios/INDEX.md`
- `tests/e2e/runs/LEDGER.md`
- `tests/e2e/runs/2026-10-05-2155-sprint-19-p1.md`
- `docs/sprints/sprint-19/README.md`

### Deviations

HTL-026, HTL-027, HTL-028, HTL-029, and HTL-030 are not seeded. Their nights fall in the gap between High (`2026-09-30`) and Peak (`2026-12-20`). `StayQuoter` returns no rate. Inventing a season would invent a price. The seed writes 25 bookings, all with `departure_id` null.

The fixture gives a child count and no ages. Each child is seeded at age 8, which is inside the published child ages. The fixture has no channel, so sales use D2C / Phone. The fixture has no payment rows. A fully paid, in-house, or checked-out status is reached by recording one settled balance for the charge total.

The action draws `ANK-…` and the seeder then writes `HTL-…`. The create history line still shows the generated reference.

Config is published before room types exist, so the first rates version has no room rates. The hotel seed publishes a second rates version once the types exist (`Sprint 19: hotel room rates after room types exist`).

Yacht create, request, move, checkout, portal, charter, and the yacht demo seeders still set `departure_id`. `09` H19 drops that column in Sprint 22, and this task keeps yacht seed mode until then. The hotel path does not set it. `CabinConflict` is the old 409 shape, not a second claim writer.

P1 was not walked. `iconic-e2e` is down, and `up.sh` would replace a developer `.env`. Same ENV close as sprint 18.

### Open questions

None.

### Notes for later

Sprint 22 deletes yacht seed mode, `departure_id` on create, and `CabinConflict`. The five unseeded fixture rows need a season before they can be sold. The panel confirm action still does not call the confirm preview.

`the hotel seeder is idempotent and bookings have no departure` passed (16 assertions), including a second seed. `hotel seed mode writes the hotel and skips the yacht inventory` passed. `a taken cabin is 409` and the departure-lock test passed. Larastan is clean on the files this task added. The full Pest suite was not re-run.

### Sprint close

Sprint 19 is closed. Do not commit from the agent. Suggested commands, from each repo that has changes:

```bash
git status
git diff
git add -A
git commit -m "Sprint 19: bookings on stays, hotel seed, drop the departure claim adapter."
```

Review the diff before `git add`. iconic-api, iconic-ui, and iconic-panel all have sprint 19 work.
