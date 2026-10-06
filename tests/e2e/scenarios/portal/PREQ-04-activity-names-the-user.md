# PREQ-04 · Portal activity names the user
- **Tags:** sprint-13, portal
- **Priority:** P2
- **Batch:** B17
- **Users:** Carolina + Ada Agent
- **Start:** reset

## Why
The agency's portal activity lists sign-in, a request and a download, each with the agency user's name. A failed sign-in is a separate row and does not include the reason.

## Steps
1. This scenario resets unless you are already in a session that contains Ada's sign-in, a portal request and a material download, with no later `reset.sh`. If you are, skip to step 5.
2. As Carolina, open Blue Latitude Travel and upload a small PDF titled `E2E fact sheet`, kind `Fact sheet`.
3. As Ada, open `http://localhost:3002/login` and submit `ada@portal.test` / `wrong-password` once, then sign in with `password`.
4. Download `E2E fact sheet` from `/materials`. On `/availability`, search check-in **21 Dec 2026**, check-out **25 Dec 2026**, adults `2`, and send a Family request (client `E2E Guest`, `e2e-preq04@portal.test`, client-of-record ticked). Note the reference.
5. As Carolina, open Blue Latitude's **Portal activity**.

## Expected
- [ ] E1 · One row is `Ada Agent` · `Signed in`.
- [ ] E2 · One row is `Ada Agent` · `Request created` and the reference from step 4.
- [ ] E3 · One row is `Ada Agent` · `Material downloaded` · `E2E fact sheet · v1`.
- [ ] E4 · The failed attempt, if you did step 3, is `Ada Agent` · `Failed sign-in`. The row does not say why it failed.

## Notes
Activity is newest first. Sign-out, the invitation and the password reset are not in this list. A failed sign-in for an unknown address has no agency row, so it does not appear here.
