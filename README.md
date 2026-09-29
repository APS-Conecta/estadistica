# Estadística

## What this is

Nextcloud 34 app for APS Conecta Gestión: the REM figures DEIS publishes for every establishment in the country,
labelled from each year's own dictionary, so a center can set its own figures beside the national ones and beside
establishments like it. It also follows the Metas Sanitarias de Atención Primaria month by month. UI in Spanish,
everything else English. AGPL-3.0-or-later.

**It stores only aggregate counts that DEIS and FONASA publish, and touches no patient data.** It fills in no REM,
validates no planilla, and reads no Serie BS, BM or D. Nothing in it chooses which establishment is «ours»: that is
instance configuration ([gestion ADR-0013](https://github.com/APS-Conecta/gestion/blob/main/docs/adr/0013-the-establishment-is-instance-configuration.md)).

| View | What it is | Where it comes from |
| --- | --- | --- |
| **Consultar** | A consulta built in four steps (Datos → Filtrar → Resumir → Visualizar): a REM hoja and sección, prestaciones and columns, grouped by up to two dimensions, the center beside the country and a peer group | DEIS REM Serie A and P, every establishment and month in the window; labels from that year's DICCIONARIO CODIGOS |
| **Preguntas** | Saved consultas, readable by all staff | This app's own tables |
| **Tableros** | Saved preguntas laid out as cards; each card asks its own consulta | This app's own tables |
| **Metas Sanitarias** | Metas I–VIII of the year, one card each, month by month: the center's %, its gaps to the meta comprometida and the meta nacional, and the national and peer % | The year's metas file, the REM routes it names, and FONASA's Población Inscrita Validada |

The admin section, **Administración → Estadística**, holds the window of years, the state of every load, FONASA's
population, the commitments of each year's metas, and a door for files the app cannot fetch. An admin can delegate
it to any group.

## Status

**0.1.0, M1: the REM pillar.** Every piece above is built and tested on synthetic data: the ingest by HTTP Range
and by a dropped zip, the catalogue, the consulta engine and its OCS API, preguntas and tableros, the FONASA import,
and the 2026 metas. `info.xml`, `package.json` and `openapi.json` all read 0.1.0.

It went through a code review on 2026-09-29. Its fixes are in, and what it deferred is in [`BUGS.md`](BUGS.md)
(B-015 to B-020).

Not done yet:

- It is a **lab app** in gestion ([`dev/lab-apps.sh`](https://github.com/APS-Conecta/gestion/blob/main/dev/lab-apps.sh)),
  installed on the lab since 2026-09-29, in no release. 0.1.0 is not tagged: the tag waits for the owner's
  real-data check ([`ROADMAP.md`](ROADMAP.md) § *Next*). Promotion is its own change: a tarball, `OWN_APPS`,
  gestion's CI clone and org-drift check, and a `docs/LICENSING.md` row.
- Real data has not been run through it yet. The full DEIS window and a real FONASA export are the dev instance's
  next check, and the owner compares one real month of Metas I–VII in production before staff are told
  ([`ROADMAP.md`](ROADMAP.md)).
- Staff files (CSV, XLSX) as a fuente are M2 ([`ROADMAP.md`](ROADMAP.md)).

## Quickstart

On a machine with Docker, beside a checkout of [`aps-common`](https://github.com/APS-Conecta/aps-common)
(`../aps-common`). This stands up a throwaway Nextcloud 34 with PostgreSQL 18 on port 8092; it is not the gestion
stack.

1. **Start the instance** (in this repo): `make instance-up`. It enables the app, restarts the web workers and
   ends with `Instance ready at http://localhost:8092 (admin / admin)`.
2. **Check the wiring**: `make smoke`. Every check reads `ok`, and it ends with `All good.`
3. **Open `http://localhost:8092/apps/estadistica`** as `admin` / `admin`. Consultar shows «Todavía no hay cifras
   REM» and the note that no establishment is configured: nothing is loaded yet, and that is right.
4. **Load a year** in Administración → Estadística: put a DEIS zip (`SERIE_REM_<año>.zip`) in Files and choose it
   under «Cargar un archivo a mano». It reports «Recibido: cifras de <año>…», and «Cifras REM cargadas» shows each
   serie as it loads, one per hour. Without a file, the hourly job fetches the window from DEIS on its own,
   wherever Nextcloud's background jobs run.

`make instance-clean` removes the instance and its database.

## Install

A clinic gets Estadística through gestion, not from the app store. Today it is a lab app, so it installs only where
it is cloned, as `gestion/apps/estadistica` (the directory name is the app id). Both commands run from the gestion
checkout:

    git clone https://github.com/APS-Conecta/estadistica.git apps/estadistica
    make fix-mount-perms && make seed

Phase 12 enables a lab app when `apps/<id>/.git` exists, and phase 16 writes the establishment (below).

Nextcloud 34, PHP 8.2–8.5, **PostgreSQL only**: `info.xml` declares it, so an install on another engine is refused at
enable time ([ADR-0002](docs/adr/0002-postgresql-only.md)). The default window of four years takes about 4.3 GB of
database, and the same in each backup.

## Configuration

App config, `occ config:app:set estadistica <key> --value=<value>`:

| Key | What it does | Default | Who writes it |
| --- | --- | --- | --- |
| `deis_code` | The establishment this install serves: six digits. Anything else reads as no establishment | unset: the country and the peers show, the local series does not | gestion phase 16, from `SITE_DEIS`. The app has no setter |
| `establishment_type` | Its tipo, shown in the admin section | unset | gestion phase 16, from `SITE_TIPO` |
| `comuna_cut` | Its comuna's five-digit CUT | unset | gestion phase 16, from `SITE_COMUNA_CUT` |
| `first_year` | The first year of national history kept. Narrowing it deletes the years left out | the current year − 3 | Administración → Estadística, «Años de historia nacional» |

gestion's divergence gate (`scripts/divergence.sh`) reads the three identity keys back against the site file. The job's own keys
(`deis_checked_at`, `deis_etags`, `deis_check_errors`, `deis_register`) are state, not settings.

Who administers Estadística is Nextcloud's admin group plus any group delegated the section, under Administración →
Privilegios de administración or `occ admin-delegation:add 'OCA\Estadistica\Settings\AdminSettings' <group>`
([ADR-0003](docs/adr/0003-every-admin-write-declares-its-delegation.md)).

## How it works

- **One importer, two sources.** The same importer reads DEIS over HTTP Range (central directory first, then only
  the Serie A, Serie P and dictionary members) or a zip an admin dropped. Each (año, serie) loads in one transaction:
  a failure keeps the last good load and says why in the admin section
  ([ADR-0004](docs/adr/0004-rem-is-a-lean-typed-table-over-a-window.md),
  [ADR-0005](docs/adr/0005-the-app-calls-deis-at-run-time.md)).
- **One job.** An hourly `TimedJob` checks DEIS at most once a day and loads one pending member per tick. Nothing
  runs on the host's scheduler.
- **One consulta spec.** The builder, the tableros, the metas and the API all send the same spec. It compiles to
  parameter-only SQL, run `READ ONLY` under a 10 s `statement_timeout`; a spec that names anything else is a Spanish
  422 ([ADR-0006](docs/adr/0006-one-consulta-spec-compiled-to-bound-sql.md)).
- **FONASA by hand.** Its Población Inscrita Validada has no download, so the admin uploads the «Inscritos APS» CSV or
  types the center's own figures; a year with no corte of its own reads the latest earlier one × 1.03
  ([ADR-0007](docs/adr/0007-piv-is-uploaded-and-pro-rated.md)).
- **Metas as a yearly file.** `seeds/metas-2026.json` ships with the app; a later year is a file an admin loads, with
  no release ([ADR-0008](docs/adr/0008-metas-are-a-yearly-seed.md)).

## For other apps

OCS, with `OCS-APIRequest: true`, under `/ocs/v2.php/apps/estadistica/api/v1/`: `fuentes`, `fuentes/{id}`,
`consultas` (POST), `preguntas`, `tableros` and `indicadores`. Every route is readable by any user and rate-limited.
`consultas` takes `{consulta, establecimiento?}`: without `establecimiento`, the local series is the configured one.

The contract is [`openapi.json`](openapi.json), generated by `make openapi` from the controller, never by hand; the
answer of a consulta is only extended, never reshaped ([ADR-0006](docs/adr/0006-one-consulta-spec-compiled-to-bound-sql.md)).
gestion's [`docs/CONTRACTS.md`](https://github.com/APS-Conecta/gestion/blob/main/docs/CONTRACTS.md) lists it.

## Development

- `make test`: the unit suite in a bare PHP container, no Nextcloud.
- `npm run lint-js` and `npm test`: ESLint and Vitest.
- `make aps-drift`: the vendored `aps-common` copies are current (`make aps-sync` refreshes them).
- `make smoke`: the wiring against the dev instance, including «openapi.json regenerates without drift».
- `make test-integration`: the integration suite against the dev instance's PostgreSQL (needs `make deps` first).
  No CI job runs it; run it before a change to `lib/`.
- `make test-browser`: a tablero card's figures against the API's, in Chromium (Playwright for Python on the host).
  No CI job runs it either.
- `npm run build` and commit `js/`: the bundles are committed, and CI fails when they differ from `src/`.

CI (`.github/workflows/ci.yml`) runs `make aps-drift`, `make test`, `npm run lint-js`, `npm test`, the bundle check,
and `make smoke` on a cold instance.

Three traps this app has already paid for:

- **Restart the web workers after `occ app:enable`.** They cache the routes for an hour, and every route answers
  404 until they restart. `make instance-up` does it.
- **App config reaches the web process about 3 s late**, and so does a delete. A check right after `occ
  config:app:set` sees the old value.
- **Every `<style>` block in a component ships the whole `.vue` file** as an inline source map. A component without
  one is much cheaper ([`BUGS.md`](BUGS.md)).

## Licence

AGPL-3.0-or-later ([`LICENSE`](LICENSE)). The REM figures are DEIS's public data, the population is FONASA's, and
the metas are MINSAL's Orientaciones Técnicas. None of them ships in this repository: the app fetches the first, and
an admin loads the second. The 2026 metas file restates the OOTT's routes and metas nacionales.

The notices of the third-party code in the bundles (Vue, Chart.js, the Nextcloud libraries) are
`js/estadistica-main.js.LICENSE.txt` and `js/estadistica-admin.js.LICENSE.txt`, which the build writes beside them.
