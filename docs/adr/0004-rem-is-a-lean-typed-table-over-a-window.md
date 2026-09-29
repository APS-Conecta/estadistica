# ADR-0004 — REM is a lean typed table over a window, loaded whole per (año, serie)

- **Status:** accepted (2026-09-28)
- **Affects:** `lib/Migration/Version000002Date20260928000000.php`, `lib/Rem/`, `lib/Db/IngestState*.php`,
  `lib/Service/DroppedFiles.php`, `lib/BackgroundJob/RemJob.php`

## Context

DEIS publishes REM Serie A and P for every establishment in the country as one zip per year: 100–246 MB zipped,
members up to 675 MB inflated, 6.56M rows for 2024 Serie A. Research measured three storage shapes on one year:
- typed and wide: ≈ 1.08 GB lean, and a national consulta in 352 / 19.6 ms cold / warm;
- JSONB rows: ≈ 6.3 GB, and ≈ 4.8 s per consulta;
- long (unpivoted): ≈ 5.2 GB, and the wide consulta timed out at 10 s.

The extract also differs by era: SS-LLL codes, `,` decimals, a BOM, data descriptors. And the 2025 Serie A
member is cut by its publisher: 615 establishments missing, the last row cut, the CRC intact.

## Decision

- **One typed table, `estadistica_rem`.** Serie, año, mes, the 6-digit establishment, código, and `col01`…`col50` as
  DECIMAL(20,6), where an empty cell is NULL. The primary key is the natural key. Servicio, región and comuna are not
  stored: they drift by era, and the register holds them once (Slice 4).
- **One transaction per (año, serie).** A DELETE, then `INSERT … SELECT FROM unnest(CAST(? AS …[]))` in 5,000-row
  batches, with the state row in the same transaction.
- **Read by range, never whole.** The central directory comes from the tail, then one member is raw-inflated in
  64 KiB chunks. Its size and CRC are checked against the directory at the end.
- **Completeness is a content property.** These make a load «incompleto»:
  - a last row the publisher cut;
  - unreadable rows;
  - rows of another year;
  - a servicio whose reporting establishments fell below 75 % of the nearest complete year.
- **An incomplete member loads only when no complete copy is stored.** That covers 2025 Serie A, the owner's
  decision. When a complete load is stored, the incomplete member is kept out and the reason shown.
- **One job, one member per hourly tick.** TIME_SENSITIVE, no parallel runs.
- **The admin's dropped zip goes through the same importer.** Files → the FilePicker's path → checked in the
  request → IAppData → the job.

## Consequences

- The default window (current − 3) is ≈ 4.3 GB and rides the whole-database backup. Widening shows its projected
  size first (Slice 1).
- A load takes minutes at ≈ 32k rows/s, inside one cron run. Readers keep seeing the old rows until it commits
  (MVCC), and the replaced member's dead tuples are left to autovacuum.
- A failure never replaces the last good copy and never goes quiet: every attempt writes its outcome to the
  (año, serie) row the admin section lists.
- The ceiling is ≈ 32k rows/s inside OCP. COPY over a second connection would lift it, and is rejected (AD-9, owner).

## Amendment (2026-09-28, Slice 4): labels and establishments join the rows

- **Labels.** `estadistica_code` holds one row per (año, serie, código) from that year's own DICCIONARIO CODIGOS, and
  `estadistica_dictionary` says, per (año, serie), which dictionary that was and what it could not do (Catalog).
  - A year with a readable dictionary reads only its own: códigos and columns change by year (DEIS FAQ).
  - A year without one (2009–2016 ship BIFF `.xls`) takes each código's label from the nearest later year that has
    it, marked «etiqueta de <año>».
  - A código no dictionary explains is «sin descripción», counted per (año, serie) when the serie loads.
  - The rules that read a dictionary were measured on the 2017–2026 files; DictionaryParser states them.
- **Establishments.** `estadistica_establishment` is DEIS's Base de Establecimientos, header-locked by its field
  names, stored once and joined by the six-digit code (Register). A peer group is a filter over it.
- `estadistica_rem` and `estadistica_ingest` are unchanged. A dictionary or register that cannot be read never holds
  back the figures: it is recorded and shown, and the previous one keeps serving.
