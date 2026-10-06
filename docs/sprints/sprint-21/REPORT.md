# Sprint 21 report

## 21-01 — Documents on stays

Confirmations, summaries, vouchers and the pre-arrival note describe the stay. Due dates are measured from check-in. An issued document is left as it was; a stay change that moves the money issues a new one.

`DocumentFacts` now builds stay facts: property name, address and phone, room type, the allocated room, check-in and check-out with the times from stay rules, nights, the party, the rate plan name, the meal-plan code, nights grouped by season, charged and informational taxes, and cancellation terms from the rate plan's band set. Itinerary and day-plan facts are gone. `PretripSnapshot` is `PreArrivalSnapshot` (`DocumentKind::PreArrival`). `DocumentKind::Pretrip` still casts an already issued document and is never rewritten. The voucher date is the check-in date.

`ModifyStay` (including a shorten) dispatches `StayModified` after commit. The plan is derived from check-in, so the pre-arrival and voucher dates follow the new arrival. Migration `2026_10_06_110001_rename_pretrip_days_before` renames `documents.pretrip_days_before` to `documents.pre_arrival_days_before` and keeps the number (`Sprint 21: pretrip_days_before renamed (09 H10)`).

### Files

- Snapshots and plan: `DocumentFacts`, `PreArrivalSnapshot`, `VoucherSnapshot`, `InvoiceSnapshot`, `SummarySnapshot`, `SnapshotFactory`, `DocumentPlan`, `DeliveryKey`, `DocumentsDueCommand`, `DocumentCheck`
- Kinds and mail: `DocumentKind`, `DocumentPlanKind`, `DeliveryKind`, `DocumentView`, `DocumentMail`, `DeliverySubject`, `AutomationCatalogue`
- Stay change: `StayModified`, `ModifyStay`, `EventCatalogue`
- Config: `DocumentsRules`, `BusinessRulesDocument`, `Registry`, `ConfigPublisher` (dependent rules follow the wrapped document), `JourneyClock`, `DepartureGuestExperience`, the rename migration
- Templates: invoice, summary, voucher, pre-arrival, fees, schedule, totals, stay partial, head, footer, proof, and the document mails. `partials/cruise.blade.php` is removed
- Tests: `StayDocumentSnapshotTest`, document, config and automation tests, regenerated HTML snapshots
- E2E: DOC-01, DOC-02, DOC-05, DOC-06

### Deviations

- Rate plans have no "show room" flag (09 H8). The room is printed when one is allocated.
- The meal plan is the stored code (`RO`). Labels are still the open item on the rates document.
- Charged taxes are printed. They are not added into `charges_total` (room charges, extras and collected fees).
- `rules()` accept either day key so the sprint 7 migration can still publish. `fromArray` prefers the new key. `toArray` writes only the new key, so a republish stores `pre_arrival_days_before`.
- The automation switch stays `pretrip`. A `PRE_ARRIVAL` delivery uses that switch. The journey rule string `pretrip_days_before` stays; the clock reads `preArrivalDaysBefore`.
- Questionnaire and balance-reminder mails use check-in, so a stay booking does not read a departure.

### Open questions

None.

### Notes for later

- The panel plan row and the CRM documents filter still say `PRETRIP`. An old issued pre-trip document is readable on the booking, and the plan falls back to it, but a kind-matched version list will not show it under `PRE_ARRIVAL` until the panel is updated.
- Regenerate the layer's API types so `PRE_ARRIVAL` is in the generated unions.
- Data-chaser and survey mails still read `departure` (tasks 02 and 03). Manifest templates still say cabin (task 02).

## 21-02 — Retire manifests; front-desk lists and guest registration export

DPNG and captain manifests are no longer produced or chased. The front desk reads one date endpoint. Guest registration is a CSV or PDF whose columns come from business rules.

`registration` sits on the business-rules document: `fields`, `formats`, `deadline_hours_after_check_in`. The published default is the HQ9 set (name, nationality, DOB, document number, arrival, departure), formats CSV and PDF, and no deadline. Migration `2026_10_06_120001_add_registration_to_business_rules` publishes that block as System with approval `Sprint 21: registration added (09 H15)`. A missing key in `fromArray` stays empty, so the migration is not identical to the previous version.

