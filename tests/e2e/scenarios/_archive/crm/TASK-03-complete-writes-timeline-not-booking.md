# TASK-03 · Completing a task writes the contact timeline and nothing on the booking
- **Tags:** sprint-10, crm
- **Priority:** P1
- **Users:** Lucía
- **Start:** reset

## Why
A task completion is CRM history. The booking history stays still (OPS-007).

## Steps
1. Sign in as Lucía. Open `http://localhost:3001/crm/sales/tasks`.
2. Open a **Request response** task she can complete (TASK-01’s task, before it is released). Note the booking reference.
3. **Complete** with outcome `Called the guest`.
4. Open the contact. Read **Timeline**.
5. Open the booking in the RMS. Read **History**.

## Expected
- [ ] E1 · The task leaves the open list. Hint on the dialog: `Completing a task writes to the contact timeline, never the booking.`
- [ ] E2 · The contact timeline has a row titled `Task completed` with detail `Called the guest`.
- [ ] E3 · The booking history has no new row for that completion.

## Notes
If the booking history gains a task row, that is a **BUG**.
