# 19-07 — Panel: bookings and front desk

**Repo:** iconic-panel
**Depends on:** 19-02 … 19-06; `AnkStayInput` (16-07)
**Read first:** `app/pages/rms/reservations/bookings.vue`, `booking-requests.vue`, `rms/operations/holds.vue`, booking drawer components

## Build
1. **New booking**: `AnkStayInput` (night info from the restrictions + availability API) → rooms list (type, adults, child ages, plan, optional room picker showing free rooms for the whole stay) → live quote with night lines, taxes, deposit → contact and channels → create. Restriction failures listed with an "Override" toggle (permission + reason).
2. **Bookings list**: columns Reference, Guest, Stay (dates + nights pill), Room, Type, Status, Balance due. Filters: arriving between, in house on, departing between, status, owner, channel.
3. **Booking drawer**: stay block with **Check in**, **Check out**, **No-show** buttons (visible only when legal, using `allowed_actions` from the API — no client-side rules), **Modify stay** (preview → confirm with reason), **Move room**. Night lines table. History.
4. **Front desk** page (new, menu Reservations → Front desk): date picker (default today), three tabs **Arrivals** / **In house** / **Departures**, each with counts, expected arrival time, balance due, room, quick actions. Shows night-audit alerts for the date.
5. Calendar (17-07) bars now open the booking drawer with actions.
6. Status labels: "In house", "Checked out", "No-show" (i18n).

## Tests
Vitest: new-booking payload for a 3-room group with mixed dates; drawer shows only API-allowed actions; front desk tab filtering.

## Done when
A full stay lifecycle (create → confirm → pay → check in → extend → check out) is possible in the panel.
