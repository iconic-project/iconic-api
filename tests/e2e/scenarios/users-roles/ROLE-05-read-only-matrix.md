# ROLE-05 · Read-only matrix
- **Tags:** sprint-1, users-roles
- **Priority:** P2
- **Users:** Carolina · new user with `users.manage` only
- **Start:** reset
- **Needs:** Mailpit

## Why
`users.manage` without `roles.manage` can see the matrix but must not edit roles.

## Steps
1. As Carolina, create role `Users only`. Grant `panel.rms` and `users.manage` only. Save.
2. Invite `usersonly@iconic.test`, name `Users Only`, role `Users only`. Accept in a fresh context (`Usersonly1`).
3. As that user, open `/rms/admin/permissions`.

## Expected
- [ ] E1 · The matrix title `Permission matrix — enforced server-side on every mutation` is visible.
- [ ] E2 · No `＋ New role`. No role `···` menus. Cells are not clickable. No save / cancel bar.
- [ ] E3 · **Team members** is visible (they have `users.manage`).
