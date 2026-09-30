# Roadmap — Estadística

Status doc, repo-first SSOT: where the app is and where it is going. The reasoning behind each decision is in its ADR
([`docs/adr/`](docs/adr/)); what went wrong is in [`BUGS.md`](BUGS.md).

## Where we are

**0.1.0 — M1, the REM pillar, is built** and tested on synthetic data. [`README.md`](README.md) § *Status* says what
works; [`CHANGELOG.md`](CHANGELOG.md) lists it. It **ships**: gestion vendors the `v0.1.0` tarball in `OWN_APPS`
(APS-Conecta/gestion#198, 2026-09-30).
It went through a code review on 2026-09-29: its fixes are in, and what it deferred is in [`BUGS.md`](BUGS.md)
(B-015 to B-020).

On 2026-09-29 it merged to `main`, together with its gestion side (APS-Conecta/gestion#196), and was installed on
gestion's lab: its migrations applied, Administración → Estadística names the site file's DEIS code, and
`make divergence` catches a hand-set one until the next seed puts it back. It was tagged `v0.1.0` the same day: the
owner's check of the metas (item 1 below) gates telling staff, not the release.

The real-data run (acceptance a21) was on gestion's lab rather than the dev instance, on 2026-09-29. The default
window's eight members loaded, 27.3 million rows in 4.9 GB, each checked against DEIS's own zips, and the owner's FONASA
export loaded 2,091 centers. What it found is B-030 to B-032 in [`BUGS.md`](BUGS.md), for M2; none blocks the tag
(owner). The metas of June 2026 for the lab's center, hand-summed in SQL, equal what the app serves.

## Next

1. **The owner's check in production** (gestion's lab, the only deployment). June 2026's Metas I–VII against the
   Estadística-REM unit's own calculation, recorded, before staff are told.
2. **Owner questions the 2026 metas file leaves open**, each written in its notes:
   - the pesos are 2025's, because the 2026 OOTT restate none;
   - Meta II's denominator counts mujeres only, while its numerador also counts col02's transmasculine people;
   - Meta VI's numerador is counted in 2026, where the OOTT's text says 2025.
3. **What the real-data run found** (BUGS B-030 to B-032): a fecha de corte one establishment can move, labels
   borrowed from an earlier year's dictionary, and whole-hoja national consultas at the 10 s timeout. The M2 lap.
4. **What the code review left to the farmacia template** (BUGS B-015 to B-019): the webpack-vue-config major,
   `.htaccess`'s 404s, a pinned and checked `composer.phar`, the browser gate in CI, and the `APS` default in a
   worktree. Fixed in farmacia first, then here.

## Future

- **M2 — staff files as fuentes.** CSV and XLSX a unit keeps, ingested behind an identifier guard that refuses a
  person's data, re-read when the file changes in Files, and queried with the same consulta spec. The contract M1
  keeps for it is that the spec only grows ([ADR-0006](docs/adr/0006-one-consulta-spec-compiled-to-bound-sql.md)).
- **M3 — an AI that asks consultas.** It reads the OCS API; it never writes SQL.
- **Linkable tableros.** A reload returns to Consultar today; hash links (`#/tableros/<id>`) are the upgrade when one
  must be sent (`src/App.vue`).
- **A gestion backup that skips the REM rows**, which the app can fetch again. Left as it is until the default
  window's dump time is measured; a gestion change, for the owner.

Not planned: a clinic selector (gestion ADR-0013, as amended), COMGES, REM planilla filling or validation, and
Series BS, BM and D.