`GET /api/rms/front-desk?date=` returns arrivals, in house, and departures for that date, with the same filters the page used before. `GET /api/rms/front-desk/registration?date=&format=csv|pdf` exports named guests of bookings that occupy that night. Occupying statuses are Confirmed, Fully Paid, In House, Checked Out, and Overdue. Sensitive columns (nationality, date of birth, document number) are omitted without `guests.view_sensitive`. Each export writes `registration.exported` on the property: date, format, counts, and field codes. No guest values.

`iconic:manifests-due` is off the schedule. The command still exists and prints that manifests are retired; it issues nothing and chases nothing. `POST /api/rms/departures/{departure}/manifests/{kind}` returns 410. Index, versions, and file download stay. The data-chaser catalogue row says the job is retired and not scheduled. Manifest registry rows keep their keys and carry a 09 H15 note. Guest registration is a new pending-client row (HQ9). Registry counts are 102 / 77 / 15 / 10 / 56.

Guest issues use `registration.fields` and the stay check-in. A missing field is a warning. It becomes an error only when `deadline_hours_after_check_in` is set and that many hours have passed after check-in midnight in the business timezone. `Guest::isComplete` is unchanged.

The front desk page loads the new list endpoint and offers Export registration (CSV or PDF). Operations navigation says Documents. The generate-manifest section is gone. Existing manifests are listed read-only on the booking history tab when the booking still has a departure.

### Files

- API: `RegistrationField`, `RegistrationRules`, `BusinessRulesDocument`, `Registry`, migration `2026_10_06_120001`, `RegistrationSheet`, `ExportGuestRegistration`, `FrontDeskLists`, `FrontDeskDay`, `FrontDeskController`, the date and export requests, `FrontDeskListsResource`, `routes/api/rms.php`, `GuestIssues`, `ManifestController`, `ManifestsDueCommand`, `IconicSchedule`, `AutomationCatalogue`
- Tests: `FrontDeskRegistrationTest`, `AddRegistrationToBusinessRulesMigrationTest`, `GuestIssuesTest`, `ManifestsTest`, `SyncJobsTest`, `BusinessRulesEndpointsTest`
- Panel: `front-desk.vue`, `documents.vue`, `BookingManifestArchive.vue`, `BookingPanel.vue`, en/es i18n, `guards.test.ts`. `DepartureManifests.vue` and `ManifestVersionsModal.vue` are removed
- E2E: DOC-09, BR-01, CRM-09, GST-01, `reference-values.md`, INDEX

### Deviations

- The guest table has no `document_type` or `address` column. `document_number` is the passport number. `document_type` is `passport` when that number is present, otherwise blank. `address` is always blank, so it is always incomplete if selected. No columns were added.
- The in-house tab is still status In House only. The export is wider: any occupying status whose night contains the date.
- Arrivals and departures still include every status the old booking filters included.
- The command stays callable so a manual run cannot recreate the old job. It is a no-op.
- `fromArray` does not invent the HQ9 defaults for a missing block. `initial()` and the migration do.
- Panel `nuxt typecheck` still fails on pre-existing `yacht` properties outside this task. The files this task touched are clean. Full eslint still fails on three pre-existing unknown classes (`tl-line`, `tl-arrow`, `crm-task-head`).

### Open questions

None. HQ9 names no deadline hour, so the default is null.

### Notes for later

- Manifest PDF and CSV templates, and the data-chaser and survey mails, still speak departure and cabin. This task stopped the schedule and the create endpoint. It did not rewrite those templates.
- Regenerate the layer's API types for `GET /api/rms/front-desk` and the registration download. The page uses a local list type.
- `documents.manifestsTitle` and the other manifest copy in i18n are unused after the generate section was removed.
- `manifestHelpers.ts` and its unit test remain. Nothing in the app imports them.
- The panel login page loads. The export button and the documents page were not clicked through: the browser blocked filling the password field.

## 21-03 — Guest experience: pre-arrival questionnaire, arrivals brief, NPS after check-out

The questionnaire send date is check-in minus `documents.pre_arrival_days_before`. The guest page shows the stay: property name, check-in, check-out. The list is an arrival range. The hotel-manager brief is an arrivals brief for one date. The survey waits `nps.survey_hours_after_check_out` after check-out, and a no-show is never surveyed.

