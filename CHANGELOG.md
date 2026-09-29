# Changelog

All notable changes to `APS-Conecta/estadistica` are recorded here.

Format: [Keep a Changelog 1.1.0](https://keepachangelog.com/en/1.1.0/).
Versioning: [Semantic Versioning 2.0.0](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
  ([ADR-0003](docs/adr/0003-every-admin-write-declares-its-delegation.md)).
- **The OCS API**: `fuentes`, `consultas`, `preguntas`, `tableros` and `indicadores`, with a generated
  `openapi.json`.
- The establishment is read from config (`deis_code`, `establishment_type`, `comuna_cut`), which gestion's phase 16
  writes. Without it the app shows the country and the peers, and the admin section says what is missing.
