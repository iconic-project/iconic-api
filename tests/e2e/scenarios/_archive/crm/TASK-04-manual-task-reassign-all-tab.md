# TASK-04 · Mateo creates a manual task and reassigns it; All is hidden without records.act_on_any
- **Tags:** sprint-10, crm
- **Priority:** P2
- **Users:** Mateo, Lucía
- **Start:** reset

## Why
A person can raise a task. The All tab is the any-records view.

## Steps
1. Sign in as Mateo. Open `http://localhost:3001/crm/sales/tasks`. Confirm **All** is present.
2. **New task**: title `Call back`, due tomorrow, contact **Anna Whitfield**, owner **Lucía B.** (the owner field is on this form; Mateo has `records.act_on_any`). Save. The task row has no later reassign control.
3. Sign out. Sign in as Lucía. Open Tasks. Look for **All**. Open **Mine**.

## Expected
- [ ] E1 · Mateo sees **All**. The new task is open, kind **Manual**.
- [ ] E2 · Lucía sees `Call back` under **Mine**. Mateo does not see it under **Mine**. He still sees it under **All**.
- [ ] E3 · Lucía has no **All** tab (`records.act_on_any` is absent).

## Notes
Do not complete the task.