`GET /api/rms/guest-experience?from=&to=` returns bookings whose check-in falls in the range and whose status is Confirmed, On Hold (agency), Fully Paid, In House, or Checked Out. Each guest keeps its own send date. The page-level send date is the earliest of those, or the range start minus the days rule when the range is empty. `GET /api/rms/guest-experience/arrivals?date=&format=html|pdf` prints the arrivals brief: expected arrival time, dietary notes, celebrations, special requests, and room-rhythm tallies. Accessibility is included only with `guests.view_sensitive`. Printing writes `brief.printed` on the property (date, format, booking count) and stores no preference values. `GET /api/rms/guest-experience/departures`, `GET /api/rms/departures/{departure}/guest-experience`, and the hotel-manager brief return 410. Preference read and write, questions, and the NPS endpoints stay.

NPS hours moved from `survey_hours_after_return` to `survey_hours_after_check_out` with the same value. Migration `2026_10_06_130001_rename_survey_hours_after_return` publishes that rename as System with approval `Sprint 21: survey_hours_after_return renamed (09 H10)`. The anchor is `checked_out_at` when set, otherwise check-out on `check_out` at `stay.check_out_time`. `SendSurveys` returns immediately for `NO_SHOW`. The command still selects Checked Out only. Registry counts stay 102 / 77 / 15 / 10 / 56.

The guest-experience page has arrival From and To (default today) and no departure selector. The brief uses the From date. The engine questionnaire subtitle shows the property and the stay.

### Files

- API: `DepartureGuestExperience`, `ArrivalsBrief`, `RecordBriefPrinted`, `GuestExperienceController`, `ArrivalRangeRequest`, `ArrivalsBriefRequest`, `routes/api/rms.php`, `QuestionnairePage`, `QuestionnaireResource`, `brief.blade.php`, `NpsRules`, `BusinessRulesDocument`, `Registry`, `AutomationCatalogue`, `NpsSurveyCommand`, `SendSurveys`, `NpsDashboard`, `NpsViewResource`, migration `2026_10_06_130001`
- Removed: `HotelManagerBrief`, `DepartureList`
- Tests: `GuestExperienceListsTest`, `StaffPreferencesTest`, `NpsTest`, `EngineQuestionnaireTest`, `RenameSurveyHoursAfterReturnMigrationTest`, `BusinessRulesSeederTest`, `BusinessRulesEndpointsTest`, `AddNpsRulesToBusinessRulesMigrationTest`
- Panel: `guest-experience.vue`, `guestExperienceHelpers.ts`, en/es `guestExperience` copy, the eslint class allowlist (`gx-dates` replaces `gx-departure`)
- Engine: `questionnaire/[token].vue` (stay subtitle)

### Deviations

- The list is a range. The brief is one date, the From date, including when To is later.
- Questionnaire JSON still sends `departure_date` and `itinerary_name`. They now carry check-in and the property name, so an older client does not break. `check_in`, `check_out`, and `property_name` are added beside them.
- The survey page and survey mail were left on the departure shape. This task changed the send clock and the no-show rule.
- `NpsRules::toArray` keeps `survey_hours_after_return` when that is the key already stored and the new key is absent. The sprint 8 migration test still sees the old key. A fresh document writes the new key, so the rename migration does not publish a second identical version on a current seed.
- The captain-manifest preference test calls `IssueManifest` directly. `POST` manifests is 410 from 21-02.
- The engine page was updated so the guest sees the stay. The task line names the API and the panel.
- No guest-experience e2e scenario names a departure selector. None was added.

### Open questions

None.

### Notes for later

- `SurveyPage` and the survey mail still speak departure and itinerary. The NPS clock does not.
- Regenerate the layer's API types. The panel reads `survey_hours_after_check_out` through a local widening because the generated facts still name `survey_hours_after_return`.
- The date-range page and the arrivals brief were not clicked through. The panel was down, and the browser blocked filling the password field.

## 21-04 — Alerts and retention on the stay clock

Low occupancy is one alert per run of consecutive nights. Retention waits from check-out. Night-audit alerts are on the alerts kinds list.

