# USR-03 · No privilege escalation from a limited role
- **Tags:** sprint-1, users-roles
- **Priority:** P2
- **Users:** Carolina · new limited user
- **Start:** reset
- **Needs:** Mailpit · two browser contexts

## Why
`users.manage` must not let someone invite as Admin or disable Carolina. The API message must appear in the modal.

## Steps
1. As Carolina on `/rms/admin/permissions`, click `＋ New role`. Name `Limited ops`. Description empty. `Copy permissions from` = `None`. `Create`.
2. In the matrix, grant only `panel.rms` (Sections) and `users.manage` (Admin). Save (`Save` on the sticky bar). Confirm any access-loss dialog if it appears.
3. Invite `limited@iconic.test`, name `Limited Op`, role `Limited ops`.
4. `mail-latest.sh limited@iconic.test`. Context B: accept, password `Limited1`.
5. Context B: open `/rms/admin/permissions`. `＋ Invite user`, try role **Admin**, name `Nope`, email `nope@iconic.test`. `Invite`.
6. Still context B: on Carolina’s row, try `Disable` (if the action is shown) and confirm.

## Expected
- [ ] E1 · Inviting as Admin fails. The modal shows the API message `This action is unauthorized.`
- [ ] E2 · Disabling Carolina fails with the same message `This action is unauthorized.` in the modal (403, not 409).
- [ ] E3 · Carolina remains `Active` and Admin.

## Notes
Sprint 1 task AC said 409 for last-admin; the shipped API returns 403 because a non-Admin cannot mutate an Admin. Screen wins.
Status pills are i18n sentence case (`Active`) and CSS-uppercase on screen (`ACTIVE`). Match case-insensitively.
