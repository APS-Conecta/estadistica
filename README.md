# Estadística

## What this is

A Nextcloud 34 app for APS Conecta: the REM figures DEIS publishes for every establishment in the country, labelled
from each year's own dictionary, so a CESFAM can set its own figures beside the national average and beside
establishments like it; and the month-by-month progress of the Metas Sanitarias. Every staff member reads it.

What it is not: a patient record. It stores only the aggregate counts DEIS and FONASA publish. It is also not
multi-tenant: one install serves one establishment, and which one is instance configuration, not code
([gestion ADR-0013](https://github.com/APS-Conecta/gestion/blob/main/docs/adr/0013-the-establishment-is-instance-configuration.md)).

- **Decisions** — [`docs/adr/`](docs/adr/). Read the ones touching what you are about to change.

## Status — 2026-09-29

Version 0.0.1, the first of M1's ten phases. `appinfo/info.xml` holds the version; `package.json` repeats it and
MetadataTest fails if the two disagree.

Working: the app page, which says when no establishment is configured and that there are no REM figures yet; the
admin section, which shows the configured establishment (or the notice that there is none) and saves the first year
of national history the install keeps; and the delegation of that section to any group an admin chooses.

Not working yet: everything that holds figures. The REM ingest, the DEIS catalogue and register, the consultas, the
visual builder, preguntas and tableros, the FONASA PIV and the Metas Sanitarias arrive in M1's later phases.

## Quickstart

Everything runs from this directory, with [aps-common](https://github.com/APS-Conecta/aps-common) checked out beside
it (`../aps-common`; elsewhere, pass `APS=<path>` to make). Steps 1, 3, 4 and 6 need Docker; step 2 needs Node.

1. `make test` — the PHP unit suite in a bare container. `--testdox` prints one line per behaviour and exits 0.
2. `npm ci`, then `npm test` and `npm run build` — the js suite, then webpack writes
   `js/estadistica-{main,admin}.js`.
3. `make instance-up` — a throwaway Nextcloud 34 + PostgreSQL 18 with this directory bind-mounted as the app. It
   prints `Instance ready at http://localhost:8092 (admin / admin)`.
4. `make deps`, then `make integration` — the integration suite inside that instance, against its real PostgreSQL
   (local only: CI does not run it).
   `make smoke` then checks the wiring no unit test can see, and ends with `All good.`
5. Open <http://localhost:8092>, log in as `admin` / `admin`, and pick **Estadística** in the header.
6. `make instance-clean` when you are done — it removes the containers and their volumes.

## Install

Nextcloud **34 only**, PHP 8.2 to 8.5, and **PostgreSQL only**: `appinfo/info.xml` declares
`<database min-version="18">pgsql</database>`, so enabling the app on another engine is refused
([ADR-0002](docs/adr/0002-postgresql-only.md)).

The release is `git archive` of the tracked tree: `js/` is committed and aps-common ships as the committed
`vendor/aps/common/src`, so no build step or composer install runs on the server. It is enabled with
`occ app:enable estadistica`.

## Configuration

- **The establishment** — three app-config keys, `deis_code` (six digits), `establishment_type` and `comuna_cut`
  (five digits). The installer's provisioning writes them and the app only reads them. Unset or malformed reads as
  "not configured": the pages still answer, and the admin section says what is missing.
- **The years of national history** — `first_year`, saved from the admin section. Unset, it is the current year and
  the three before it; it can be widened back to 2009.
- **Who administers the section** — any group an admin delegates it to, with
  `occ admin-delegation:add 'OCA\Estadistica\Settings\AdminSettings' <group>` or under Administration privileges. No
  group name lives in the app ([ADR-0003](docs/adr/0003-every-admin-write-declares-its-delegation.md)).

## Licence

AGPL-3.0-or-later ([`LICENSE`](LICENSE)). The bundled JavaScript's third-party notices ship beside it, in
`js/*.LICENSE.txt`.
