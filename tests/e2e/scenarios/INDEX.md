# Scenario catalogue

Pick by **id**, **tag**, **priority**, or **batch**. P1 first. Visual checks are not in this catalogue.

Active rows are hotel scenarios plus generic auth, users-roles, config, and smoke. Yacht scenarios and the older product walks live in [`_archive/`](_archive/) and are not walked.

The latest result for every P1 is [`runs/LEDGER.md`](../runs/LEDGER.md).

| ID | Title | Tags | Priority | Batch | Users | File |
|---|---|---|---|---|---|---|
| SMK-01 | Stack is up | smoke | P1 | B1 | — | [smoke/SMK-01-stack-is-up.md](smoke/SMK-01-stack-is-up.md) |
| SMK-02 | Every demo user can sign in and out | smoke, sprint-1, auth | P1 | B1 | all four | [smoke/SMK-02-demo-users-sign-in-out.md](smoke/SMK-02-demo-users-sign-in-out.md) |
| AUTH-01 | Return to the page you asked for | sprint-1, auth | P1 | B1 | Mateo | [auth/AUTH-01-return-to-asked-page.md](auth/AUTH-01-return-to-asked-page.md) |
| AUTH-02 | Failed sign-ins look identical | sprint-1, auth | P2 | — | — | [auth/AUTH-02-failed-sign-ins.md](auth/AUTH-02-failed-sign-ins.md) |
| AUTH-03 | Forgot and reset a password | sprint-1, auth | P1 | B1 | Carolina | [auth/AUTH-03-forgot-reset-password.md](auth/AUTH-03-forgot-reset-password.md) |
| AUTH-04 | Accept an invitation | sprint-1, auth | P1 | B1 | Carolina + new | [auth/AUTH-04-accept-invitation.md](auth/AUTH-04-accept-invitation.md) |
| AUTH-05 | Accept while signed in as someone else | sprint-1, auth | P2 | — | Carolina + new | [auth/AUTH-05-accept-while-signed-in.md](auth/AUTH-05-accept-while-signed-in.md) |
| AUTH-06 | A disabled user is signed out on their next action | sprint-1, auth | P2 | — | Carolina, Lucía | [auth/AUTH-06-disabled-user-signed-out.md](auth/AUTH-06-disabled-user-signed-out.md) |
| AUTH-07 | Redirects stay inside the panel | sprint-1, auth | P2 | — | Mateo | [auth/AUTH-07-redirects-stay-inside.md](auth/AUTH-07-redirects-stay-inside.md) |
| AUTH-08 | Section access | sprint-1, auth | P1 | B1 | CFO, Mateo, Lucía | [auth/AUTH-08-section-access.md](auth/AUTH-08-section-access.md) |
| USR-01 | Invite, edit, disable, enable, resend | sprint-1, users-roles | P2 | — | Carolina | [users-roles/USR-01-invite-edit-disable-enable-resend.md](users-roles/USR-01-invite-edit-disable-enable-resend.md) |
| USR-02 | Your own row | sprint-1, users-roles | P2 | — | Carolina | [users-roles/USR-02-own-row.md](users-roles/USR-02-own-row.md) |
| USR-03 | No privilege escalation from a limited role | sprint-1, users-roles | P2 | — | Carolina + limited | [users-roles/USR-03-no-privilege-escalation.md](users-roles/USR-03-no-privilege-escalation.md) |
| ROLE-01 | Grant, use, revert a permission | sprint-1, users-roles | P1 | B1 | Carolina, Lucía | [users-roles/ROLE-01-grant-use-revert.md](users-roles/ROLE-01-grant-use-revert.md) |
| ROLE-02 | Own only vs any | sprint-1, users-roles | P2 | — | Carolina | [users-roles/ROLE-02-own-only-vs-any.md](users-roles/ROLE-02-own-only-vs-any.md) |
| ROLE-03 | Custom role lifecycle | sprint-1, users-roles | P2 | — | Carolina | [users-roles/ROLE-03-custom-role-lifecycle.md](users-roles/ROLE-03-custom-role-lifecycle.md) |
| ROLE-04 | Unsaved matrix changes | sprint-1, users-roles | P2 | — | Carolina | [users-roles/ROLE-04-unsaved-matrix.md](users-roles/ROLE-04-unsaved-matrix.md) |
| ROLE-05 | Read-only matrix | sprint-1, users-roles | P2 | — | Carolina + limited | [users-roles/ROLE-05-read-only-matrix.md](users-roles/ROLE-05-read-only-matrix.md) |
| RATE-01 | Rates read-only | sprint-2, config | P2 | — | Lucía | [config/RATE-01-rates-read-only.md](config/RATE-01-rates-read-only.md) |
| ENG-01 | Manager publishes copy without a reference | sprint-2, config | P1 | B2 | Mateo | [config/ENG-01-manager-copy-no-reference.md](config/ENG-01-manager-copy-no-reference.md) |
| ENG-02 | Manager can't touch rules | sprint-2, config | P1 | B2 | Mateo | [config/ENG-02-manager-rules-locked.md](config/ENG-02-manager-rules-locked.md) |
| ENG-03 | Rule change needs a reference | sprint-2, config | P2 | — | Carolina | [config/ENG-03-rule-change-needs-reference.md](config/ENG-03-rule-change-needs-reference.md) |
| ENG-05 | Engine settings read-only | sprint-2, config | P2 | — | Lucía | [config/ENG-05-engine-read-only.md](config/ENG-05-engine-read-only.md) |
| BR-01 | Fresh-seed registry | sprint-2, config | P1 | B2 | Carolina | [config/BR-01-fresh-seed-registry.md](config/BR-01-fresh-seed-registry.md) |
| BR-02 | A differing value, reset, publish | sprint-2, config | P1 | B2 | Carolina | [config/BR-02-differ-reset-publish.md](config/BR-02-differ-reset-publish.md) |
| BR-03 | Cancellation bands | sprint-2, config | P2 | — | Carolina | [config/BR-03-cancellation-bands.md](config/BR-03-cancellation-bands.md) |
| BR-04 | A change on another page shows here | sprint-2, config | P2 | — | Carolina | [config/BR-04-change-on-another-page.md](config/BR-04-change-on-another-page.md) |
| BR-05 | "No cap" never becomes zero | sprint-2, config | P2 | — | Carolina | [config/BR-05-no-cap-never-zero.md](config/BR-05-no-cap-never-zero.md) |
| BR-06 | Who can see Business Rules | sprint-2, config | P2 | — | Mateo, Lucía | [config/BR-06-who-can-see-business-rules.md](config/BR-06-who-can-see-business-rules.md) |
| HSET-01 | Properties and room types in the RMS | sprint-16, hotel | P1 | B3 | Carolina | [hotel/HSET-01-properties-and-room-types.md](hotel/HSET-01-properties-and-room-types.md) |
| HSET-02 | Stay rules on the Business Rules page | sprint-16, hotel | P1 | B3 | Carolina | [hotel/HSET-02-stay-rules.md](hotel/HSET-02-stay-rules.md) |
| HINV-01 | Timeline shows the hotel | sprint-17, hotel, inventory | P1 | B4 | Carolina | [hotel/HINV-01-timeline-shows-the-hotel.md](hotel/HINV-01-timeline-shows-the-hotel.md) |
| HINV-02 | Block a room for three nights | sprint-17, hotel, inventory | P1 | B4 | Mateo | [hotel/HINV-02-block-three-nights.md](hotel/HINV-02-block-three-nights.md) |
| HINV-03 | Block conflict names the night | sprint-17, hotel, inventory | P1 | B4 | Mateo | [hotel/HINV-03-block-conflict-names-the-night.md](hotel/HINV-03-block-conflict-names-the-night.md) |
| HINV-04 | Shorten and release a block | sprint-17, hotel, inventory | P2 | — | Mateo | [hotel/HINV-04-shorten-and-release.md](hotel/HINV-04-shorten-and-release.md) |
| HINV-05 | Stop-sell and min-stay | sprint-17, hotel, inventory | P1 | B4 | Carolina | [hotel/HINV-05-stop-sell-and-min-stay.md](hotel/HINV-05-stop-sell-and-min-stay.md) |
| HINV-06 | Lucía sees, cannot edit | sprint-17, hotel, inventory | P2 | — | Lucía | [hotel/HINV-06-lucia-sees-cannot-edit.md](hotel/HINV-06-lucia-sees-cannot-edit.md) |
| HRATE-01 | Price check matches the reference stays | sprint-18, hotel, config | P1 | B5 | Carolina | [hotel/HRATE-01-price-check-reference-stays.md](hotel/HRATE-01-price-check-reference-stays.md) |
| HRATE-02 | Overlapping seasons refused | sprint-18, hotel, config | P1 | B5 | Carolina | [hotel/HRATE-02-overlapping-seasons-refused.md](hotel/HRATE-02-overlapping-seasons-refused.md) |
| HRATE-03 | Add a season and publish | sprint-18, hotel, config | P1 | B5 | Carolina ×2 | [hotel/HRATE-03-add-a-season-and-publish.md](hotel/HRATE-03-add-a-season-and-publish.md) |
| HRATE-04 | Rate plan changes deposit and cancellation | sprint-18, hotel, config | P2 | — | Carolina | [hotel/HRATE-04-rate-plan-deposit-and-cancellation.md](hotel/HRATE-04-rate-plan-deposit-and-cancellation.md) |
| HRATE-05 | City tax shown, not charged | sprint-18, hotel, config | P2 | — | Carolina | [hotel/HRATE-05-city-tax-shown-not-charged.md](hotel/HRATE-05-city-tax-shown-not-charged.md) |
| HBKG-01 | Create a 2-night midweek stay | sprint-19, hotel, bookings | P1 | B6 | Carolina | [hotel/HBKG-01-two-night-midweek.md](hotel/HBKG-01-two-night-midweek.md) |
| HBKG-02 | Three-room group with different dates | sprint-19, hotel, bookings | P1 | B6 | Carolina | [hotel/HBKG-02-three-room-group.md](hotel/HBKG-02-three-room-group.md) |
| HBKG-03 | Restriction refusal and override | sprint-19, hotel, bookings | P1 | B6 | Carolina | [hotel/HBKG-03-restriction-override.md](hotel/HBKG-03-restriction-override.md) |
| HBKG-04 | Check in on the arrival day | sprint-19, hotel, bookings | P1 | B6 | Carolina | [hotel/HBKG-04-check-in-arrival-day.md](hotel/HBKG-04-check-in-arrival-day.md) |
| HBKG-05 | Early departure credits unused nights | sprint-19, hotel, bookings | P1 | B6 | Carolina | [hotel/HBKG-05-early-departure.md](hotel/HBKG-05-early-departure.md) |
| HBKG-06 | Extend an in-house guest | sprint-19, hotel, bookings | P1 | B6 | Carolina | [hotel/HBKG-06-extend-in-house.md](hotel/HBKG-06-extend-in-house.md) |
| HBKG-07 | No-show releases following nights | sprint-19, hotel, bookings | P1 | B6 | Carolina | [hotel/HBKG-07-no-show.md](hotel/HBKG-07-no-show.md) |
| HBKG-08 | Move room, timeline updates | sprint-19, hotel, bookings | P2 | — | Carolina | [hotel/HBKG-08-move-room.md](hotel/HBKG-08-move-room.md) |
| HBKG-09 | Balance due counts from arrival | sprint-19, hotel, bookings | P2 | — | Carolina | [hotel/HBKG-09-balance-from-arrival.md](hotel/HBKG-09-balance-from-arrival.md) |
| HBKG-10 | Night audit raises alerts, no status change | sprint-19, hotel, bookings | P2 | — | Carolina | [hotel/HBKG-10-night-audit.md](hotel/HBKG-10-night-audit.md) |
| HENG-01 | Search a stay from the home page | sprint-20, engine | P1 | B7 | Guest | [engine/HENG-01-search-and-from-prices.md](engine/HENG-01-search-and-from-prices.md) |
| HENG-02 | Min-stay reason and one-click fix | sprint-20, engine | P1 | B7 | Guest | [engine/HENG-02-min-stay-one-click.md](engine/HENG-02-min-stay-one-click.md) |
| HENG-03 | Book now, pay later | sprint-20, engine | P1 | B7 | Guest + Carolina | [engine/HENG-03-pay-later-in-rms.md](engine/HENG-03-pay-later-in-rms.md) |
| HENG-04 | Online deposit confirms the booking | sprint-20, engine | P1 | B7 | Guest + Carolina | [engine/HENG-04-deposit-stripe-confirmed.md](engine/HENG-04-deposit-stripe-confirmed.md) |
| HENG-05 | Two browsers race for the last room | sprint-20, engine | P1 | B7 | Carolina + two guests | [engine/HENG-05-two-browsers-last-room.md](engine/HENG-05-two-browsers-last-room.md) |
| HENG-06 | Hold expires and the nights are free | sprint-20, engine | P2 | — | Guest + Carolina | [engine/HENG-06-hold-expires-nights-free.md](engine/HENG-06-hold-expires-nights-free.md) |
| HENG-07 | Sold out, then join the waitlist | sprint-20, engine | P2 | — | Guest + Carolina | [engine/HENG-07-sold-out-waitlist.md](engine/HENG-07-sold-out-waitlist.md) |
| HPOR-01 | Agency availability and rates | sprint-20, portal | P1 | B8 | Ada Agent | [portal/HPOR-01-availability-and-rates.md](portal/HPOR-01-availability-and-rates.md) |
| HPOR-02 | Agency request, hold visible in the RMS | sprint-20, portal | P1 | B8 | Ada Agent + Carolina | [portal/HPOR-02-request-hold-on-timeline.md](portal/HPOR-02-request-hold-on-timeline.md) |
| HPOR-03 | Commission payable date is after check-out | sprint-20, portal | P2 | — | Ada Agent | [portal/HPOR-03-commission-payable-after-checkout.md](portal/HPOR-03-commission-payable-after-checkout.md) |
| HOPS-01 | Confirmation shows the stay | sprint-21, hotel, documents | P1 | B9 | Carolina | [hotel/HOPS-01-confirmation-shows-the-stay.md](hotel/HOPS-01-confirmation-shows-the-stay.md) |
| HOPS-02 | Registration export hides sensitive columns | sprint-21, hotel, guests | P1 | B9 | Carolina, Lucía | [hotel/HOPS-02-registration-export.md](hotel/HOPS-02-registration-export.md) |
| HOPS-03 | Arrivals brief for tomorrow | sprint-21, hotel, guests | P2 | — | Carolina | [hotel/HOPS-03-arrivals-brief-tomorrow.md](hotel/HOPS-03-arrivals-brief-tomorrow.md) |
| HOPS-04 | Low occupancy groups consecutive nights | sprint-21, hotel, alerts | P2 | — | Carolina | [hotel/HOPS-04-low-occupancy-groups-nights.md](hotel/HOPS-04-low-occupancy-groups-nights.md) |
| HOPS-05 | Dashboard occupancy, ADR, RevPAR | sprint-21, hotel, reports | P1 | B9 | Carolina | [hotel/HOPS-05-dashboard-occupancy-adr-revpar.md](hotel/HOPS-05-dashboard-occupancy-adr-revpar.md) |
| HOPS-06 | Modify a stay, document plan follows | sprint-21, hotel, documents | P2 | — | Carolina | [hotel/HOPS-06-modify-stay-updates-the-plan.md](hotel/HOPS-06-modify-stay-updates-the-plan.md) |
| HCRM-01 | Contact shows last and next stay | sprint-21, crm | P1 | B9 | Carolina | [crm/HCRM-01-contact-last-and-next-stay.md](crm/HCRM-01-contact-last-and-next-stay.md) |
| HCRM-02 | Segment by length of stay | sprint-21, crm | P2 | — | Carolina | [crm/HCRM-02-segment-by-length-of-stay.md](crm/HCRM-02-segment-by-length-of-stay.md) |
| HCRM-03 | Journey email three days before arrival | sprint-21, crm | P2 | — | Carolina | [crm/HCRM-03-journey-three-days-before-arrival.md](crm/HCRM-03-journey-three-days-before-arrival.md) |
| HOFF-01 | Stay-window percent discounts the nights inside it | sprint-22, hotel, offers | P1 | B9 | Carolina | [hotel/HOFF-01-stay-window-percent.md](hotel/HOFF-01-stay-window-percent.md) |
| HOFF-02 | Min nights skips a short stay | sprint-22, hotel, offers | P2 | — | Carolina | [hotel/HOFF-02-min-nights.md](hotel/HOFF-02-min-nights.md) |
