# ADR-0009 — The Resumen asks the newest metas over every comparable year, once per change

- **Status:** accepted (2026-11-05, owner grill)
- **Affects:** the Resumen view, the 2027 metas seed, `lib/Goals/`, a new OCS `resumen` route, `lib/BackgroundJob/`

## Context

- Staff open Estadística on a four-step builder. Nothing shows their own metas this year beside past years, and a
  saved pregunta keeps absolute months, so it never moves to the next year (ADR-0006 amendment).
- The reference the owner gave is SSMO's «Metas Sanitarias 2026» Power BI report. Each meta has a percentage with
  «N de D», red or green against the meta, and a monthly chart. It has no past years, and it shows a month not loaded
  as 0,00 %.
- Res. Exenta 732 (Diario Oficial, 2026-10-07) fixes the 2027 metas. Metas III, V, VI and VII measure something
  other than 2026's metas of the same number. Each meta comprometida 2027 follows a rule over the center's December
  2026 result.
- The diccionario changes every year: between 2025 and 2026, Serie A dropped 20 códigos and moved the columns of 68.
- The owner requires the numbers to be deterministic and certain. National consultas reach the 10 s limit (#27).

## Decision

- **One set of metas: the newest resolución's.** The Resumen shows only the metas of the newest seed. The official
  result of each past year stays in the metas view, under its own year's seed (ADR-0008, unchanged).
- **The histórico shifts periodos by whole years.** A ruta asked over an earlier year moves each periodo by the same
  number of years. Meta I's October-to-September window moves with it.
- **Only official rutas.** A meta shows once its ruta is oficial, from that year's OOTT (Anexo 3). Until then a
  prepared ruta may ship in the seed, but the meta shows «esperando ruta oficial».
- **Only comparable years.** A year counts only when every código × columna of the ruta carries the same label in
  that year's diccionario as in the metas year's. Labels are compared after folding case, accents and plurals. No
  mapping of ours stands in. Any other year shows «sin datos comparables», with nothing estimated.
- **Computed once per change, then read.** After each load of REM or of a planilla, and only then, the job asks every
  ruta of every comparable year for the establishment, with the same statements a consulta compiles to (ADR-0006).
  It stores the results with their sources. The view and `GET /api/v1/resumen` read only what was stored, so every
  reader sees the same numbers until the next change.
- **Only the establishment.** The Resumen sets each meta beside the establishment's own past years only. The
  nacional and the pares stay in the metas view and the builder.
- **Each meta shows:** its percentage and «N de D» for the last month with a figure, whether it cumple, one line per
  comparable year with the meta as a line, and the table under the chart. Clicking a number shows its hoja, códigos,
  columnas, ruta and the date of its fuente. Sin informar is shown as a gap, never as 0.
- **People are counted only from official figures** (ADR-0007 amendment). A meta whose age range cuts a PIV band
  shows a percentage only where the admin typed that year's denominador comprometido.
- **Meta mínima from the last cierre.** The minimum each meta's rule allows is computed from the last complete
  year's cierre, under the newest metas, and is shown as a reference. Meta II's rule depends on whether the center
  reached its previous meta comprometida. Without one typed, the Resumen applies the stricter branch and says why.

## Consequences

- The metas view and the Resumen can show different numbers under the same meta number. Each Resumen label names
  its metas year («Meta V 2027»).
- ADR-0008's ruling of a table and no chart stays for the metas view. The Resumen draws charts.
- A figure changes only when DEIS or a planilla changes, and it changes for every reader at once.
- Until MINSAL publishes the 2027 OOTT, the 2027 metas wait. Once they are published, each prepared ruta is checked
  against Anexo 3 and marked oficial in a seed with a higher version (ADR-0008).
- Metas II–IV stay empty in the Resumen, past years and meta mínima included, until the admin types those years'
  official denominators. The Resumen names the year and meta each one is missing.
- A past year drops out as «sin datos comparables» whenever DEIS relabels a código of the ruta, even cosmetically
  beyond case, accents and plurals.
