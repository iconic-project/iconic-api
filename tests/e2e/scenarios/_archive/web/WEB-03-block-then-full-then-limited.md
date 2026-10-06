# WEB-03 · Block then FULL · WAITLIST then LIMITED AVAILABILITY

Retired in sprint 20. The engine searches stays. Walk HENG-01 through HENG-07. Do not walk these departure steps.
- **Tags:** sprint-8, web
- **Priority:** retired
- **Users:** Guest + Carolina
- **Start:** reset
- **Needs:** two browser contexts

## Why
K4: a cabin blocked or held in the RMS must change the engine label within 30 seconds. LIMITED AVAILABILITY is holds, not blocks (F9).

## Steps
1. **Guest.** `http://localhost:3000/`, 2 adults, Nov 2027–Jan 2028. `Check availability`. Expand **Northern Passage**. Find **7 Nov 2027 · ANATIVA**. Record the free-cabin / from-price treatment and the label (`AVAILABLE`).
2. **Carolina.** Open `http://localhost:3001/rms/operations/blocks`. `＋ New block`. Yacht **ANATIVA**. Tick `7 Nov 2027 · Northern Passage` (9 free). Tick **Suite 01** only. Reason **Fam trip**. `Create block`.
3. Guest: wait until the 7 Nov ANATIVA row updates (revalidate ≤ 15 s, or hide/show the tab). At most 30 s. Record the new label / cabin count.
4. Carolina: same departure — block the remaining eight cabins (Suite 02–08 and Owner's Suite) on 7 Nov ANATIVA. New block or extend; reason **Fam trip**.
5. Guest: wait ≤ 30 s. The 7 Nov ANATIVA row is `FULL · WAITLIST`. Actions are **Waitlist** and **Contact us**, never **Select**.
6. Carolina: open `/rms/booking-engine/departures` or Calendar Year 2027. **21 Nov 2027 ANAMARA** (DEP-005) already has Owner sold (ANK-2026-0009) and Suite 04 held (ANK-R-2026-0041). Block every remaining **free** cabin on that departure (Suites 01–03 and 05–08).
7. Guest: expand **Western Realm**, find **21 Nov 2027 · ANAMARA**. Wait ≤ 30 s.

## Expected
- [ ] E1 · After one cabin blocked: 7 Nov ANATIVA shows one cabin fewer (label still bookable, or `ONLY N CABIN(S) LEFT` if at the threshold). Change appears within 30 s. ⚠ UNVERIFIED — `EngineLabel` + task 08.
- [ ] E2 · After the rest are blocked: label `FULL · WAITLIST`. No **Select**. Waitlist is offered.
- [ ] E3 · After DEP-005 free cabins are blocked: label `LIMITED AVAILABILITY`. **Waitlist** + **Contact us**, never **Select**. ⚠ UNVERIFIED — F9 `free === 0 && held > 0`.
- [ ] E4 · Carolina Calendar: blocked cells `FAM`; Suite 04 on 21 Nov remains a hold (`REQ` / request), not a block.

## Notes
Do not use DEP-013 (chartered, not shown). LIMITED is the second beat — blocks alone go FULL, not LIMITED. Guest context has no staff cookies. Before any other click, click `Analytics off` (or set `localStorage['iconic-engine-analytics']` to `refused` and reload). Accepting analytics now also posts `POST /api/engine/events`. CRM-05 and CRM-06 own that behaviour.
