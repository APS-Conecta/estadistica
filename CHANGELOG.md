# Changelog

All notable changes to `APS-Conecta/estadistica` are recorded here.

Format: [Keep a Changelog 1.1.0](https://keepachangelog.com/en/1.1.0/).
Versioning: [Semantic Versioning 2.0.0](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.2.0] - 2026-10-25

The first signed release: the suite installs the app from this repository's own GitHub Release (instalador #40), and only when the establishment chooses it in the wizard's «Aplicaciones opcionales» — its DEIS figures arrive when the app does, downloaded by the app itself after install.

### Added

- **Docs alignment**: the README pins its OCS citation to server 34 and `appinfo/info.xml` gains a
  `<documentation>` element with user, admin and developer links.
- **Saving without a session answers 403**: `POST /api/v1/preguntas` and `POST /api/v1/tableros`
  return 403 with a message, not a server error, when no user is signed in.
- **Dashboard card buttons name their card**: «Subir», «Bajar» and «Quitar» carry an aria-label
  with the card's question title.

### Changed

- **First signed release (0.2.0).** Releases are built and signed by the org's shared
  workflow (instalador #31), called from `.github/workflows/release.yml` pinned by commit
  SHA: a pull request builds the frontend and packs the tarball; a `v*` tag — which must
  equal `v` + the `appinfo/info.xml` version — signs `SHA256SUMS` with the app key inside
  the repo's `release` environment and publishes exactly four assets (`estadistica.tar.gz`,
  `info.xml`, `SHA256SUMS`, `SHA256SUMS.minisig`), proven against the suite's `apps.pub`.
  The tarball's contents come from `.nextcloudignore`, added with this change: the
  development tree (`src/`, `tests/`, `docs/`, `scripts/`, `tools/`, the agent files, the
  configs) stays out; `appinfo/`, `lib/`, `templates/`, `img/`, `seeds/` (the Metas
  Sanitarias the migrations load at enable), the built `js/`, the vendored
  `vendor/aps/common/src` and the licences travel.

  **`js/` is build output now, not a committed artifact.** The four tracked bundle files
  (two bundles and their terser `.LICENSE.txt` notices) leave git and `js/` is ignored:
  the release builds the bundles before packing, and `pack-app` refuses a committed one.
  CI's rebuild-no-drift arm goes with them — CI now fails a PR whose build produces no
  bundle, the same refusal the packer makes — and installing from a clone needs
  `npm ci && npm run build` first (README says so).

  **The vendored docs checker and canon are gone** (instalador #40, S2a — aps-common#10's
  shape): the checker, its profiles, the `docs-es` marker and the documentation workflow
  under `.github/`, the issue forms, the pull-request template and the code-owners file —
  the org defaults in `APS-Conecta/.github` serve the community files now. No `guard.yml`:
  the repo is public, so secret scanning and push protection are GitHub's, not a
  workflow's. Every mention fixed; the history above keeps its own words.
- **Docs in Spanish**: `README.md` is rewritten in Spanish to the five-section contract and points to the
  documentation site; the repo opts into the Spanish-docs regime (`.github/docs-es`), with the checker and
  profiles refreshed to the current canon (repo-docs ADR 0006). `appinfo/info.xml`'s `<documentation>`
  links now point to the documentation site.

## [0.1.0] - 2026-09-29

The first version: M1, the REM pillar. The 0.0.x versions before it were its slices, and none was released.

### Added

- **REM ingest** from DEIS over HTTP Range, or from a zip an admin drops: Serie A and P for every establishment and
  month in a window of years, one transaction per (año, serie), a last good load kept through any failure, and its
  state in the admin section ([ADR-0004](docs/adr/0004-rem-is-a-lean-typed-table-over-a-window.md),
  [ADR-0005](docs/adr/0005-the-app-calls-deis-at-run-time.md)).
- **One hourly `TimedJob`**: DEIS checked at most once a day, one pending member loaded per tick.
- **The catalogue and the register**: each year's DICCIONARIO CODIGOS, with «sin descripción» counted, and DEIS's
  Base de Establecimientos for the peer groups.
- **The consulta engine**: one spec, parameter-only SQL, `READ ONLY` under a 10 s `statement_timeout`, a Spanish 422
  for anything else ([ADR-0006](docs/adr/0006-one-consulta-spec-compiled-to-bound-sql.md)).
- **The builder** (Consultar): Datos → Filtrar → Resumir → Visualizar, the center beside the country and a peer group.
- **Preguntas and tableros**, readable by all staff, changed by their author or by whoever administers Estadística.
- **FONASA's Población Inscrita Validada**: the «Inscritos APS» CSV, header-locked, or the center's own figures typed
  by hand ([ADR-0007](docs/adr/0007-piv-is-uploaded-and-pro-rated.md)).
- **Metas Sanitarias 2026**, I–VIII, shipped as `seeds/metas-2026.json` and loaded by a repair step; month by month,
  with the gaps to the meta comprometida and the meta nacional, and the national and peer %. A later year loads as a
  file ([ADR-0008](docs/adr/0008-metas-are-a-yearly-seed.md)).
- **The admin section**, delegable to any group, every write declared with `#[AuthorizedAdminSetting]`
  ([ADR-0003](docs/adr/0003-every-admin-write-declares-its-delegation.md)). It shows where each REM year came from,
  and asks before a later first year lets loaded or waiting years go.
- **The OCS API**: `fuentes`, `consultas`, `preguntas`, `tableros` and `indicadores`, with a generated
  `openapi.json`.
- The establishment is read from config (`deis_code`, `establishment_type`, `comuna_cut`), which gestion's phase 16
  writes. Without it the app shows the country and the peers, and the admin section says what is missing.
