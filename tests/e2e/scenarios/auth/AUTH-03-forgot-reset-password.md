# AUTH-03 · Forgot and reset a password
- **Tags:** sprint-1, auth
- **Priority:** P1
- **Users:** Carolina
- **Start:** reset
- **Needs:** Mailpit

## Why
Forgot-password must not enumerate emails, and the new password (D1a: 8+ characters) must replace the old one.

## Steps
1. Open `http://localhost:3001/forgot-password`.
2. Enter `nobody@iconic.test`. Click `Send reset link`. Note the confirmation.
3. Enter `carolina@iconic.test`. Click `Send reset link`. Note the confirmation (must match step 2).
4. Run `tests/e2e/bin/mail-latest.sh carolina@iconic.test`. Open the `http://localhost:3001/reset-password?…` link in a fresh context.
5. Set password `Newpass1` and confirm. Click `Reset password`.
6. On `/login`, sign in as `carolina@iconic.test` / `password` (the old one).
7. Sign in as `carolina@iconic.test` / `Newpass1`.

## Expected
- [ ] E1 · After every submit the notice is `If that email is on an account, we sent a reset link.` (unknown and known emails identical). Title is `Forgot password`. Link `Back to sign in` is present.
- [ ] E2 · Mailpit subject is `Reset your Iconic password`. The reset URL is `/reset-password` with `token` and `email` query params.
- [ ] E3 · Reset page title is `Reset password`. Hint reads `At least 8 characters.` Fields: `Password`, `Confirm password`. Button: `Reset password`.
- [ ] E4 · After reset, URL is `/login?notice=password-changed` and the notice is `Password changed`. You are signed out.
- [ ] E5 · Old password `password` shows `These credentials do not match our records.`
- [ ] E6 · `Newpass1` signs in and lands on `/rms/reservations/calendar`. Header `CAROLINA M. — ADMIN`.

## Notes
Minimum 8 characters is D1a (`Password::min(8)`). Sprint 1 REPORT still says 12 — the screen and API are 8.
The confirmation notice stays above the form after the first send. Do **not** reload between steps 2 and 3 — enter the second address on the same page.