For each active property, the next `alerts.low_occupancy_days_before` nights (starting tomorrow) are read one night at a time through `NightAvailability::occupancy`. A night is low when rooms are available and the sold percent is below `low_occupancy_pct`. Nights with nothing available split a run. One `LOW_OCCUPANCY` alert covers each run whose length is at least `alerts.low_occupancy_min_consecutive_nights`. A run that recovers is resolved. The kind already existed, so `LOW_OCCUPANCY_NIGHTS` was not added.

The new minimum is 1. The fixture and 09 H15 name no number. 1 keeps a short run instead of hiding it. Migration `2026_10_06_140001_add_low_occupancy_min_consecutive_nights` publishes that default as System with approval `Sprint 21: low_occupancy_min_consecutive_nights added (default 1, source 09 H15)`. `fromArray` leaves a missing key at 0, so the migration is not identical to the previous version.

`passport_months_after_cruise` is `passport_months_after_check_out` (24). `medical_days_after_cruise` is `medical_days_after_check_out` (90). Migration `2026_10_06_140002_rename_retention_after_cruise` publishes that rename as System with approval `Sprint 21: passport_months_after_cruise and medical_days_after_cruise renamed (09 H10)`. The anchor is the booking check-out. A later check-out is left alone. `--dry-run` still writes nothing. The README warning not to run retention in production until the client confirms the periods stays. `toArray` keeps the old keys when that is what is stored, so the sprint 6 consent migration still sees them.

Night audit already raised `ARRIVAL_NOT_CHECKED_IN`, `IN_HOUSE_PAST_CHECK_OUT`, and `DEPARTURE_NOT_CHECKED_OUT`, and the kinds endpoint already listed all 16 kinds. The kinds test now asserts those three. The check-out kind's label is "Check-out not completed". The confirmed-at-check-in label no longer says departure. The stored enum values are unchanged.

`app/Support/Alerts` and `app/Support/Retention` contain no `departure`. Alert keys are `check-out-still-open:` and `confirmed-on-check-in:`. Migration `2026_10_06_140003_rename_stay_alert_keys` rewrites existing alert and task keys so the next night audit does not raise a second row. Registry counts stay 102 / 77 / 15 / 10 / 56.

### Files

- Occupancy: `OccupancyCheck`, `OccupancyCheckCommand`, `AlertsRules`, migration `2026_10_06_140001`
- Retention: `RetentionRules`, `RetentionCommand`, `ConsentDataMap`, `EraseContact`, migration `2026_10_06_140002`
- Alerts: `AlertKeys`, `AlertRegistry`, `AlertSubject`, `AlertSweep`, `NightAudit`, `AlertKind`, `AutomationCatalogue`, migration `2026_10_06_140003`
- Registry and document: `BusinessRulesDocument`, `Registry`, the two business-rules resources
- Tests: `OccupancyCheckTest`, `RetentionCommandTest`, `VoyageStatusTest`, `AlertsTest`, the two new migration tests, seeder, document, consent, and consent-versions tests
- E2E fixture: `reference-values.md`

### Deviations

- The minimum is 1, with `TODO(OPEN: 09 H15)`. No fixture states another number. A published value of 3 drops a run of two, which the second occupancy test covers.
- An unsold night is 0% and counts as low. A gap is a night at or above the percent, or a night with no rooms available.
- The window is tomorrow through today plus the days rule. That is the same span the old departure check used.
- `RetentionCommand` still reads a manifest's departure to find check-out. A manifest row has no check-out of its own. That command is outside `app/Support/Retention`.
- `CONFIRMED_AT_DEPARTURE` and `DEPARTURE_NOT_CHECKED_OUT` stay as stored values.

### Open questions

None. The consecutive-night minimum is the open default above.

### Notes for later

- Regenerate the layer's API types. `AlertKind` in `api.d.ts` still omits the three night-audit values.
- Manifest file purge should move to check-out once manifests no longer point at a departure (sprint 22).

## 21-05 — CRM: derived stay data, segments, journeys, templates

Contact derived fields, segment filters, journey clocks and template variables read the stay on the booking. No CRM query joins `departures`.

