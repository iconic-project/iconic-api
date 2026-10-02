# GST-02 · Lucía: passports masked; enter to replace
- **Tags:** sprint-6, guests
- **Priority:** P1
- **Users:** Lucía
- **Start:** reset

## Why
Sales Exec must never receive a full passport or medical note. Empty save must leave the stored number unchanged (task 02). If the mask leaks, the dedicated-key work is wasted.

## Steps
1. Sign in as `lucia@iconic.test` / `password` (fresh context). Open `http://localhost:3001/rms/reservations/bookings`. Date range **All dates**.
2. Open ANK-2026-0007 (M. Castellanos — Lucía’s own). **Guests** tab. Read Mariana Castellanos’s card.
3. **Edit**. Passport field is empty with placeholder `Restricted — enter to replace` and hint `Leave empty to keep the stored number. Typing a value replaces it.` There are **no** medical / dietary / accessibility fields.
4. Type dummy `E2E000999` in Passport number (not a seeded passport). `Save guest`. Read the card.
5. **Edit** again. Leave Passport number empty. `Save guest`. Read the card.

## Expected
- [ ] E1 · Card passport is `Passport •••• 567` (last three of the seeded number only). No medical-note line. ⚠ UNVERIFIED — task 07 browser on 0007.
- [ ] E2 · Form: placeholder `Restricted — enter to replace`; hint as in step 3; no `Medical conditions` / `Dietary note` / `Accessibility note` labels.
- [ ] E3 · After typing `E2E000999` and save: toast `Guest saved`. Card shows `Passport •••• 999`.
- [ ] E4 · After empty save: card still `Passport •••• 999` (empty does not clear).

## Cross-checks
Read-only. Do **not** print a passport into the run report.

- `bin/db-check.sh '(function(){$g=\App\Models\Guest::query()->where("first_name","Mariana")->where("last_name","Castellanos")->first();if($g===null){return["ciphertext"=>false,"history_clean"=>false];}$raw=\Illuminate\Support\Facades\DB::table("guests")->where("id",$g->id)->value("passport_no");$plain=$g->passport_no;$cipher=is_string($raw)&&$raw!==""&&$raw!==$plain;$clean=\App\Models\ChangeHistory::query()->where("subject_id",$g->booking_id)->get()->every(fn($row)=>$plain===null||$plain===""||!str_contains(json_encode([$row->before,$row->after]),$plain));return["ciphertext"=>$cipher,"history_clean"=>$clean];})()'` → `{"ciphertext":true,"history_clean":true}`

## Notes
0003 has no stored passport — do not use it for the mask. Dummy `E2E000999` is not a seeded passport. One user per context.
