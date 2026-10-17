# AGENTS.md — Estadística

Nextcloud 34 app for APS Conecta Gestión: the REM figures DEIS publishes for every establishment in the country, a center's own beside the national ones and its peers', and the Metas Sanitarias month by month.

## Working rules

- **Names:** code is English, but a domain term keeps the spelling `CONTEXT.md` gives it (*Pregunta* and
  *Tablero* in new names and UI text, never *question* or *dashboard*). Rename an existing name only inside
  code a change already touches, and never a table, column, route, controller action (an OpenAPI operation
  id) or API field. The existing `Question*` and `Dashboard*` classes are this repo's established code
  spelling: keep them.
- **Threat model:** read `docs/threat-model.md` before any security work.
- **Gate before opening a PR** — what `ci.yml` and `docs.yml` run. This repo uses **npm**, not pnpm:
  `git clone --depth 1 https://github.com/APS-Conecta/aps-common.git ../aps-common` if it is missing — aps-common is
  private, so if the clone fails, skip the steps that need it and list them as not run; never edit a workflow to work around it;
  `make aps-drift`; `npm ci`; `npm run lint-js`; `npm test`; `npm run build`, then `git diff --exit-code js/`;
  `make test`; `make instance-up && make smoke`, then `make instance-clean`;
  `python3 .github/repo-docs.py check . --offline`. A step you could not run is named in the PR, never reported as passed.
- **`src/` changed → `npm run build`** and commit the regenerated `js/` (bundles and their `.LICENSE.txt`) in the same PR.
  Never commit `*.js.map` or `node_modules/`.
- **Never edit** `.github/repo-docs.py`, `.github/profiles/*`, `package-lock.json`, `composer.lock`, the dependency lists
  in `package.json`/`composer.json`, `js/` by hand, `openapi.json` (generated from `ApiController` by `scripts/openapi.sh`;
  `make smoke` fails on drift), or the aps-common copies `src/aps/`, `vendor/aps/common/src/`, `tests/stubs/OC/Hooks/Emitter.php`.
- **Strings:** what a person reads — templates, `RefusedException` messages — is Spanish, written as literals the way the
  surrounding code does; this app uses no `t()` / `$l->t()`, so do not introduce them. Code, comments, commits and PR text English.
- **One concern per PR**, under 50 lines of hand-written source (rebuilt bundle excluded), and it contains every change
  its description claims.
