# ADR-0008 — The Metas Sanitarias are a yearly seed, read month by month

- **Status:** accepted (2026-09-29)
- **Affects:** `seeds/metas-2026.json`, `lib/Goals/GoalSeed.php`, `lib/Goals/Goals.php`, `lib/Goals/GoalEvaluator.php`,
  `lib/Migration/EnsureSeedData.php`, `lib/Service/DroppedFiles.php`, `lib/Controller/AdminController.php`,
  `lib/Controller/ApiController.php`, `src/components/Goals.vue`

## Context

- **The metas change every year.** MINSAL's Orientaciones Técnicas (OOTT) fix each year's Metas Sanitarias of the Ley
  19.813 in October: the metas nacionales, and in Anexo 3 each indicator's route in the REM, by código and column,
  cited against that year's REM dictionaries. The owner: «we will do this each year and we need to update it».
- **The 2026 OOTT** (version 1.0, 10-10-2025) were read route by route for this app:
  - Meta I's denominator subtracts two códigos from a third, over October 2025 to September 2026;
  - Meta VII adds two códigos, each over its own columns;
  - Metas II, III.A, III.B, IV.A, V and VII count people from FONASA's Población Inscrita Validada, four of them
    through ENS prevalences;
  - Meta VIII is a report, with no figures;
  - the 2026 text restates no pesos; research carried 2025's Anexo 2.
- **Serie P is a corte.** Measured on SERIE_REM_2025: every Serie P row carries month 6 or 12. P4, P3 and P12 count
  people in control at the corte, not in the month.
- **What a center asks** (a12, a22): how far it is, month by month, from its meta comprometida and the meta nacional,
  and how the country and centers like it are doing.

## Decision

- **A seed file per year, loaded as data** (a9). `seeds/metas-2026.json` ships with the app. A later year arrives as
  another file, through the admin section's door, with no release:
  - the door tells a metas file by its opening brace (DroppedFiles), beside a zip and FONASA's CSV;
  - `estadistica_goal` keeps each year's file as it was loaded, and `GoalSeed` judges it again on every read (the
    consulta rule, docs/adr/0006 amendment);
  - a year may be loaded from the year before (October), and no later;
  - a file replaces its year whole. The commitments stay: they name their meta by codigo.
- **The shipped seed loads in a repair step** (`EnsureSeedData`, install and post-migration): when its year has no
  metas, or holds an older `version`. A file an admin loaded with a version as high or higher stays theirs.
- **A route is consultas.** A numerador or a REM denominador is one fuente, a periodo ending in the metas' year, and
  the códigos × columnas to add (`sumar`) and to subtract (`restar`). Each term is judged by `QuerySpec::from()`, so a
  seed names nothing a consulta could not (a16). The terms are asked on the server, with the statements a consulta
  compiles to, in one read-only snapshot for the whole year (docs/adr/0006 amendment); the view reads
  `GET /api/v1/indicadores`.
- **A denominador counts REM, people, or a commitment.** People are FONASA's inscritos for the meta year (the corte
  rule and the band rule of docs/adr/0007), as age groups, each of one sex or both, times an optional prevalence.
  `{"comprometido": true}` is a denominator only the establishment's commitment gives.
- **Month by month.** A Serie A route adds up from its periodo's first month to the month shown, and no further than
  its last. A Serie P route shows the latest corte up to that month. A Serie A month after the meta year's fecha de
  corte has no figure yet, rather than repeating the last one.
- **The commitments are the establishment's** (a10, a11), typed in the admin section as a delegated write: the meta
  comprometida of each meta, the denominador comprometido of a meta counted over people (it wins for the local
  series only), and the cumplimiento of a manual meta. They are kept per year and per establishment.
- **National and peer % where the denominator is nationally computable** (a12): REM, or FONASA's inscritos when a
  corte serves the year. The peers are the register's establishments that share the chosen traits with this one,
  tipo to start with; the viewer may choose others (owner, Slice 9).
- **Per meta only** (owner, Slice 9). Each meta shows its %, its gaps in points to the meta comprometida and the meta
  nacional, and its peso. No global cumplimiento is computed: the official one is the SEREMI's, with its re-weighting
  of metas left out (OOTT Anexo 1).
- **One card per meta, with its month-by-month table** (owner, Slice 9), not a chart.
- **Where the 2026 text is read, not copied**, the seed says so in its notes:
  - the pesos are 2025's;
  - Meta II's denominator counts mujeres only, while its numerador counts col02's transmasculine people too;
  - Meta VI's numerador is counted in 2026, where the OOTT's text says 2025.

## Consequences

- Each October an admin loads the new year's file, and types the commitments. A shipped correction of a year
  replaces it only with a higher version.
- A seed stays valid only while QuerySpec accepts it, which is why the spec only grows (M2 contract notes).
- A meta over people shows no % at all until FONASA's corte is loaded, and says so.
- A month of the current year shows nothing before DEIS publishes it, and a Serie P meta nothing before June.
- The national and peer figures are sums over every establishment that reported, divided by FONASA's inscritos of
  all its centers: the same route for everyone, not the SEREMI's evaluation.
