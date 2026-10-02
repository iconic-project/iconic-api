# AUTH-02 · Failed sign-ins look identical
- **Tags:** sprint-1, auth
- **Priority:** P2
- **Users:** none signed in
- **Start:** reset

## Why
Different messages would let an attacker enumerate accounts. Rate limit must kick in on the 6th attempt.

## Steps
1. Open `http://localhost:3001/login`.
2. Email `carolina@iconic.test`, password `wrong-password`. Submit `Sign in`. Note the message. Click away or wait for the form to be usable again.
3. Email `nobody@iconic.test`, password `password`. Submit.
4. In a second step later (or via Carolina in another context after this check): the invited/disabled cases use the same string — here submit email `carolina@iconic.test` with password `password` once to confirm the happy path still works after you finish the rate-limit steps, **or** skip if you already burned the limiter.
5. From a fresh `reset.sh` (so Redis rate limits are empty): submit `carolina@iconic.test` / `not-the-password` six times in a row, as fast as the form allows.

## Expected
- [ ] E1 · Wrong password shows exactly one form message (`.warnbox`, not a per-field error): `These credentials do not match our records.`
- [ ] E2 · Unknown email shows the same message, same place.
- [ ] E3 · The 6th rapid attempt shows `Too many attempts. Try again in a minute.` (not the credentials message).

## Notes
Invited and disabled users also return the credentials message (API). Covered for disabled login in AUTH-06. Reset first so the limiter is empty.
