# ADR-0006 — one consulta spec, compiled to bound SQL, run read-only

- **Status:** accepted (2026-09-28, Slice 5).
- **Affects:** `lib/Query/`, `ApiController::consultas`, and every later caller of a consulta: the builder
  (Slice 6), preguntas and tableros (Slice 7), the metas view (Slice 9), suite apps and M3's AI.

## Context

The same questions reach Estadística from several places: the Spanish builder, a saved tablero card, the metas
view, another suite app and, later, an AI. If each of them wrote its own SQL, they would disagree about the numbers.
The API must return the UI's numbers (a15). Every one of them would also be a door for injection.

Unlike the JSONB rows the run plan's baseline assumed, the REM table is typed (docs/adr/0004). Its figures are
`col01`…`col50` columns, not keys inside a document, so a column cannot be a bound value. Research measured the
guards in PostgreSQL 18: inside `SET TRANSACTION READ ONLY` an INSERT fails, and `statement_timeout` cancels a slow
statement.

## Decision

- **One spec, Spanish keys.** A consulta is one JSON document: `fuente`, `periodo`, `filtros`, `agrupar`, `metrica`
  and `comparar`. The builder, tableros, metas, the OCS route (`POST /api/v1/consultas`) and M3 all send it.
  `QuerySpec::from()` is its only reader. It refuses in Spanish, as a 422, every key, column, dimension or metric it
  does not know, before any SQL exists (a16).
  - `fuente` is a fuente id (`rem-a`, `rem-p`).
  - `periodo` is a month range (`AAAA-MM`). It is required, and may cross a year, which Meta I's window does.
  - The filtros must name what is summed (a hoja, a sección within it, códigos) and the columns.
  - At most two of `anio`, `mes`, `codigo`, `columna`, and the register traits are grouped by (a7).
  - `metrica` is `suma` or `promedio_establecimiento`, which is the suma ÷ the establishments that reported.
  - `comparar` adds the national series and a peer group's. A trait is a register value, or `true` for the local
    establishment's own.
- **Parameter-only SQL.** `QueryCompiler` writes SQL from its own fixed text and from names the spec checked against
  closed sets: the columns, the dimensions, the traits and the two aggregates. Every value is bound. Two consultas
  that differ only in values compile to the same statement, and none of their values appears in it
  (QueryCompilerTest).
- **Only reported cells count.** The chosen columns are unpivoted (`LATERAL VALUES`), and a cell counts only where
  it was reported. An empty cell is NULL, never 0, so an «informante» is an establishment with at least one reported
  cell among the chosen columns.
- **One statement per series, one snapshot.** Local, national and peer series differ only in who is counted. They
  run in one transaction opened with `SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY`, and
  `statement_timeout` is set locally through `set_config(…, true)` (10 s). A write that reached the executor fails
  in PostgreSQL. A statement past the timeout is cancelled and answered as a Spanish 422.
- **Names resolve on the server.** A hoja or sección becomes the códigos that the periodo's dictionaries put there:
  each year's own, or the nearest later year's (`Catalog::scope`). A peer trait of `true` becomes the local
  establishment's register value. Either one is refused in Spanish when nothing resolves.
- **The local establishment is a parameter.** It defaults to app config: `establecimiento` in the request, else
  `deis_code` (suite ADR-0009). The app's own pages never send one.

## Consequences

- The answer is `{series: {local, nacional, pares}, etiquetas: {local, pares, codigos}, fuente}`. That is the M3
  contract, and it is only extended, never reshaped. Each series is a list of `{grupo, valor, informantes}`, and
  `grupo` lists the group's values in `agrupar`'s order.
- Preguntas store specs verbatim from Slice 7. From then on a key is only ever added, never renamed. An unknown key
  stays a 422, so a spec that is refused today can become valid later, and a valid one never changes meaning.
- A hoja filter is the union of its códigos over the periodo's dictionaries, not year by year (`ponytail:` in
  `Catalog::scope`). Per-year pairs measured 2–3× slower, and nothing yet shows DEIS moving a código between hojas.
- The per-request cost is bounded: at most three statements of 10 s each, and at most 5,000 groups per series.
  Measured on 5.76M synthetic rows, a national series takes ≈ 2 s and a local one ≈ 20 ms.
- M2's file fuentes add a second compiler branch under the same spec and the same executor. A fuente interface is
  extracted then, not before (epidemiologia ADR-0010/0011).

## Amendment (2026-09-29, Slice 7): a pregunta keeps its consulta as sent

- **Judged, then stored as sent.** `POST /api/v1/preguntas` stores the consulta only if `QuerySpec::from()` accepts
  it. What is stored is the document that came in, re-encoded with its keys and values unchanged. It is never the
  normalized form: a consulta sent without `metrica` stays without one, and still reads as suma. So a stored spec
  says exactly what its author asked, in the contract's own words, and the key-freeze above protects it.
- **Judged again every time it runs.** A tablero card and an opened pregunta post the stored consulta to
  `POST /api/v1/consultas` like any other caller. A consulta the dictionaries no longer resolve (a hoja gone from
  every dictionary of its periodo) answers its own Spanish 422, in its own card, and the cards beside it still draw.
- **The chart type is not part of the spec.** A pregunta keeps it beside the consulta (`tipo`: barra, linea or
  tabla), because it says how an answer is drawn, not what is asked.

## Amendment (2026-09-29, Slice 9): the metas ask on the server

- **The metas' routes are consultas, but the metas view does not post them.** Where the Decision says the metas send
  the spec to `POST /api/v1/consultas`, read: each term of a meta's route is a consulta that `QuerySpec::from()`
  judges when its seed is read (docs/adr/0008). `GoalEvaluator` then asks every term of the year on the server:
  `QueryCompiler` compiles the same statements, and `QueryExecutor::series()` runs them inside one `readOnly()`, so
  the same guard applies and the whole year reads one snapshot.
- **Why not from the page.** A meta is more than a consulta: it subtracts terms (Meta I), divides by FONASA's
  inscritos or a commitment, and turns months into running sums or cortes (a12). Done in the browser, that would be
  fourteen consultas and the PIV rules copied into JavaScript. The view reads `GET /api/v1/indicadores` instead, so
  the page shows what the API answers (a15) through that route, and a meta's numbers equal a consulta's for the same
  códigos, columns and months.
