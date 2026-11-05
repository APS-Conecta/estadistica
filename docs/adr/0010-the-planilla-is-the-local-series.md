# ADR-0010 — The establishment's planilla is its local series wherever it exists

- **Status:** accepted (2026-11-05, owner grill)
- **Affects:** a new planilla reader in `lib/Rem/`, the local series of `rem-a` and `rem-p`, the admin section,
  `lib/BackgroundJob/`

## Context

- DEIS publishes a month 3–4 weeks after it closes (September 2026 appeared on 2026-10-23). It then rewrites earlier
  months: about 2–3 % of a month's Serie A rows change in later files.
- A center fills its REM planilla during the month. Servicios de Salud already report from it: SSMO's metas report
  cites «Fuente: REM - Local».
- The owner ruled that the planilla comes first: «we base ourselves locally, from the most updated», and «the excel
  planilla will be filled».
- Every figure must be official (owner). The planilla is: it is the establishment's own REM, the report it sends to
  its Servicio de Salud.
- ADR-0006 expected M2's file fuentes (#31) to add a second branch under the same consulta spec.

## Decision

- **The planilla is DEIS's own form**, `.xlsm` or `.xlsx`, for one serie (A or P) and one month. The establishment
  saves it in the folder the admin chooses. It is read by the same layout as the diccionario, which is DEIS's form
  for that year, and its macros never run.
- **Refused whole, with a Spanish reason,** when its establishment code is not the configured one (suite ADR-0009), or
  its form version does not match that year's diccionario.
- **The newest wins.** For each serie and month, the newest saved planilla is the local series. DEIS's figures for
  that serie and month do not count for the establishment. A blank cell in the planilla is sin informar, even where
  DEIS has a number.
- **DEIS fills only what no planilla covers.** A serie and month with no planilla reads DEIS. The nacional and the
  pares always read DEIS.
- **Every month says where it came from:** «planilla del establecimiento» with the file's date, or «datos públicos
  DEIS» with the zip's date.

## Consequences

- The establishment's figures can differ from the ones the SEREMI evaluates, which come from DEIS. The Resumen says
  which month came from which source, and does not reconcile them.
- A planilla left as it was keeps counting after DEIS corrects the month. Correcting it is the establishment's job,
  by saving the planilla again.
- A planilla saved mid-month makes that month partial, and the month shows what it holds. The Resumen states the
  month's source and date; it never fills in the rest from DEIS.
- A consulta's local series becomes DEIS overlaid by planillas. The nacional, the pares and the informantes are
  unchanged.
