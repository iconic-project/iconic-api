# 20-07 — Sprint close: e2e and report

**Repo:** iconic-api
**Depends on:** 20-01 … 20-06

New scenarios:
- **HENG-01** Search a stay from the home page; price calendar shows from-prices (P1)
- **HENG-02** Min-stay reason and one-click fix (P1)
- **HENG-03** Book now, pay later: request lands in the RMS queue with the stay (P1)
- **HENG-04** Online deposit with Stripe test card; booking confirmed (P1)
- **HENG-05** Two browsers race for the last room; one wins (P1)
- **HENG-06** Hold expires; nights free again on the timeline (P2)
- **HENG-07** Sold out → join waitlist (P2)
- **HPOR-01** Agency availability and rates (P1)
- **HPOR-02** Agency request on a stay; hold visible in the RMS (P1)
- **HPOR-03** Commission list shows payable date after check-out (P2)

Rewrite/retire `web/*` and `portal/*` scenarios; run P1; update `LEDGER.md`; close `REPORT.md`.
