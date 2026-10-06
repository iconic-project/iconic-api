# ENG-02 · Manager can't touch rules
- **Tags:** sprint-2, config
- **Priority:** P1
- **Users:** Mateo
- **Start:** reset

## Why
E6: guests, calendar and fees need `engine_settings.manage`. Mateo has copy only.

## Steps
1. Sign in as Mateo. Open `/rms/booking-engine/settings`.
2. Scroll each named panel. Inspect **Guests & capacity**, **Sales calendar & search**, and **Galápagos fees shown in the price panel**.
3. Prove lock by the input’s `disabled` attribute (or that typing is ignored). Do not treat “looks grey” as enough.

## Expected
- [ ] E1 · These inputs have `disabled`: `Max guests per cabin`, `Max guests per yacht`, child ages, default search months, horizon, all PNG/TCT fee amounts.
- [ ] E2 · Copy panels stay enabled: **Booking notes & messages**, **Confirmation page — what happens next**.
- [ ] E3 · Rule panels (**Guests & capacity**, **Sales calendar & search**, fees) show the `ADMIN` pill; copy panels show `ADMIN · MANAGER`. `discounts.online_deposit_discount_pct` stays on Business Rules (not this page). New copy fields `online_deposit_advantage` / `online_deposit_perk` are copy if shown — enabled for Mateo. The private charter page is gone (Sprint 22).
