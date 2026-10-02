# AUTH-04 · Accept an invitation
- **Tags:** sprint-1, auth
- **Priority:** P1
- **Users:** Carolina · new Manager
- **Start:** reset
- **Needs:** Mailpit · two browser contexts

## Why
Invitation is the only way onto the staff panel (D2). History and status must update when the invitee sets a password.

## Steps
1. Context A: sign in as `carolina@iconic.test` / `password`. Open `http://localhost:3001/rms/admin/permissions`.
2. Click `＋ Invite user`. Fill `Name` = `Task Invitee`, `Email` = `invitee@iconic.test`, `Role` = `Manager`. Click `Invite`.
3. Run `tests/e2e/bin/mail-latest.sh invitee@iconic.test`. Copy the `http://localhost:3001/accept-invitation?…` link.
4. Context B (fresh private window, not Carolina): open the invite link. Set password `Invitee1` / confirm. Click `Set password`.
5. Context A: reload the team list. Open History on the new row.

## Expected
- [ ] E1 · After invite, toast `Invitation sent`. The new row status pill is `Invited`.
- [ ] E2 · Mailpit subject is `Set your Iconic password`. Link path is `/accept-invitation` with `token` and `email`.
- [ ] E3 · Accept page title is `Set your password`. Hint `At least 8 characters.` Button `Set password`.
- [ ] E4 · After accept, context B is signed in. Header shows `TASK INVITEE — MANAGER`. URL is `/rms/reservations/calendar`.
- [ ] E5 · Carolina’s list shows the row status `Active`.
- [ ] E6 · History (newest first) has `Invitation accepted`, then `Invited as Manager`. Times are Galápagos (label `Galápagos time · UTC−6`).

## Cross-checks
- `bin/db-check.sh 'App\Models\ChangeHistory::query()->where("event", "user.activated")->latest("id")->value("event")'` → `"user.activated"`

## Notes
Status pills are i18n sentence case (`Invited`, `Active`) and CSS-uppercase on screen (`INVITED`, `ACTIVE`). Match case-insensitively.
