# PIPE-02 · Lucía moves her own deal; she cannot move Mateo’s; LOST needs a reason
- **Tags:** sprint-10, crm
- **Priority:** P1
- **Users:** Lucía, Mateo
- **Start:** reset

## Why
Stages 1–4 move only for the owner, unless the actor has `records.act_on_any`. LOST is not a silent drop.

## Steps
1. Sign in as `mateo@iconic.test` / `password`. Open `http://localhost:3001/crm/sales/pipeline`. **New deal**: contact **Anna Whitfield**, title `Mateo lead`, estimate `1000`, stage **New lead**.
2. Sign out. Sign in as `lucia@iconic.test` / `password`. **New deal**: contact **K. Osei**, title `Lucia lead`, estimate `1000`, stage **New lead**.
3. Open **Lucia lead**. **Move to…** **Quoted**. The control lists every stored stage, so this is one change, not three.
4. Open **Mateo lead**. Try to move it.
5. Open **Lucia lead**. Choose **Lost**. The dialog label is `(required)` and the submit button stays disabled while the reason is empty. Then submit with reason `Not this season`.

## Expected
- [ ] E1 · **Lucia lead** lands in **Quoted**. The drawer shows that stage.
- [ ] E2 · **Mateo lead** does not move. The API returns 422 `This deal belongs to another owner.` The card stays **New lead**.
- [ ] E3 · With an empty reason the Lost submit button is disabled. After `Not this season` the deal is **Lost** and the reason is on the deal. A request sent with a blank reason is 422 `A reason is required.`

## Notes
Lucía has no `records.act_on_any`. Mateo has it, so this scenario must not ask Mateo to fail a move on Lucía’s deal. Do not delete either deal.
