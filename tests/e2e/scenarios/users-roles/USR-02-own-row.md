# USR-02 · Your own row
- **Tags:** sprint-1, users-roles
- **Priority:** P2
- **Users:** Carolina
- **Start:** reset

## Why
An admin must not disable or demote themselves from the UI.

## Steps
1. Sign in as `carolina@iconic.test` / `password`. Open `/rms/admin/permissions`.
2. Find the row `Carolina M.` / `carolina@iconic.test`.
3. Click `Edit`.

## Expected
- [ ] E1 · Own-row actions are `Edit` and `History` only — no `Disable`.
- [ ] E2 · Edit modal: `Role` is disabled. Hint reads `You can't change your own role`.
- [ ] E3 · `Name` is still editable.

## Notes
Status pills are i18n sentence case (`Active`) and CSS-uppercase on screen (`ACTIVE`). Match case-insensitively.
