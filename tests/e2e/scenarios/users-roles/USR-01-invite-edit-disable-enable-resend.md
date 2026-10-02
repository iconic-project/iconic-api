# USR-01 · Invite, edit, disable, enable, resend
- **Tags:** sprint-1, users-roles
- **Priority:** P2
- **Users:** Carolina
- **Start:** reset
- **Needs:** Mailpit

## Why
Each team action must update the row, the status pill, and write a history sentence in Galápagos time.

## Steps
1. Sign in as `carolina@iconic.test` / `password`. Open `http://localhost:3001/rms/admin/permissions`.
2. `＋ Invite user`: name `Row Walker`, email `walker@iconic.test`, role `Sales Exec`. `Invite`.
3. On the Invited row, click `Resend invitation`. Confirm Mailpit has a newer mail (`mail-latest.sh walker@iconic.test`).
4. Click `Edit`. Change `Name` to `Row Walker Jr`. `Save`.
5. Click `Disable`. Warning shows `They're signed out on their next action.` Reason `E2E USR-01`. `Disable`.
6. Click `Enable`.
7. Open `History` on that row.

## Expected
- [ ] E1 · After invite: toast `Invitation sent`; pill `Invited`; action `Resend invitation` is present.
- [ ] E2 · After resend: toast `Invitation resent`.
- [ ] E3 · After edit: toast `User updated`; the row shows `Row Walker Jr`.
- [ ] E4 · After disable: toast `User disabled`; pill `Disabled`.
- [ ] E5 · After enable: toast `User enabled`; pill `Active` (or `Invited` if they never accepted — here they never accepted, so the pill returns to `Invited`).
- [ ] E6 · History includes (newest-first) `Enabled`, `Disabled` with `Reason: E2E USR-01`, `Name changed · Row Walker → Row Walker Jr`, `Invitation resent`, `Invited as Sales Exec`. Zone label `Galápagos time · UTC−6`.

## Notes
Status pills are i18n sentence case (`Invited`, `Disabled`, `Active`) and CSS-uppercase on screen (`INVITED`). Match case-insensitively.
