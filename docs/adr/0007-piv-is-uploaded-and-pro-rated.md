# ADR-0007 — The PIV is uploaded, kept per corte, and pro-rated

- **Status:** accepted (2026-09-29)
- **Affects:** `lib/Piv/PivReader.php`, `lib/Piv/PivImporter.php`, `lib/Piv/Population.php`,
  `lib/Service/DroppedFiles.php`, `lib/Controller/AdminController.php`, `lib/Service/Sources.php`

## Context

- **What the PIV is for.** Several Metas Sanitarias count people, not prestaciones. Their denominator is FONASA's
  Población Inscrita Validada (PIV), by establishment, age band and sex.
- **No source a program can read** (research, 2026-09-28):
  - FONASA's Tableau report redirects to a WAF-CAPTCHA page, and every `.csv` form of it answers 404;
  - FONASA's open-data pages answer 403;
  - datos.gob.cl holds only regional or 2013 copies, licensed CC-NC.
- **The owner's own export**, «Inscritos APS» for the corte 31-12-2025:
  - 146,345 rows and 2,091 centers, UTF-8 without a BOM, comma-delimited, CRLF;
  - eleven columns, and one row per centro × FONASA tramo (A/B/C/D/X) × age band × sex;
  - age bands of ten years, from `0 a 9 años` to `80 a más años`, plus `Sin información`;
  - no transmasculine value.

  Measured again on 2026-09-29 through this app's reader: 35,862 rows once the tramo is summed away; 15,788,220
  inscritos with an age band and 3,642 without; 1.2 s at a 24.5 MiB peak.
- **The OOTT 2026 rules.** A meta year *t* reads the corte of 31-12-(*t* − 1). A unit without it uses the last one it
  has, grown by 3 %.
- **The metas ask for age ranges the bands do not have**: 25–64, age 6, 65 and over, 5 and over.

## Decision

- **Upload, through the one door** (owner, Slice 8). The admin puts FONASA's CSV in Files and picks it with
  «Elegir un archivo en Archivos…», the same door as a REM zip and the Base de Establecimientos. The server tells a
  zip from anything else by its first bytes. A file that is not a zip goes to `PivReader`, and is loaded in the
  request.
- **Header-locked.** The file must carry exactly FONASA's eleven columns, in any order:
  - headers are compared by slug, so a BOM, REGIÓN's accent and Excel's capitals pass;
  - a `;` file, or one saved as Windows-1252, reads the same (Excel in es-CL);
  - any other set is refused whole in Spanish, naming what is extra and what is missing, above all a RUT or a name
    column (D5);
  - a malformed row refuses the whole file, naming its line, and nothing is stored.
- **Kept per corte, summed per age × sex** (owner, Slice 8). `estadistica_piv` holds one row per corte,
  establishment, age band and sex:
  - FONASA's tramo is summed away, because no meta reads it;
  - the establishment's name, comuna and servicio stay the register's;
  - people with no age band are counted, reported at import and not stored;
  - each corte a file carries replaces that corte's FONASA rows in one transaction. A corte the file does not carry
    stays.
- **Typed by hand for this install's own establishment only** (owner, Slice 8). An admin types its inscritos per
  age band × Mujeres/Hombres for the corte of 31 December of a year:
  - a typed cell wins over FONASA's, cell by cell, for that establishment's own series;
  - the country and the peer groups stay FONASA's published counts;
  - a file loaded later leaves the typed cells alone;
  - with no establishment configured, typing is refused in Spanish.
- **Which corte a meta year reads** (`Population::pick`). It reads 31-12-(*t* − 1) as stored. Failing that, it reads
  the latest earlier corte × 1.03, and failing that, none. A later corte never stands in for an earlier year: growth
  runs forward. Each scope picks on its own: the establishment among the cortes it has a figure for, and the country
  and the peers among FONASA's.
- **The band rule** (`Population::count`). A band counts count × the years it shares with the range ÷ 10, and
  `80 a más` counts as 80–89. The sum stays whole until one division, so the result is deterministic. A band that
  was cut marks the value «prorrateado», and the metas label it «estimada por prorrateo de los tramos de edad».
- **Committed denominators sit above all of this** (Slice 9, ADR-0008): a value the center committed to wins.
- **The PIV is a fuente** (`piv`, origen «FONASA»), dated by its newest corte, with its cortes as its catálogo. It
  is not a consulta fuente: the consulta engine compiles REM only (docs/adr/0006).

## Consequences

- Each new corte is a manual step for an admin, once a year. The admin section shows which cortes are loaded.
- A staff member can check the local denominators against FONASA's certificate and correct them. The national and
  peer comparison cannot be bent by one install's typing.
- Sex filters see only Mujeres and Hombres. Meta II's denominator (mujeres 25–64) therefore has no transmasculine
  share, although its numerator counts col02 (Decisions → Metas 2026 specifics).
- A comuna's administrative codes in FONASA's file count in the national total and in no peer group, because the
  register does not have them.
