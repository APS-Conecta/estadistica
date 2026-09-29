# Roadmap — Estadística

Status doc, repo-first SSOT: where the app is and where it is going. The reasoning behind each decision is in its ADR
([`docs/adr/`](docs/adr/)); what went wrong is in [`BUGS.md`](BUGS.md).

## Where we are

**0.1.0 — M1, the REM pillar, is built** and tested on synthetic data. [`README.md`](README.md) § *Status* says what
works; [`CHANGELOG.md`](CHANGELOG.md) lists it. It is a **lab app** in gestion (`dev/lab-apps.sh`), in no release.

## Next

1. **Real data on the dev instance.** Run the job over the default window and load the owner's FONASA export
   through the admin section, then check:
   - more than one establishment per loaded year;
   - the configured center's Serie A row count equals the extract's, recomputed from the zip for `SITE_DEIS`;
   - 2026's A and P códigos with no label: none, or an explicit list;
   - a national consulta with no year filter answers inside the 10 s timeout;
   - FONASA's corte of 31-12-2025 holds every center of the export.
2. **The owner's check in production.** One real month of Metas I–VII against the Estadística-REM unit's own
   calculation, recorded, before staff are told.
3. **Owner questions the 2026 metas file leaves open**, each written in its notes:
   - the pesos are 2025's, because the 2026 OOTT restate none;
   - Meta II's denominator counts mujeres only, while its numerador also counts col02's transmasculine people;
   - Meta VI's numerador is counted in 2026, where the OOTT's text says 2025.
4. **Promotion to `OWN_APPS`**, a gestion change: a release tag and its tarball, the CI clone,
   `check-org-drift.sh`, and the `docs/LICENSING.md` row.

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
