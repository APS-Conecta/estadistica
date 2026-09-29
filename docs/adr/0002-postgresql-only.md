# ADR-0002 — PostgreSQL only, declared

- **Status:** accepted (2026-09-28)
- **Affects:** `appinfo/info.xml`, every migration, the loader and the consulta executor

## Context

The engine is PostgreSQL (run plan D2), and two mechanisms depend on it:
- the REM loader binds PostgreSQL array literals to `unnest(?::numeric(20,6)[])`, which was measured at 6.1× a
  multi-row VALUES load in Nextcloud's own driver mode (design, 2026-09-28);
- every consulta runs inside `SET TRANSACTION READ ONLY` and `SET LOCAL statement_timeout`.

With no `<database>` element, Nextcloud assumes the app must also run on Oracle and enforces Oracle's naming rules
(`MigrationService::__construct`). No suite app declares one.

## Decision

`info.xml` declares `<database min-version="18">pgsql</database>`. MetadataTest pins it.

The attribute is load-bearing. Without one, Nextcloud's InfoParser hands `MigrationService` the bare string `"pgsql"`,
and its `foreach` over it emits a PHP warning on every migration run. With one, the value is the
`{@attributes, @value}` array that both `MigrationService` and `DependencyAnalyzer` expect (measured on the
`nextcloud:34` image, 2026-09-28).

## Consequences

- An install on MySQL or SQLite is refused when the app is enabled, not at the first consulta.
- The Oracle checks are off: identifier length is PostgreSQL's (63 bytes, ≤ 60 for app tables under `oc_`). Primary
  keys and lesson #74's defaults stay, because they are this org's rules, not Oracle's.
- Estadística is the first suite app to declare an engine. A second app doing so should cite this ADR rather than
  rediscover it.
