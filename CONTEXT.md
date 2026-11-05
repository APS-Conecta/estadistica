# Estadística

The context of reading a center's REM figures beside the country's, and following its Metas Sanitarias. Everything
here is an aggregate count that DEIS, FONASA or MINSAL publishes, or that the establishment's own planilla holds; no
patient data enters this context. Every figure shown is official, and nothing is estimated. The terms are Spanish
because staff, the publishers and the public API say them in Spanish. The code around them is English, and its ADRs
cite them as written here.

## What is published

**REM** — the Resúmenes Estadísticos Mensuales every establishment reports to DEIS, and DEIS publishes for the whole
country, one zip per year. This app reads two of its series and keeps every establishment and month of them.
_Avoid_: planilla, when the published figures are meant. The planilla is what a center fills in; this app reads the
establishment's own, and fills nothing in.

**Serie** — **A** is monthly. **P** is a corte: its rows carry only months 6 and 12, and count people in control on
that date, not during the month. BS, BM and D are not read.

**Hoja, sección, prestación, columna** — how a REM figure is addressed: a hoja (`A03`), a sección of it, a prestación
by its código (six to sixteen characters; REM's have at least eight, `02010420`), and a columna (`col01`…`col50`). A
figure is the sum of the chosen
prestaciones × columnas.

**Diccionario** — DEIS's DICCIONARIO CODIGOS for one year and serie. It labels each (año, código, columna) with its
hoja, sección, fila and column header. A year with no readable dictionary borrows the nearest later year's labels,
and says so. A código no dictionary names shows as «sin descripción», and is counted.

**Base de Establecimientos** — DEIS's register of establishments. It gives each code its tipo, dependencia, nivel de
atención, servicio de salud, región and comuna, which is what a peer group chooses by.

**Planilla** — the REM form the establishment fills in for one serie and month, as kept in Nextcloud. For each serie
and month it covers, the newest saved planilla is the local series, and DEIS's figures for that month are not; the
nacional and the pares are DEIS's alone. Its origen is «planilla del establecimiento».

**Fuente** — one body of figures the app keeps and can say the age of: `rem-a`, `rem-p`, `establecimientos` (the Base
de Establecimientos) and `piv`. Each names its **origen** (the publisher, «datos públicos DEIS» or «FONASA»; «planilla
del establecimiento» for the months a planilla covers) and its **fecha de corte**, the date its data was cut. A
Serie A meta's local series ends at the establishment's own fecha de corte instead: its last month with a written
number in that serie (`fecha_corte_local` in the API). The nacional and the pares end at the fuente's. No consulta
asks the register: it is read beside the REM.
_Avoid_: dataset, and the publisher as a synonym: one publisher has three fuentes here.

**Incompleto** — a year and serie that loaded, but whose member failed a completeness or plausibility check. It is
kept and shown with its reason. It never replaces a complete load of the same year and serie.

**Primer año** — the first year of national history this install keeps (`first_year`). Everything from it to the
current year is the **ventana**.

## Asking

**Establecimiento** — the one this install serves, from config. Its figures are the **local** series. Nothing in
the app chooses it or offers another as «ours».

**Nacional** — every establishment that reported. **Pares** — the establishments that share the traits the viewer
picked with the local one (tipo, servicio de salud, comuna…). Both are aggregates.

**Sin informar** — a cell with no written number. It is never 0: a month, or a meta, with no written number in any
of its cells has no figure.

**Informante** — an establishment that reported at least one of the chosen cells in the periodo. **Promedio por
establecimiento** is the suma ÷ the informantes. Comparing always uses it: a country's sum cannot stand beside one
center's.

**Consulta** — one question as a spec: a fuente, a periodo, the filters, up to two dimensions to group by, a métrica
and what to compare with. The builder, the tableros, the metas and the API all send one, and it is the only way
figures are read.

**Pregunta** — a consulta saved with a title and a chart type, readable by all staff. **Tablero** — preguntas laid
out as cards; each card asks its own. Changing either is its author's, or belongs to whoever administers Estadística.
_Avoid_: reporte, dashboard: the UI says tablero.

## Metas

**Metas Sanitarias** — the yearly goals of Atención Primaria under the Ley 19.813, Metas I–VIII. A **resolución de
metas** in the Diario Oficial fixes each year's set: the indicators, the metas nacionales and the rule each meta
comprometida follows (2027: Res. Exenta 732). MINSAL's **Orientaciones Técnicas** (OOTT) follow, and their Anexo 3
gives each meta's route in the REM.

**Resumen** — the view that opens first: the metas of the newest resolución de metas for the establishment, in the
current year and in each comparable year before it. Its current year is the latest with figures: next year's metas
are counted over this year's until their own year has some. _Avoid_: tablero; nobody lays it out.

**Histórico** — a meta's ruta asked over an earlier year's figures. A year is **comparable** when every código ×
columna of the ruta carries the same label in that year's diccionario, and its periodos lie whole in the ventana;
otherwise it shows «sin datos comparables».

**Ruta** — how a meta's numerador or denominador is counted: a fuente, a periodo, and the códigos × columnas to add
(`sumar`) and to subtract (`restar`). Every term is a consulta. A ruta is **oficial** once the year's OOTT give it;
until then its meta shows «esperando ruta oficial».

**Meta nacional** — the percentage the resolución de metas sets for the country. **Meta comprometida** — the
percentage this establishment committed to. The metas view shows the gap to each in points. **Peso** — the meta's
weight in the SEREMI's evaluation. No global cumplimiento is computed here.

**Cumple** — a meta whose percentage reaches its meta comprometida, or the meta nacional when none is typed.

**Cierre** — a meta's value for a complete year: December's running sum for Serie A, the December corte for Serie P.

**Meta mínima** — the lowest meta comprometida the resolución de metas allows the establishment, from its last
cierre and the meta nacional. It is a reference: the Servicio de Salud fixes the meta comprometida.

**Nota** — a short Spanish text a model writes about what changed, from the figures already computed. It is shown
only when every number in it is one of them, and it says a model wrote it.

**Cumplimiento informado** — a meta with no figures (Meta VIII) is a percentage an admin types.

**PIV** — FONASA's Población Inscrita Validada, the people registered at each center, by age band and sex, at a
**corte** (31 December). It is the denominador of the metas that count people. A year with no corte of its own reads
the latest earlier one × 1.03, as the OOTT prescribe. A meta whose age range cuts a band has no denominador from the
PIV. A **denominador comprometido** is the official one the center typed instead; it wins, for the local series only.
_Avoid_: prorrateado; no band is split.

## What this context has no word for

- **«Nuestro centro»** as a choice. The establishment is configuration (suite ADR-0009); a clinic selector is a
  governance decision nobody has taken.
- **COMGES** and REM validation. Other goals and other checks belong to whatever builds them.
- **Paciente**, RUT, nombre. A FONASA file that carries a person is refused whole.
