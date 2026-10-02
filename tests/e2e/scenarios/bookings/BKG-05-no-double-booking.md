# BKG-05 · No double booking
- **Tags:** sprint-4, bookings
- **Priority:** P1
- **Users:** Carolina ×2
- **Start:** reset
- **Needs:** two browser contexts

## Why
The unique active-claim index is the last word. A second Create on a taken cabin must show the sold sentence and write nothing.

## Steps
1. Run `tests/e2e/bin/reset.sh`. In context A, sign in as Carolina. Open `http://localhost:3001/rms/reservations/calendar`. Date range **Year 2027**. Confirm ANATIVA Suite 01 on `28 Nov 2027` shows `·` (Available). If it does not, **stop** — the reset did not apply.
2. Context A: `/rms/reservations/bookings` → `＋ New reservation`. Type CABIN. Guest `E2E First`, email `e2e.bkg05a@iconic.test`, phone `+1 555 0501`, preferred EMAIL, channel D2C / Hotel Booking Engine. Departure `28 Nov 2027` · ANATIVA. 2 adults, Suite 01. Wait until the price box shows `USD 26,600`. Do **not** click Create yet.
3. Context B (separate browser / private window): sign in as Carolina. Same New reservation, same departure and Suite 01. Guest `E2E Second`, email `e2e.bkg05b@iconic.test`. Price box `USD 26,600`. Click `Create reservation` and wait for the success toast.
4. Context A: click `Create reservation`.
5. Run `tests/e2e/bin/db-check.sh 'App\Models\Booking::query()->whereDate("created_at", now())->whereHas("cabin", fn ($q) => $q->where("code", "S1"))->whereHas("departure", fn ($q) => $q->where("date", "2027-11-28")->whereHas("property", fn ($y) => $y->where("code", "ANATIVA")))->count()'`.

## Expected
- [ ] E1 · After step 1 the ANATIVA Suite 01 / 28 Nov cell is `·`. Do not continue if it is not.
- [ ] E2 · Context B toast `Reservation ANK-2026-0022 created.` ⚠ UNVERIFIED — next ref from Pest.
- [ ] E3 · Context A modal stays open. `.warnbox` shows `Suite 01 on 28 Nov 2027 · ANATIVA is sold.` (API `ConflictMessage`; Pest). Cabin then reads `Suite 01 · Not available`. ⚠ UNVERIFIED — taken-cabin suffix i18n `bookings.cabinTaken`.
- [ ] E4 · `db-check` prints `1`.

## Cross-checks
- `bin/db-check.sh` expression above → `1`

## Notes
Target cabin: **28 Nov 2027 ANATIVA Suite 01** (DEP-008). Do not put the word `create` in the tinker expression — `db-check.sh` refuses it. This is not a `db-check` write.