`ContactDerived` computes `last_stay_check_out`, `next_stay_check_in`, `stays_count`, `nights_count` and `last_room_type` from `bookings` (and `room_types` for the room name). `lifetime_value` is still the sum of charges on sold bookings. Sold statuses are unchanged: Confirmed, Fully Paid, In House, Checked Out, Overdue. Last stay is the sold booking with the latest `check_in` on or before today, so an in-house stay counts. Next stay is the earliest sold `check_in` after today. Lifecycle windows use `check_in` and `check_out`. The old `DATE_ADD(departures.date, INTERVAL nights)` SQL is gone.

Segment vocabulary fields `stay_date`, `arrival_weekday`, `length_of_stay`, `room_type` and `rate_plan` replace the departure dimension. They match any non-deleted booking. `guest_age` is age at `bookings.check_in`. `SegmentDimension` is unchanged. 09 does not name new cases. Proposed cases, not added: `STAY_DATE`, `ARRIVAL_WEEKDAY`, `LENGTH_OF_STAY`, `ROOM_TYPE`, `RATE_PLAN`.

`festive_departure_views` is off the public vocabulary. Festive is a date supplement (09 H8) and the old filter needed a departures join. The compiler still accepts the old field and counts `view_departure` events. The seeder and migration `2026_10_06_150001_stay_clock_journey_anchors` rewrite stored festive conditions to `event_count` / `view_departure`. The families sentence says "at arrival".

Journey anchors `arrival` (check-in day) and `check_out` replace `departure` and `return`. The clock still accepts the old names. Extras-due and pre-arrival hours use check-in. Re-engagement shifts from check-out. `JourneyEngine` treats the trip as started when today is on or after `bookings.check_in`. The same migration rewrites stored step anchors and does not touch `change_history`.

Template variables `{{check_in}}`, `{{check_out}}`, `{{nights}}`, `{{room_type}}`, `{{property_name}}`, `{{check_in_time}}` and `{{check_out_time}}` resolve from the stay, the room type, the property and the stay-rule times. `{{departure_date}}` is deprecated and still renders check-in. The journey template editor shows that note while a version lists `departure_date`.

The deal drawer shows check-in, check-out, nights, room type and property, plus the latest `search_performed` and `room_type_viewed` events. Engine activity describes a stay search from those event params and no longer loads `Departure`. The contact drawer shows the stay summary. Pipeline cards, campaign bookings and contact bookings still expose `departure_date`; the value is check-in.

### Files

- Derived: `ContactDerived`, `Contact`, `ContactResource`, `ContactController`, `ContactBookingResource`
- Segments: `SegmentVocabulary`, `SegmentCompiler`, `SegmentsSeeder`, migration `2026_10_06_150001`
- Journeys: `JourneyClock`, `JourneyEngine`, `JourneyStep`, `JourneysSeeder`, the same migration
- Templates: `TemplateVariable`, `TemplateRenderer`
- Surfaces: `DealDrawer`, `DealResource`, `EngineActivity`, `BehaviouralEventDetail`, `ContactTimeline`, `DealStages`, `PipelineBoard`, `CampaignMeasures`, `B2bPartnerController`, `B2bPartnerResource`
- Tests: `ContactDerivedTest`, `StayCrmTest`, `SegmentsTest`, `TemplatesTest`, `EngineActivityTest`
- Panel: `DealDrawer.vue`, `ContactDrawer.vue`, `JourneyTemplatePanel.vue`, `en.json`, `es.json`, patched `iconic-ui/app/types/api.d.ts`

### Deviations

- No new `SegmentDimension` cases. The five stay dimensions are vocabulary fields. Proposed enum cases are listed above.
- The festive segment no longer requires a festive departure. It counts `view_departure`.
- `departure_date` stays as a response key on pipeline cards, campaign bookings, contact bookings and the deal payload. The date is check-in, so an older client still reads a date.
- Manifest journey rules still `loadMissing('departure')`. That is a relation load, not a join, and those rules still need a departure row.
- `{{itinerary_name}}` still loads `departure.itinerary`.
- `TaskSweep` still measures the post-trip call from the departure return date (`TODO(OPEN: 19-05)`).
- B2B partner reads no longer eager-load `bookings.departure`. Stats use the stay columns.
- The journey runner no longer eager-loads `booking.departure`.
- Layer types were patched by hand. Scramble was not regenerated.
- Panel `pnpm typecheck` still fails on `yacht` fields in booking, calendar and departure screens. Those errors predate this task. The CRM stay fields typecheck. ESLint on the three CRM components is clean.

