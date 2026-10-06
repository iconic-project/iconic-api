# HOPS-03 · Arrivals brief for tomorrow
- **Tags:** sprint-21, hotel, guests
- **Priority:** P2
- **Batch:** B27
- **Users:** Carolina
- **Start:** reset

## Why
The brief is one arrival date, not a departure. Tomorrow is the date the operator prints.

## Steps
1. Sign in as Carolina. Open `http://localhost:3001/rms/operations/guest-experience`.
2. Set **Arriving from** and **Arriving to** to tomorrow in Galápagos (UTC−6).
3. Click **Arrivals brief**.

## Expected
- [ ] E1 · The brief title is **ARRIVALS BRIEF**. The subtitle includes the property and tomorrow's date.
- [ ] E2 · Sections include **Expected arrival**, **Dietary & food preferences**, **Celebrations**, **Special requests**, and **Room & rhythm**.
- [ ] E3 · Carolina also sees **Accessibility requirements**. An empty section says `None recorded.`
- [ ] E4 · The page has no departure selector.

## Notes
An empty tomorrow is a pass. Do not create a booking to fill the brief. The From date is the brief date.
