# 18-04 — Taxes and fees

**Repo:** iconic-api
**Depends on:** 18-02
**Read first:** 09 H9, H16, H17; `PngFees.php`, `FeesSettings.php`, `Enums/PngCategory.php`, `Support/Guests/ApplyPng.php`, `Support/Guests/PngCategory.php`, `Support/Guests/AndeanCommunity.php`, `Support/Documents/*` (where fees are rendered), `engine_settings.fees`

## Why
Galápagos park and transit fees were per-guest, regulatory and paid locally. Hotels have city tax, VAT-like percentages and service fees with various bases. One generic list covers both "informational" and "charged".

## Build
1. Business rules key `taxes: list<{code, label, basis: PER_STAY|PER_NIGHT|PER_PERSON_PER_NIGHT|PCT_OF_ROOM, amount (int USD, or whole pct for PCT_OF_ROOM), child_exempt_under_age (nullable int), charged: bool, shown_in_price_panel: bool}>`. Config migration publishes an **empty list** in production and the fixture's list in local/testing. `Sprint 18: taxes added (09 H9)`.
2. `TaxCalculator::forStay(StayQuote, guests with ages, list<Tax>): list<TaxLine>` — pure; `PCT_OF_ROOM` on the room total after discounts, half-up.
3. `StayQuote` gets `tax_lines` and `total_including_charged_taxes`. Taxes with `charged = false` are shown, never added to amounts due.
4. Booking: `tax_lines` (json, frozen at sale) — column added in Sprint 19's booking migration; here only the quote side.
5. Retire PNG/TCT **as configuration**: `engine_settings.fees.png`, `fees.tct_pp` marked legacy (read-only, like 18-01 point 6). `PngCategory`, `ApplyPng`, `bookings.png_collected` stay for yacht bookings until Sprint 22; new code must not use them (add them to the Arch test's "legacy" allowlist with a comment).
6. `engine_settings.fees.footnote` stays (generic text).

## Tests
Each basis; child exemption by age; uncharged tax excluded from total due; empty list → no tax lines.

## Done when
A quote shows "City tax · 2 adults × 3 nights" when configured, and nothing when the list is empty.
