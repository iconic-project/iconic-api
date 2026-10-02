# ROLE-03 · Custom role lifecycle
- **Tags:** sprint-1, users-roles
- **Priority:** P2
- **Users:** Carolina
- **Start:** reset

## Why
A role with users must not be deletable. After the last user is moved, delete must work.

## Steps
1. As Carolina on `/rms/admin/permissions`, `＋ New role`. Name `Copy Exec`. `Copy permissions from` = `Sales Exec`. `Create`.
2. Invite (or Edit an invited throwaway) — invite `copyexec@iconic.test`, name `Copy User`, role `Copy Exec`. (No need to accept.)
3. On the `Copy Exec` column menu (`···`), hover `Delete`.
4. Edit `Copy User` and set Role back to `Sales Exec`. `Save`.
5. Delete `Copy Exec`. Confirm `Delete this role? This cannot be undone.`

## Expected
- [ ] E1 · Toast `Role created`. A `Copy Exec` column appears.
- [ ] E2 · While the user is assigned, `Delete` is disabled. Tooltip is `Move its 1 users to another role first` (or `Move its {n} users…`).
- [ ] E3 · After moving the user, Delete works. Toast `Role deleted`. The column is gone.

## Notes
Hover **Delete** inside the open `···` menu (not only the `···` button). The blocked-delete tooltip is on that item: `Move its 1 users to another role first`. The `···` tooltip is only the dirty-draft warning.
