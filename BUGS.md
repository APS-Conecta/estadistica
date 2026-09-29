# Bugs — Estadística

Fix log: what was wrong, and why the fix is right. Each row names the cause and where the fix lives; the reasoning
that made a fix non-obvious is a comment beside the code it protects, not here. Once this repository's issue tracker
holds a bug, the tracker is the authority for what is open.

## Open

| # | Symptom | Cause | Fix |
|---|---|---|---|
| B-012 | On a phone, the typed-population grid in Administración → Estadística is wider than its section | the grid is 388 px and the section 334 px, and the settings page sets `white-space: nowrap` | the metas grid's treatment (B-010): let the label column wrap and scroll inside the section |
| B-013 | The main bundle is 711,997 bytes against a 720,000 budget | `@nextcloud/webpack-vue-config` builds production with `devtool: 'source-map'`, so every component's `<style>` block ships its whole `.vue` file inline, base64: ≈ 254 KB unminified across both bundles | css-loader `sourceMap: false` in `webpack.config.js`. It is a change to every app cloned from farmacia, so it is the owner's; until then, a component without a `<style>` block is the cheap one |
| B-014 | The docs gate warns «repo docs are mixed; this owner's policy is 'en'» | `docs/manual.md` is Spanish because its reader is staff. repo-docs exempts a doc by file name, and its profile lists only `docs/CONVENTIONS.md` and `GLOSARIO.md` | add `docs/manual.md` to `doc_language_exempt` in repo-docs' `profiles/aps-conecta.json`, as the profile's own rule allows for a staff-facing doc. A repo-docs change; until then the warning is accepted, and it is only a warning |
| B-015 | `npm audit` reports GHSA-w5hq-g745-h8pq (uuid < 11.1.1, moderate) and GHSA-848j-6mx2-7j84 (elliptic, low, no fix), dev-only | both come through `@nextcloud/webpack-vue-config` 6.3.2 (webpack-dev-server, node-polyfill-webpack-plugin); `npm audit --omit=dev` is clean, nothing reaches `js/` | the semver-major 7.x, in the farmacia harness first and then its clones (code review D1) |
| B-016 | `.htaccess` answers 403 for `composer.json`, `package.json`, `openapi.json` and `Makefile`, while its own comment promises 404; `scripts/smoke.sh` accepts either | the `<FilesMatch>` block uses `Require all denied`; only the directory rule rewrites to 404. Verbatim from farmacia | a 404 rewrite for those files and an exact 404 in smoke, in the farmacia harness first (code review Q8) |
| B-017 | `scripts/openapi.sh` runs a `composer.phar` it downloaded unpinned and unverified, also in CI's smoke job | `curl … composer-stable.phar` with no checksum; the same line is in farmacia, territorio and epidemiologia | pin a version and check getcomposer.org's published sha256, once in the harness (code review S1) |
| B-018 | The browser gate (a15, a8) never runs in CI | `make test-browser` needs Playwright and a headless Chromium, and no CI job has them; the same in farmacia | a harness-level CI job (code review Q9) |
| B-019 | `make test` in a `.worktrees/` checkout deletes the committed `vendor/aps/common/src` | `APS ?= $(CURDIR)/../aps-common` resolves to an empty directory Docker creates | pass `APS=` in a worktree; the harness default should refuse a missing path (code review Q19) |
| B-020 | Early in a year, the year in progress may be flagged «incompleto» | Completeness compares each servicio's reporting establishments with the nearest complete year, whole | not seen on the a21 run (lab, 2026-09-29), and it could not be: the job loads the newest year first, so 2026 loaded with no neighbour to compare with. Its first test is DEIS republishing 2026, which the daily check reloads against the nearest complete year. If it shows, compare against the neighbour's same months (code review Q16) |
| B-030 | The Metas view says «cifras hasta septiembre de 2026», and every Serie A meta repeats August in September | the fecha de corte is the highest month of any row (`RemReader`'s `lastMonth`). In DEIS's 2026 extract (published 2026-09-25) one establishment, 200366, filed 551 September rows; each earlier month has ≈ 2,975 | a corte most establishments reached, such as the last month with at least `Completeness::RATIO` of the month before's; for the local series, its own last reported month. M2, found on the a21 run |
| B-031 | 2026 Serie A shows 3 códigos «Sin descripción»: 08210001 and 08210002 (1,646 and 554 rows, from 290 and 134 establishments) and 09601168 (107 rows, 58) | the 2026 dictionary (SA_26_V1.2) dropped 08210001 and 08210002, which 2023–2025 label «Médico» and «Enfermera/o», and a year borrows labels only from a later dictionary (`Catalog`). No dictionary has 09601168 | borrow from the nearest earlier dictionary too, after the later ones, noted «etiqueta de <año>»; 09601168 stays until DEIS labels it. M2; the owner chose this over an exception list (2026-09-29) |
| B-032 | A national consulta over the whole window that sums a whole hoja is cancelled at the 10 s timeout (a clean 422, in Spanish) with all 50 columns, and takes 8–10 s with 4. A consulta that names its códigos takes 3.3 s (Meta I's numerador, local, nacional and pares, 2023–2026) | each national series reads a hoja's rows across 27.3 million (4.9 GB) with the lab's PostgreSQL defaults (`shared_buffers` 128 MB, `work_mem` 4 MB). The only indexes are the primary key and `(estab, anio)` | measure first, `EXPLAIN (ANALYZE, BUFFERS)` of the compiled statement; then PostgreSQL sizing in gestion's stack, or national monthly totals per código that the job keeps. M2; not a blocker for 0.1.0 (owner) |

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

Found by the M1 code review (`.rpiv/artifacts/reviews/2026-09-29_17-19-04_estadistica-m1-bbcc1fd-p10.md`), and fixed
before 0.1.0.

| # | Symptom | Cause | Fix |
|---|---|---|---|
| B-021 | A year widened back into the window never reloaded | RemJob forgot a year's rows before its kept ETag, so a tick that died in between left the ETag, and DEIS answered 304 | the ETag and the drop go first, the rows last (`RemJob::run`) |
| B-022 | A dropped zip was lost when its load failed on our side, and DEIS's copy replaced it | the job removed the drop once nothing of its year was pending, whatever the outcome | the drop stays while a serie of its year broke on our side (`RemImporter::brokeOnOurSide`) |
| B-023 | A drop's second serie could be left unqueued beside a file the job then removed | the series were queued one statement at a time after the file was stored | one upsert queues them all (`IngestStateMapper::markPending`) |
| B-024 | A re-drop during the job's removal, or two first drops, failed | the zip and the drops folder were written after a look (check-then-act) | `newFile` writes over; a folder another request made is used (`DroppedFiles`) |
| B-025 | A consulta's labels could describe another dictionary than its figures, and four reads ran with no timeout | the códigos, peers, labels and fuente were read outside the read-only transaction | all of `answer()` runs in `readOnly()` |
| B-026 | A dictionary with a sheet name over 16 characters failed whole with a database error | the parser took any sheet named like a hoja, and `hoja` holds 16 | the sheet is skipped and counted, with unreadable códigos (`DictionaryParser`) |
| B-027 | A register whose first header-matching sheet was empty was refused | the parser refused at the first such sheet | it reads on to the next (`RegisterParser`) |
| B-028 | A database error on a shipped seed failed `occ app:enable` | `EnsureSeedData` caught only the parser's refusals | every failure is reported and skipped |
| B-029 | A slow catálogo answer could overwrite a newer choice in the builder, and a refused «add card» lost the choice | no request order in `loadCatalog()`; `add()` cleared unconditionally | a request number; clear only on success |
