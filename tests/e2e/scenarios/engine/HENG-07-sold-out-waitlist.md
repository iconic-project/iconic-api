# HENG-07 · Sold out, then join the waitlist
- **Tags:** sprint-20, engine
- **Priority:** P2
- **Batch:** B25
- **Users:** Guest + Carolina
- **Start:** reset
- **Needs:** two browser contexts

## Why
A room type with no room for the whole stay offers the waitlist. Joining claims nothing. The RMS waitlist shows the stay.

## Steps
1. **Carolina.** On the calendar, block every Standard Double room for check-in 21 Dec 2026, check-out 24 Dec 2026. Use New block with **Choose a room type and a count**, type Standard Double, count `10` (the fresh-seed free count). Reason `HENG-07`.
2. **Guest.** `http://localhost:3000`, `Analytics off`, search 21–24 Dec 2026, 2 adults, 1 room.
3. On **Standard Double**, name `E2E Waitlist`, email `e2e.heng07@iconic.test`. Click `Join the waitlist`.
4. **Carolina.** Open `http://localhost:3001/rms/operations/holds`, tab **Waitlist**, date range **All dates**.

## Expected
- [ ] E1 · Standard Double says `Sold out` and shows `Join the waitlist`. Twin, Family, and Suite do not show that form.
- [ ] E2 · After join, the card says `You are on the waitlist.` Nothing is held.
- [ ] E3 · The RMS row is `E2E Waitlist`, room type Standard Double, stay 21–24 Dec 2026.

## Cross-checks
- `bin/db-check.sh 'App\Models\WaitlistEntry::query()->whereHas("contact", fn ($q) => $q->where("email","e2e.heng07@iconic.test"))->value("source")'` → `ENGINE`.

## Notes
Join the waitlist appears only when that type is `SOLD_OUT` and `waitlist_enabled`. OFF-04 walks the same join. This scenario is the stay-only form of that check.
