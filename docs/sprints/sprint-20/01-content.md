# 20-01 — Content: itineraries → property and room types

**Repo:** iconic-api, iconic-panel
**Depends on:** Sprint 19
**Read first:** 09 H20; `Models/Itinerary.php`, `Actions/Itineraries/*`, `Support/Itineraries/*` (`Defaults`, `Completeness`, `SeedMapper`), `ItineraryController`, panel `rms/booking-engine/itineraries.vue`

## Why
Guests choose a hotel and a room, not a route. The CMS work already done on itineraries (images, highlights, FAQs, SEO, completeness checks) moves to where hotel content lives.

## Build (api)
1. Actions `UpdatePropertyContent` (exists from 16-02 — extend) and `UpdateRoomTypeContent`; image upload endpoints for property hero and room type photos (reuse the itinerary image pipeline: validation, storage, alt text required).
2. `Support/Content/Completeness` — port `Itineraries/Completeness` rules to property (hero, description, address, policies, meta) and room types (≥ 1 photo with alt, description, bed setup, meta). A room type that is incomplete cannot be shown on the engine (same rule itineraries had with status).
3. One-off migration: copy the **first published** itinerary's FAQs and highlights into the property as a starting point (yacht installs only; hotel seed already has content). Itineraries become read-only (`ItineraryPolicy` denies writes; dropped in Sprint 22).
4. Delete `Support/Itineraries/Defaults` usage from new code (it contains yacht copy such as "7 nights · Sun→Sun").

## Build (panel)
1. Menu Booking engine → **Property** (content editor, completeness meter) and **Room types** (list + editor: occupancy, photos, amenities, SEO, preview card).
2. `itineraries.vue` removed from the menu; route redirects to Room types.

## Tests
Completeness rules; image upload validation; permissions; panel editor emits the right payloads.

## Done when
The engine-facing content of the hotel fixture is 100% complete in the panel's meter.
