# PORT-01 · Invite, accept, then the drawer shows the last sign-in
- **Tags:** sprint-13, portal
- **Priority:** P1
- **Batch:** B16
- **Users:** Carolina + a new portal user
- **Start:** reset
- **Needs:** Mailpit

## Why
Approving a user from the drawer sends a single-use invitation. Accepting sets the password and marks the user Active. The last sign-in is written by a later sign-in, not by accept.

## Steps
1. Sign in as Carolina on `http://localhost:3001`. Open `http://localhost:3001/rms/commercial/b2b`. Open **Blue Latitude Travel**.
2. Under **Portal users**, set name `E2E Invite` and email `e2e-port01@portal.test`. Click `Add user`. On that new row, click `Invite`.
3. Run `tests/e2e/bin/mail-latest.sh e2e-port01@portal.test`. Open the accept link in a fresh browser context (not Carolina's).
4. Set password `password` and confirm it. Click `Set password`.
5. In Carolina's context, reopen the Blue Latitude drawer and read the new user's line.
6. In the portal context, `Sign out`. Open `http://localhost:3002/login`. Sign in as `e2e-port01@portal.test` / `password`.
7. Reopen the Blue Latitude drawer and read the same line again.

## Expected
- [ ] E1 · After Add user the row is `Not invited` and offers `Invite`. After Invite the toast is `Invitation sent` and the row is `Invited` with `sent` and `expires` times (Galápagos). The expiry is 14 days after the sent time.
- [ ] E2 · Mailpit subject is `Set your Iconic portal password`. The link is `http://localhost:3002/accept` with `token` and `email`.
- [ ] E3 · Accept title is `Set your password`. Fields are `Password` and `Confirm password`. Button is `Set password`. There is no static “at least 8 characters” hint. After submit the URL is `/rates`, the header shows `Blue Latitude Travel` and `Signed in as` `E2E Invite`.
- [ ] E4 · Before the login in step 6, the drawer line is `Active` and has no `last sign-in` clause.
- [ ] E5 · After step 6 the line is `Active · last sign-in` plus a Galápagos date and time.

## Notes
A password shorter than 8 characters is refused with `The password field must be at least 8 characters.` Accept logs the browser in and still leaves `last_login_at` empty until `Sign in`.
