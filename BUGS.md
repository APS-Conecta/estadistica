# Bugs — Estadística

Fix log: what was wrong, and why the fix is right. Each row names the cause and where the fix lives; the reasoning
that made a fix non-obvious is a comment beside the code it protects, not here. Once this repository's issue tracker
holds a bug, the tracker is the authority for what is open.

## Open

| # | Symptom | Cause | Fix |
|---|---|---|---|
| B-012 | On a phone, the typed-population grid in Administración → Estadística is wider than its section | the grid is 388 px and the section 334 px, and the settings page sets `white-space: nowrap` | the metas grid's treatment (B-010): let the label column wrap and scroll inside the section |
| B-013 | The main bundle is 712,028 bytes against a 720,000 budget | `@nextcloud/webpack-vue-config` builds production with `devtool: 'source-map'`, so every component's `<style>` block ships its whole `.vue` file inline, base64: ≈ 254 KB unminified across both bundles | css-loader `sourceMap: false` in `webpack.config.js`. It is a change to every app cloned from farmacia, so it is the owner's; until then, a component without a `<style>` block is the cheap one |
| B-014 | The docs gate warns «repo docs are mixed; this owner's policy is 'en'» | `docs/manual.md` is Spanish because its reader is staff. repo-docs exempts a doc by file name, and its profile lists only `docs/CONVENTIONS.md` and `GLOSARIO.md` | add `docs/manual.md` to `doc_language_exempt` in repo-docs' `profiles/aps-conecta.json`, as the profile's own rule allows for a staff-facing doc. A repo-docs change; until then the warning is accepted, and it is only a warning |

## Fixed

Found while building M1 (0.0.x), each by running something, and fixed before 0.1.0.

| # | Symptom | Cause | Fix |
|---|---|---|---|
| B-001 | Every route answered 404 (OCS 998) on a fresh dev instance | the web workers cache the routes and the enabled apps in APCu for an hour, and `occ` cannot clear it | `make instance-up` restarts Nextcloud after `app:enable` (farmacia `d4b7e43`) |
| B-002 | `make smoke` and `make openapi` failed on a clean clone | `scripts/smoke.sh` and `scripts/openapi.sh` were written without the execute bit | the clone step sets it, and the mode is committed |
| B-003 | The admin section's «Sin descripción» column and register line always showed their defaults | `loadIngests()` read the dictionaries and the register and never kept them | kept; `src/AdminSettings.test.js` fails on exactly that |
| B-004 | Three gates that could never have passed: smoke's check of the first-year refusal, the no-`isAdmin(` grep, and the no-register-code grep | Nextcloud escapes `año` as `a\u00f1o` in JSON; a docblock spelled `isAdmin(`; a fixture's docblock cited two real establishment codes | smoke matches a body on ASCII only; the docblocks were rewritten |
| B-005 | The HTTP Range test for a server that ignores the range passed without testing anything | the fake server answered «from the start», which is exactly the tail read of a small fixture | it answers one byte late |
| B-006 | An assertion that a value arrives as null passed for `''` | PHPUnit's `with()` compares loosely | `self::isNull()`; found by a surviving mutation |
| B-007 | A pinned list of fuentes broke when the PIV added a fourth | a later slice extended a list an earlier test pinned | every earlier suite runs when a slice adds a fuente, route or column |
| B-008 | Two reads returned rows in whatever order PostgreSQL chose | no `ORDER BY` | ordered |
| B-009 | A single chart panel drew at half width | the panels' grid was `auto-fill`, which keeps an empty track beside a lone panel | `auto-fit`, which collapses it; found by screenshot |
| B-010 | The admin tables overflowed the page | the settings page sets `white-space: nowrap` | the names column wraps, and the table scrolls inside its card |
| B-011 | Smoke read an establishment it had just set, or just removed, and saw the old state | app config reaches the web process about 3 s late, and a delete does too | smoke waits 4 s after each |
