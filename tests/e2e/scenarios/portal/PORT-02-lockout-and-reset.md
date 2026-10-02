# PORT-02 · Wrong password, lockout, reset, sign in again
- **Tags:** sprint-13, portal
- **Priority:** P1
- **Batch:** B16
- **Users:** Ada Agent
- **Start:** reset
- **Needs:** Mailpit

## Why
A wrong password, an unknown address, a disabled account and a suspended agency must look the same. The login limiter locks the address, and a mail reset replaces the password.

## Steps
1. Open `http://localhost:3002/login`. Sign in as `ada@portal.test` / `wrong-password`. Note the message.
2. Sign in as `nobody@portal.test` / `password`. Note the message.
3. Open `http://localhost:3002/forgot`. Submit `nobody@portal.test`, then `ada@portal.test`, without reloading. Note both notices.
4. Run `tests/e2e/bin/mail-latest.sh ada@portal.test`. Open the reset link. Set password `Newpass1` and confirm. Click `Reset password`.
5. On `/login`, sign in as `ada@portal.test` / `password`, then as `ada@portal.test` / `Newpass1`.
6. Still on a fresh limiter for this address (the attempts above must have been fewer than five failures): submit `ada@portal.test` / `not-the-password` six times, as fast as the form allows.
7. Wait until a further attempt is no longer refused for rate limit. Sign in as `ada@portal.test` / `Newpass1`.

## Expected
- [ ] E1 · Wrong password shows one message: `These credentials do not match our records.`
- [ ] E2 · The unknown address shows that same sentence, in the same place.
- [ ] E3 · Both forgot submits show `We have emailed your password reset link.` Title is `Forgot password`. Link `Back to sign in` is present. Only `ada@portal.test` receives mail.
- [ ] E4 · Mailpit subject is `Reset your Iconic portal password`. The URL is `http://localhost:3002/reset-password` with `token` and `email`.
- [ ] E5 · Reset title is `Reset password`. Fields `Password` and `Confirm password`. Button `Reset password`. After submit the notice is `Your password has been reset.` and `Back to sign in` is shown. You are not on the rates page.
- [ ] E6 · `password` shows `These credentials do not match our records.` `Newpass1` lands on `/rates` with `Signed in as` `Ada Agent` and agency `Blue Latitude Travel`.
- [ ] E7 · The 6th rapid failure shows `Too Many Attempts.` (the API 429 body). It is not the credentials sentence, and it is not the panel's `Too many attempts. Try again in a minute.`

## Notes
Login allows 5 attempts per minute for that email and IP (`Limit::perMinute(5)`). Step 6 needs the limiter empty, so do not spend five failures before it. Forgot mail uses a different limiter. Reset first in the sense of step 1 of the README: run `reset.sh` before this scenario so Redis limits from another scenario are gone.
