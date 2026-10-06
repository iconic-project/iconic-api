# WEB-12 · Complete your reservation from the payment-link email

Retired in sprint 20. The engine searches stays. Walk HENG-01 through HENG-07. Do not walk these departure steps.
- **Tags:** sprint-8, web
- **Priority:** retired
- **Users:** Guest + Carolina
- **Start:** reset
- **Needs:** Mailpit · two browser contexts

## Why
The complete page (K9 / J8) is the only guest write path for billing, remaining declarations and a passport. The number must be ciphertext in the database and never echoed back.

## Steps
1. **Carolina.** Open ANK-2026-0003. **Overview** → **Billing** → Email `e2e.web12@iconic.test`. Save.
2. **Payments.** `Create balance link` (deposit already settled). Clear Mailpit: `curl -sS -X DELETE http://localhost:8025/api/v1/messages`.
3. On the OPEN link click `Send by email`. Confirm `Email this payment link to the client?`
4. ```
   tests/e2e/bin/mail-find.sh --to e2e.web12@iconic.test --subject "Pay your Iconic balance — ANK-2026-0003"
   ```
   Copy the `{ENGINE_URL}/complete/{token}` link. Do not paste the token into the run report.
5. **Guest** (fresh context). Open that complete URL. Page title `Complete your reservation`.
6. **Billing details:** name `E2E Web12`, address `1 Test Street`, email `e2e.web12@iconic.test`, phone `+1 555 0812`. `Save billing details`.
7. **Declarations:** accept any still-required boxes. `Save declarations`.
8. On a guest card, fill names if empty. In **Passport number**, type a dummy value (do **not** write that value into this file, the report, or a caption). `Save guest`.
9. Read the passport field. Click `Continue to payment` only when it is enabled.

## Expected
- [ ] E1 · Mailpit body includes `/complete/{token}` and the balance amount (`USD 23,940` or `23,940`). Not a Stripe Payment Link URL.
- [ ] E2 · Complete page is `noindex` / `Cache-Control: no-store, private` (Network). Billing save succeeds. ⚠ UNVERIFIED — task 10.
- [ ] E3 · After saving a passport: label `Passport number on file`. The input is empty (`Enter a number to replace it`). The typed value never reappears.
- [ ] E4 · `Continue to payment` stays disabled until `can_pay` is true (billing + required declarations + guest details the API requires). When enabled, it goes to payment (`pay_url`) or stays disabled with `The team will send the payment link.` if `pay_url` is missing.
- [ ] E5 · Invalid token `/complete/not-a-real-token` is a single neutral state (`This link is no longer available` + `info@iconic.co`) — optional check if time.

## Cross-checks
Read-only. Do **not** print a passport.

- `bin/db-check.sh '(function(){$g=\App\Models\Guest::query()->where("booking_id",\App\Models\Booking::query()->where("reference","ANK-2026-0003")->value("id"))->whereNotNull("passport_no")->latest("id")->first();if($g===null){return["ciphertext"=>false];}$raw=\Illuminate\Support\Facades\DB::table("guests")->where("id",$g->id)->value("passport_no");$plain=$g->passport_no;$cipher=is_string($raw)&&$raw!==""&&$raw!==$plain;return["ciphertext"=>$cipher,"has_plain_column"=>$raw===$plain];})()'` → `{"ciphertext":true,"has_plain_column":false}`

## Notes
Dummy passport only — never a real number, never in the report. 0003 deposit is already paid; use the **balance** link. Guest context has no staff cookies. Before any other click, click `Analytics off` (or set `localStorage['iconic-engine-analytics']` to `refused` and reload). Accepting analytics now also posts `POST /api/engine/events`. CRM-05 and CRM-06 own that behaviour.
