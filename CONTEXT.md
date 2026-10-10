# Estadística

The context of reading a center's REM figures beside the country's, and following its Metas Sanitarias. Everything
here is an aggregate count that DEIS, FONASA or MINSAL publishes; no patient data enters this context. The terms are
Spanish because staff, the publishers and the public API say them in Spanish. The code around them is English, and
its ADRs cite them as written here.

## What is published

**REM** — the Resúmenes Estadísticos Mensuales every establishment reports to DEIS, and DEIS publishes for the whole
country, one zip per year. This app reads two of its series and keeps every establishment and month of them.
_Avoid_: planilla, when the published figures are meant. The planilla is what a center fills in, and this app fills
nothing in.

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

**Fuente** — one body of published figures the app keeps and can say the age of: `rem-a`, `rem-p`, `establecimientos`
(the Base de Establecimientos) and `piv`. Each names its **origen** (the publisher, «datos públicos DEIS» or «FONASA»)
and its **fecha de corte**, the date its data was cut. No consulta asks the register: it is read beside the REM.
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

**Metas Sanitarias** — the yearly goals of Atención Primaria under the Ley 19.813, Metas I–VIII. MINSAL's
**Orientaciones Técnicas** (OOTT) fix each year's set in October, and their Anexo 3 gives each meta's route in the
REM.

**Ruta** — how a meta's numerador or denominador is counted: a fuente, a periodo, and the códigos × columnas to add
(`sumar`) and to subtract (`restar`). Every term is a consulta.

**Meta nacional** — the percentage the OOTT set for the country. **Meta comprometida** — the percentage this
establishment committed to. The metas view shows the gap to each in points. **Peso** — the meta's weight in the
SEREMI's evaluation. No global cumplimiento is computed here.

**Cumplimiento informado** — a meta with no figures (Meta VIII) is a percentage an admin types.

**PIV** — FONASA's Población Inscrita Validada, the people registered at each center, by age band and sex, at a
**corte** (31 December). It is the denominador of the metas that count people. A year with no corte of its own reads
the latest earlier one × 1.03. A band that straddles a meta's age range is **prorrateado**, by the years it shares.
A **denominador comprometido** is one the center committed to instead; it wins, for the local series only.

## What this context has no word for

- **«Nuestro centro»** as a choice. The establishment is configuration (gestion ADR-0013); a clinic selector is a
  governance decision nobody has taken.
- **COMGES** and REM validation. Other goals and other checks belong to whatever builds them.
- **Paciente**, RUT, nombre. A FONASA file that carries a person is refused whole.
