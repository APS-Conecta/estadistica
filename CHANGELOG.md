# Changelog

All notable changes to `APS-Conecta/estadistica` are recorded here.

Format: [Keep a Changelog 1.1.0](https://keepachangelog.com/en/1.1.0/).
Versioning: [Semantic Versioning 2.0.0](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.3.0] - 2026-11-10

The Resumen (spec #41): Estadística opens on the establishment's Metas Sanitarias of the newest resolución de metas — 2027's, Res. Exenta 732 — counted from official figures only, over every comparable year, with its own planilla as the local series wherever one exists.

### Added

- **The Resumen** (#61): the view Estadística opens on, where Consultar was first before. One card per meta of
  the newest resolución de metas, for the establishment alone: its percentage, «N de D», «Datos al» its own last
  month in the meta's serie, and «Cumple» or «No cumple» in colour and in words; a meta with no figure says why
  («esperando ruta oficial», «falta el denominador oficial de <año>»). Its current year is the latest with
  figures, so the 2027 metas count over 2026's until 2027 has some. Each card equals `GET indicadores`' local
  month, and the new `GET /api/v1/resumen` serves the cards
  ([ADR-0009](docs/adr/0009-the-resumen-asks-the-newest-metas-over-every-comparable-year.md)).
- **The Resumen is stored once per change** (#62, #67, #69): `estadistica_resumen` keeps the configured
  establishment's Resumen with its sha256 fingerprint and build time, and `GET /api/v1/resumen` serves it. A
  committed REM load, a commitments write, a FONASA file or a typed population, a metas seed, and a planilla read
  or forgotten each queue one `ResumenJob` inside their own transaction, so a rolled-back write queues none. A
  failed rebuild keeps the stored Resumen, and the admin section shows when the one in use was computed and the
  failure's Spanish reason. Any other `establecimiento` is built on request and never stored.
- **Histórico** (#63): each card's meta asked over every year of the ventana, its periodos moved by whole years. A
  year is comparable when every código × columna of the ruta carries the same label in that year's own
  diccionario (case, accents and plurals folded) and its periodos lie whole in the ventana; otherwise it shows
  «sin datos comparables». Serie P is read at its own June and December cortes: a corte not reported is sin
  informar and leaves no cierre. A chart with a line per year (the most recent emphasised), the meta line and the
  gaps, and a table under it.
- **A source panel for every figure** (#65): each figure of a card and of its histórico table, each year's
  «Cierre» included, opens the app sidebar for its month: origen and fecha de corte, that year's ruta (fuente,
  periodo, and the códigos × columnas added and subtracted, with hoja, sección and labels from that year's
  diccionario) and the metas' referencia. Escape closes it, and focus returns to the figure.
- **Metas Sanitarias 2027** (#64): `seeds/metas-2027.json` ships Res. Exenta 732's Metas I–VIII as written, metas
  nacionales 90/80/65/26/50/72/80/100 (VIII informed by hand) and no pesos. Every REM ruta is prepared from the
  2026 diccionarios and marked `"oficial": false`, and each meta's `nota` cites the labels it reads and every
  reading choice. A seed's REM ruta may carry `oficial` (#61), `peso` is optional, and a meta's `nota` may run to
  1000 characters.
- **Meta mínima** (#68): each card of the newest metas shows the lowest meta comprometida Res. Exenta 732 allows,
  from the cierre of the last year whose December is published, computed exactly and rounded half up to one
  decimal, with its base year («Desde el cierre 2025.»). The rules ride the seed as data (`meta_minima`: `brecha`,
  `brecha_si_cumplio`, `piso`, `fija`). Meta II reads the base year's stored meta comprometida, and without one
  takes 40 % and says so. A meta with no figure shows no minimum and says why. It is a reference: the Servicio de
  Salud fixes the meta comprometida.
- **Planillas del establecimiento** (#59, #67): an admin or a delegate chooses a folder in their own Files
  (`PUT /admin/planillas`). Each hourly `RemJob` tick first reads its new and changed REM forms (`.xlsm` or
  `.xlsx`, macros never run) as that person, and the admin section lists each file as «Leída», «Reemplazada» or
  «Rechazada» with its Spanish reason; a failing folder never stops the REM work. For each serie and month the
  newest leída planilla of the establishment is its local series, and a blank cell is sin informar: one overlay
  (`QueryCompiler::local()`) carries it into the consultas, the metas view, «Datos al» and the Resumen, whose
  month then says «planilla del establecimiento» with the file's day. The nacional, the pares and their
  informantes stay DEIS's ([ADR-0010](docs/adr/0010-the-planilla-is-the-local-series.md)).
- **The nota** (#70, #72): after a rebuild that changes the Resumen's figures, and only when a Nextcloud
  TaskProcessing provider offers `core:text2text`, one task asks for a short Spanish nota on what changed, from
  the card figures alone. The answer is kept only when every number in it, as es-CL writes it, is a figure the
  stored Resumen shows, a year it names or a month with its year; when it writes no number in words («cinco»,
  «el doble», «la mitad»); and while the Resumen it was asked for is still the stored one. It shows at the top of
  the Resumen as plain text, labelled «Escrita por un modelo de IA a partir de las cifras», and
  `docs/threat-model.md` records how it is bounded.

### Changed

- **Each tab loads its code the first time it opens** (#57, #61): Resumen, Consultar, Preguntas, Tableros and
  Metas Sanitarias are chunks of their own (the main bundle went from 713,705 to 327,875 bytes at #57), loaded
  from where Nextcloud serves the app (`custom_apps/`). A chunk that cannot load leaves «No se pudo cargar esta
  sección…» in its tab's place. `make bundles` asserts every tab's chunk, CI, the release build and the smoke run
  it, and the smoke checks that the instance serves every `js/*.js`.
- **No prorrateo** (#60): a FONASA figure counts only for whole age bands (ADR-0007's amendment). A meta whose age
  range cuts a band has no percentage, local, nacional or pares, and says «Sin porcentaje: falta el denominador
  oficial de <año>»; its denominador comprometido, typed in the admin section, gives the local series one. Whole
  bands and the ×1.03 of a year without its own corte are unchanged. In the API, `poblacion.prorrateado` gives way
  to `poblacion.falta_oficial`.
- **Docs** (#39, #40, #56): the gestion-era citations point at the suite (gestion ADR-0013 → suite ADR-0009, the
  documentation site's contracts page, the suite's provisioner). `CONTEXT.md` gains the Resumen's vocabulary,
  ADR-0009 and ADR-0010 are new, and ADR-0007 is amended: no prorrateo.

### Fixed

- **A meta's local series ends at the establishment's own last month** (#58): a Serie A meta repeated the
  establishment's last month into the months after it, up to the fuente's fecha de corte. Those months now have
  no figure, the metas view names the establishment's own last month, and `GET indicadores` gains
  `fecha_corte_local` per serie. The nacional, the pares and the fuente's fecha de corte are unchanged.
- **Dependency advisories** (#75): every critical and high npm advisory is patched: dompurify, follow-redirects
  and source-map-js in the served bundle; shell-quote, undici, vite and launch-editor in the build tools.

**Upgrade notes.**

- **Four migrations.** `Version000011` (#59) gives `estadistica_code` each código's `fila` and `celdas` in its
  diccionario's sheet, and adds `estadistica_planilla` (each file in the folder and what reading it found) and
  `estadistica_planilla_rem` (a leída planilla's figures, kept apart from DEIS's). `Version000012` (#62) adds
  `estadistica_resumen`, the stored Resumen. `Version000013` (#67) adds `estadistica_planilla.estab`, the
  establishment a planilla names. `Version000014` (#70) adds `estadistica_resumen.nota`.
- **One-off background work.** Diccionarios stored before 0.3.0 have no layout, so `Version000011` queues every
  loaded (año, serie) once (#59): `RemJob` loads each again from the admin's drop or from DEIS, one per hourly
  tick, newest year first — about two ticks per year of the ventana. Until its year is loaded again, a planilla
  shows «Rechazada: No hay diccionario…», and it is read again by itself once it is. The upgrade's repair step
  loads the 2027 seed, which queues the first Resumen build for the next cron run (each reload queues another);
  until one succeeds, `GET /api/v1/resumen` computes the Resumen on every request and stores nothing.
- **Metas II–IV of 2027** count FONASA inscritos over ages that cut its bands (mujeres 25–64, 0–2, 6), so with no
  prorrateo (#60) they show no percentage until an admin types each one's «Denominador comprometido» in
  Administración → Estadística → Metas Sanitarias. The same holds for every 2026 meta whose ages cut a band.
- **The 2027 metas wait for the OOTT 2027.** Until their Anexo 3 confirms the prepared rutas (#52) and a seed
  marks them oficial, Metas I–VII show «Sin porcentaje: esperando ruta oficial.» and no meta mínima; Meta VIII is
  informed by hand.
- **The planilla folder** is read only once an admin or a delegate chooses it in Administración → Estadística →
  «Planillas del establecimiento». It is read as that person, so it must stay in their Files.
- **The nota** appears only once the suite offers a text-generation provider (gestion-aio#75), and then from the
  next rebuild that changes a figure; until then `nota` is null and nothing shows.

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
