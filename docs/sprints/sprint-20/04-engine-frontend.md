# 20-04 — Engine frontend: search, results, details, confirmation

**Repo:** iconic-engine (and iconic-ui for shared bits)
**Depends on:** 20-02, 20-03; 16-07
**Read first:** `iconic-engine/app/pages/**`, `nuxt.config.ts`, i18n files, HILO brand book; `booking_engine_SPEC.md` for design language only (its business rules are superseded by 09)

## Why
The public site stops being a cruise catalogue and becomes a hotel booking engine.

## Build
1. **Home** (`index.vue`): property hero, search bar (`AnkStayInput` with price calendar from `/calendar`, guests and rooms selector, child ages), highlights, room type cards, FAQs.
2. **Results** (`book/rooms.vue`, replaces `book/cabins.vue`): for the chosen stay and party, room types with photos, occupancy, "only n left" label, plans with price for the stay and per night, reasons when not bookable ("Minimum stay 3 nights from this date" with a one-click "Change to 3 nights"). Multi-room: pick quantities per type; the UI distributes the party and validates occupancy client-side **for convenience only** (server re-validates).
3. **Room type page** (`rooms/[slug].vue`, replaces `itineraries/[slug].vue`): gallery, amenities, bed setup, size, SEO meta from the API.
4. **Details** and **Confirmation**: stay summary with nights, night breakdown (collapsible), taxes shown as configured, hold countdown, both checkout paths.
5. Remove `charter.vue` from navigation (buyout decision is HQ1/22-02); keep the file until 22-02.
6. SSR + SEO: canonical URLs, sitemap for room types, structured data `Hotel` + `HotelRoom` (schema.org) built from API content only.
7. All prices formatted from integers via `useMoney`; no business values in the frontend.

## Tests
Vitest: search state ↔ URL query (shareable links), party distribution helper, reason messages; component tests for the results card.

## Done when
`pnpm lint && pnpm typecheck && pnpm test` green; search → hold → request works against the local API.
