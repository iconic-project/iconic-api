# <ID> · <Title>
- **Tags:** sprint-N, area, smoke|regression
- **Priority:** P1 | P2 | P3
- **Users:** Carolina (Admin) · …
- **Start:** reset | continues from <ID>
- **Needs:** Mailpit | two browser contexts | prototype server   (omit if none)

## Why
One or two sentences: what could break and why it matters.

## Steps
1. Open `http://localhost:3001/login`, sign in as carolina@iconic.test / password.
2. …  (exact URLs, exact field labels as shown on screen, exact values)

## Expected
- [ ] E1 · <an observable fact: text on screen, a pill, a disabled button, a URL>
- [ ] E2 · …

## Cross-checks (optional)
- `bin/db-check.sh '<expression>'` → <expected JSON fragment>

## Notes
Anything that looks wrong but is intended (link to the decision, e.g. 08 E2).
