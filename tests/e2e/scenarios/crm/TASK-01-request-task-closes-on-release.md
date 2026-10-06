# TASK-01 · A request raises one response task; releasing it closes the task
- **Tags:** sprint-10, crm
- **Priority:** P1
- **Users:** Guest + Lucía
- **Start:** reset

## Why
The request SLA is a task. Releasing the hold closes that task. Nothing cancels the booking by itself — release is the staff action.

## Steps
1. **Guest.** Pay later on a free stay (HENG-03), email `e2e.task01@iconic.test`. Read the request reference.
2. **Lucía** (`lucia@iconic.test`). Open `http://localhost:3001/crm/sales/tasks`. Find the **Request response** task for that contact.
3. Read the due time.
4. In the RMS, release that request with a reason. Reload **Tasks** after Horizon has processed the status job. The close is `RaiseTasksOnBookingStatusChanged`, not the five-minute sweep. If Horizon is down, that is **ENV**, not a missing close.
5. Open `http://localhost:3001/crm/sales/pipeline` and find that contact’s deal.

## Expected
- [ ] E1 · Exactly one open **Request response** task for that request. Due is 24 business hours after the request (`sla.response_hours`, seed 24). ⚠ UNVERIFIED — `TaskDue::responseHours`, not a reset clock.
- [ ] E2 · After release the task is not open. The booking status is **RELEASED**, not **CANCELLED**.
- [ ] E3 · The deal is **Lost**. A released booking is a lost stage (`DealStages` treats `RELEASED` like a cancellation).

## Notes
Do not complete the task by hand before the release. A second task for the same request is a **BUG**.