### Open questions

None. The segment enum cases above wait on 09 or the client. Festive nights are not a named stay-date supplement yet.

### Notes for later

- Regenerate the layer's API types so the patched deal, contact and booking fields come from OpenAPI.
- Drop `{{departure_date}}` in Sprint 22, with the editor note.
- Move the post-trip task and the manifest journey rules off `departure` when those yacht rows go.
- CRM-05 and CRM-06 still expect `view_departure`. That event name remains. No scenario text asserted a departure date on the deal or contact drawer.

## 21-06 — Metrics and reports: hotel KPIs

`HotelKpis` measures a night range. Occupancy is room-nights sold divided by room-nights available. Available is free + held + sold. Blocked nights are out, same as `NightAvailability`. ADR is room revenue divided by nights that earned room revenue. RevPAR is room revenue divided by available nights. ADR and RevPAR are integer USD, half-up. Occupancy, cancellation rate, no-show rate and length of stay are one-decimal strings. Lead time is whole days, half-up.

Room revenue is the sum of `night_lines` totals. Taxes and extras are not in that total. A booking with no night lines splits `total` across the stay in integer shares and sets `legacy_room_revenue`. A stay that crosses a month end splits on the night. Pickup counts sold room-nights created in the last N days. N is `reports.pickup_days`, default 7 (`TODO(OPEN: 21-06)`).

`GET /api/rms/hotel-kpis` returns this month, the next 30 nights and the next 90 nights. Each period has on-the-books figures and the same range last year when that range has inventory, revenue or arrivals. Next 90 nights is read in chunks of 62.

The catalogue drops `overdue`, `forecast-30-day`, `revenue-monthly` and `occupancy`. It adds `occupancy-revenue`, `pace` and `arrivals-forecast` (CSV and XLSX). An old run of a retired key still downloads. A new run of a retired key is 404. The weekly subscription now points at `occupancy-revenue`. Cadence and send time are unchanged.

The hand-computed fixture is 2 rooms, 3 nights, one block, one no-show and one cancellation. Result: available 5, sold 3, occupancy 60.0, room revenue 600, ADR 200, RevPAR 120, pickup 3, length of stay 3.0, lead time 2 days, cancellation 33.3, no-show 50.0. October only (two nights): occupancy 66.7, ADR 150, RevPAR 100. A marketing filter keeps available at 5 and sold at 0.

### Files

- Metrics: `HotelKpis`, `HotelKpisController`, `HotelKpisRequest`, `HotelKpisResource`, route `hotel-kpis`
- Config: `ReportsRules`, `BusinessRulesDocument`, `Registry` path `reports.pickup_days`, migration `2026_10_06_160001_add_pickup_days_to_business_rules`
- Reports: `ReportDefinitions`, `ReportQueries`, `ReportController`, `ReportMailer`, migration `2026_10_06_160002_retarget_occupancy_subscription`
- Tests: `HotelKpisTest`, `ReportRunsTest`, `ReportSchedulesTest`
- Panel: `dashboard.vue`, `en.json`, `es.json`, patched `iconic-ui` `HotelKpis` type

### Deviations

- Pickup N is 7. The task names N and no fixture states a number. `reports.pickup_days` is `sometimes` so the sprint 12 retention migration can still publish. `fromArray` fills a missing key with 7. A stored 0 failed `min:1` after the publisher round-trip.
- Unfiltered occupancy sold comes from `NightAvailability`. The ADR divisor is nights that earned room revenue. They match when every sold night has a night line.
- The three dashboard cards are this month's hotel Occupancy, ADR and RevPAR. The departure occupancy table is still the yacht metric.
- `commercial-summary` still uses yacht `CommercialMetrics`. `agency-report` and `commissions-payable` still join `departures`.
- Registry counts stay 102 / 77 / 15 / 10 / 56. `pickup_days` sits on the existing `report-retention` row.
- Layer types were patched by hand. Scramble was not regenerated.
- Panel `pnpm typecheck` still fails on `yacht` fields that predate this task. ESLint on `dashboard.vue` is clean. New hotel types add no error.

### Open questions

- `TODO(OPEN: 21-06)` pickup window length. Default 7 days, max 90.

### Notes for later

