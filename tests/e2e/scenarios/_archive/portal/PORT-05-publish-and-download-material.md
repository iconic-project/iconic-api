# PORT-05 · Publish a material, the agent downloads it, activity records it
- **Tags:** sprint-13, portal
- **Priority:** P1
- **Batch:** B16
- **Users:** Carolina + Ada Agent
- **Start:** reset

## Why
Staff publish a sales file from the agency drawer. The agent can download a published file, and that download is named on the agency's portal activity.

## Steps
1. Sign in as Carolina. Open Blue Latitude Travel on `http://localhost:3001/rms/commercial/b2b`. Read **Sales materials**.
2. Upload a small PDF. Title `E2E fact sheet`. Kind `Fact sheet`. Click `Upload`. The drawer sends the file for this agency; there is no shared toggle.
3. In another context, sign in as Ada. Open `http://localhost:3002/materials`. Click `Download` on that row.
4. Back on the drawer, read **Portal activity**.

## Expected
- [ ] E1 · Before the upload the drawer says `No sales materials yet.`
- [ ] E2 · Toast `Sales material uploaded`. The row shows `E2E fact sheet`, `Fact sheet`, `v1`, `Published`, and not `Shared`.
- [ ] E3 · Ada's Materials page lists `E2E fact sheet` with `Download`. The click does not show a warnbox. The file download starts.
- [ ] E4 · Activity has a row for `Ada Agent`: `Material downloaded` and `E2E fact sheet · v1`.

## Notes
A new upload is published immediately. Use any tiny PDF; do not use a real brochure.
