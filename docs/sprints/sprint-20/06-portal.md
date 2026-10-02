# 20-06 — Agency portal on stays

**Repo:** iconic-api, iconic-portal
**Depends on:** 20-02, 19-06
**Read first:** `routes/api/portal.php`, `Http/Controllers/Portal/*`, `Support/Portal/*`, `Support/Agencies/PortalPreview.php`, `iconic-portal/app/pages/{availability,rates,requests/new,bookings,commissions}.vue`

## Build (api)
1. Portal availability: same data as the engine's `/availability` + `/calendar`, agency-scoped (agency rates/commission visible as today).
2. Portal rates: seasons × room types matrix, plans, LOS, supplements — read-only, from the published document.
3. Portal request: stay + rooms[] + party → `CreateBookingRequest` with agency hold (`ON_HOLD_AGENCY` path unchanged).
4. Bookings and commissions lists show stays; commission payable dates from 19-05.

## Build (portal)
1. `availability.vue`: `AnkStayInput` + party → room types with rooms left and prices; month grid alternative view.
2. `rates.vue`: seasons strip + matrix.
3. `requests/new.vue`: stay picker, multi-room, plan selection.
4. `bookings.vue`, `commissions.vue`: stay columns.

## Tests
Portal feature tests (scoping: an agency never sees another agency's bookings), request creation on a stay, Vitest for the new forms.

## Done when
An agency user can find a free Family room for 4 nights and request it.
