# WEB-04 · Trip details tabs and the Western route map

Retired in sprint 20. The engine searches stays. Walk HENG-01 through HENG-07. Do not walk these departure steps.
- **Tags:** sprint-8, web
- **Priority:** retired
- **Users:** Guest
- **Start:** reset

## Why
Step 3 is feed-driven. NORTH and FEST must not show the Western map mislabelled.

## Steps
1. Guest. `http://localhost:3000/`, 2 adults, Nov 2027–Jan 2028. `Check availability`. On Western Realm click **Select** for **7 Nov 2027 · ANAMARA** (or open `/itineraries/western-realm` after selecting that departure).
2. Read the trip header, facts, departure switcher, rail, and tabs: **Overview**, **Itinerary**, **Includes / Excludes**, **FAQs**, **Route map**.
3. Open **Route map**. Read the title and that a map SVG is present.
4. Switch the departure (or open from itineraries) to a **Northern Passage** trip (`/itineraries/northern-passage`). Read the tabs.
5. Open a **Festive Expeditions** trip (`/itineraries/festive-expeditions`). Read the tabs.

## Expected
- [ ] E1 · WEST tabs are Overview, Itinerary, Includes / Excludes, FAQs, Route map. Rail **From** is **USD 13,300** (or the festive suffix only on festive rows). Sidebar titles `Book now, pay later:`, `Traveling with children:`, `Solo travel & triple rates:` with feed copy. ⚠ UNVERIFIED — i18n `trip.*` + task 08 browser.
- [ ] E2 · Itinerary tab day-by-day starts Sunday / San Cristóbal from the feed (not the prototype’s Monday / Baltra).
- [ ] E3 · Route map tab: title includes `The Western Route` (or the map eyebrow). A D3 SVG is visible. ⚠ UNVERIFIED — task 08 browser.
- [ ] E4 · NORTH and FEST have **no** Route map tab.

## Notes
Confirm slugs from the feed if they differ. `prefers-reduced-motion` may skip map motion — the tab and SVG must still exist on WEST. Before any other click, click `Analytics off` (or set `localStorage['iconic-engine-analytics']` to `refused` and reload). Accepting analytics now also posts `POST /api/engine/events`. CRM-05 and CRM-06 own that behaviour.
