# SEG-03 · A hard bounce enters suppression and leaves marketing segments
- **Tags:** sprint-14, crm
- **Priority:** P1
- **Batch:** B18
- **Users:** Carolina
- **Start:** reset

## Why
A hard bounce suppresses the contact. Suppression wins over every marketing segment.

## Steps
1. Sign in as Carolina. Open `http://localhost:3001/crm/marketing/segments`. Read the **Suppressed** count.
2. Run `tests/e2e/bin/setup.sh hard-bounce ANK-2026-0018`. A. Fontaine has no stored email, so the argument is the booking reference.
3. Reload Segments. Open **Suppressed**. Open every marketing card (Warm dreamers, Abandoned checkout, Festive prospects, Families 6–17, Past guests — HIGH LTV, DACH luxury).

## Expected
- [ ] E1 · The helper prints `"status":"HARD_BOUNCE"` and `"contact_suppressed":true`.
- [ ] E2 · Suppressed's count is one higher than step 1. Fontaine is on that list.
- [ ] E3 · Fontaine is absent from every marketing card opened in step 3.

## Cross-checks
- `bin/db-check.sh 'App\Models\ChangeHistory::query()->where("event","contact.suppressed")->where("reason","HARD_BOUNCE")->where("subject_id", App\Models\Booking::query()->where("reference","ANK-2026-0018")->value("contact_id"))->count()'` → `1`.

## Notes
The helper fakes the queue and throws `550 5.1.1 user unknown` inside `SendDeliveryJob`. No message is delivered. Do not point this command at a real address.