- Sprint 22 can drop the retired report query methods and the departure joins in agency and commission reports.
- Regenerate OpenAPI types so `HotelKpisResource` comes from Scramble.
- Local `php artisan migrate` stops on `2026_10_05_125001_add_cancellation_sets` (`document.commission.payable days after check out field is required`). The pickup and subscription migrations are not on this database yet. `bookings.check_in` is missing there, so the live dashboard request fails. The fixture passed on the test database.
- Signed in on the panel. Commercial Dashboard shows "The metrics request failed." `GET /api/rms/hotel-kpis` returns 500, unknown column `check_in`. `GET /api/rms/metrics` returns 500, unknown column `bookings.check_out`. Iconic API is on port 18000. Port 8000 is a different app. The panel process used for this check was started with `NUXT_PUBLIC_API_BASE=http://localhost:18000`. `.env` still says port 8000.

## 21-07 — Sprint close: e2e and report

### What was built

Nine stay scenarios and a P1 record that did not start the stack.

HOPS-01 through HOPS-06 walk the confirmation stay block, the registration export (sensitive columns only for a permitted user), tomorrow's arrivals brief, one low-occupancy alert for a run of nights, the hotel Occupancy / ADR / RevPAR cards, and a one-night stay move that shifts the pre-arrival date. HCRM-01 through HCRM-03 walk last and next stay on the contact, a length-of-stay segment, and the journey step three days before arrival.

DOC-02 now expects the stay, taxes, and cancellation block on the invoice preview. DOC-10 expects the stay-balance reminder and **Pay balance securely**. GST-05 expects the check-out passport warning. GST-01 notes that the guest card still prints `12 yrs on departure`. CRM-03 expects the Stays line. Pay-later walks in JRN-01, JRN-05, PIPE-01, PIPE-03, TASK-01, CAMP-02, CRM-05, and CRM-06 point at HENG-03. `view_departure` stays a valid event name.

`LEDGER.md` records the P1 run. INDEX has 104 P1 rows.

### Files

- `tests/e2e/scenarios/hotel/HOPS-01` … `HOPS-06`
- `tests/e2e/scenarios/crm/HCRM-01` … `HCRM-03`
- `tests/e2e/scenarios/documents/DOC-02`, `DOC-10`
- `tests/e2e/scenarios/guests/GST-01`, `GST-05`
- `tests/e2e/scenarios/crm/CRM-03`, `CRM-05`, `CRM-06`, `JRN-01`, `JRN-05`, `PIPE-01`, `PIPE-03`, `TASK-01`, `CAMP-02`
- `tests/e2e/scenarios/INDEX.md`
- `tests/e2e/runs/LEDGER.md`
- `tests/e2e/runs/2026-10-06-1332-sprint-21-p1.md`

### Deviations

`up.sh` was not started. It copies `tests/e2e/environment/api.env` over `.env`. Ports 8000 and 8001 are `keevaris-api`. The names `iconic-api`, `iconic-mysql`, `iconic-redis`, and `iconic-mailpit` are already in use, and mailpit owns 8025. Iconic API is on 18000. `127.0.0.1:3001` is the panel. Ports 3000, 3002, and 3010 had no listener. Memory available was 9.7 GiB. Detail: `tests/e2e/runs/2026-10-06-1332-sprint-21-p1.md`. 0 passed, 0 failed, 104 not run.

No application code in this task.

### Open questions

None.

### Notes for later

- DOC-10 mail body is the stay wording. Subject is still `Your Iconic balance — due {date}`.
- `EraseContact` still loads `departure` and still refuses with `This contact has a booking whose return date has not passed.` PRIV-04 keeps that sentence. A hotel-only booking with no departure row would error.
- Guest card label is still `{age} yrs on departure`.
- Hotel seed creates no named guests. HOPS-02 adds one on HTL-027 before the export.
- Local `iconic` still has no `bookings.check_in`. A live dashboard walk would 500. Do not treat that as an HOPS-05 failure until migrate finishes.

### Sprint close

Sprint 21 is closed. Do not commit from the agent. Suggested commands, from each repo that has changes:

```bash
git status
git diff
git add -A
git commit -m "Sprint 21: operations, documents and CRM on stays."
```

Review the diff before `git add`. iconic-api, iconic-ui, iconic-panel, and iconic-engine have sprint 21 work.
