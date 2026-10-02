# AUTH-05 · Accept while signed in as someone else
- **Tags:** sprint-1, auth
- **Priority:** P2
- **Users:** Carolina · new user
- **Start:** reset
- **Needs:** Mailpit

## Why
Opening an invite link in an existing session must swap to the new user, not keep Carolina signed in.

## Steps
1. Sign in as `carolina@iconic.test` / `password`. Invite `swap@iconic.test` as **Manager**, name `Session Swap` (`＋ Invite user` on `/rms/admin/permissions`).
2. Run `tests/e2e/bin/mail-latest.sh swap@iconic.test`.
3. **In Carolina’s same browser context**, open the accept-invitation link.
4. Set password `Swappass1` / confirm. Click `Set password`.

## Expected
- [ ] E1 · After accept, the header shows `SESSION SWAP — MANAGER` (not `CAROLINA M. — ADMIN`).
- [ ] E2 · URL is `/rms/reservations/calendar`.
