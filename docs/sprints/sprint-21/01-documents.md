# 21-01 — Documents on stays

**Repo:** iconic-api
**Depends on:** Sprint 19
**Read first:** 09 H10; `Support/Documents/DocumentPlan.php`, `Snapshots/DocumentFacts.php`, `VoucherSnapshot.php`, `PretripSnapshot.php`, `Enums/DocumentKind.php`, `DocumentPlanKind.php`, `resources/views` document templates, `DocumentsDueCommand.php`, business rules `documents`

## Build
1. `DocumentFacts`: stay facts — property name/address/phone, room type, room (if allocated and the plan says show), check-in/out dates with standard times ("from 15:00", "until 11:00"), nights, party per room, rate plan name, meal plan, night breakdown (summarised by season), taxes (charged and informational), cancellation terms rendered from the plan's band set. Remove itinerary/day-plan facts.
2. `PretripSnapshot` → `PreArrivalSnapshot` (`DocumentKind::PreArrival`, keep the old enum value readable for issued documents — never rewrite issued documents). Config migration renames `documents.pretrip_days_before` → `pre_arrival_days_before` (same value).
3. Voucher: the yacht variant that used `departure->date->subDay()` (embarkation the day before) is removed; voucher date = `check_in`.
4. `DocumentPlan`: due dates from `check_in`; regenerate plan on `ModifyStay` (new event `StayModified` dispatched after commit by `ModifyStay`).
5. Templates: wording reviewed for hotels; legal copy placeholders (`LEG-001`) untouched.
6. Issued documents stay immutable; re-issue after a stay change is a new document (existing behaviour).

## Tests
Snapshot tests for each kind on a fixture stay with two seasons and city tax; plan dates; regenerate on modify.

## Done when
No document template mentions embarkation, itinerary, cabin or yacht.
