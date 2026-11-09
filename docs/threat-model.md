# Threat model

*What an attacker would want from this repository, and where they would try. Read before any security
work.*

## Data this repository holds

- **Aggregate published figures:** REM series, the establishment directory, code dictionaries,
  population (PIV) and yearly Metas Sanitarias goals. Tables: `estadistica_rem`,
  `estadistica_establishment`, `estadistica_code`, `estadistica_dictionary`, `estadistica_piv`,
  `estadistica_goal`, `estadistica_commitment`, `estadistica_ingest` (`lib/Migration/`).
- **Staff-saved content:** questions (preguntas) and dashboards (tableros), each owned by its creator
  (`estadistica_question`, `estadistica_dashboard`; `lib/Service/SavedContent.php`).
- **The establishment's planillas:** what reading each file in the planilla folder found, and a read
  planilla's aggregate figures, kept apart from DEIS's (`estadistica_planilla`, `estadistica_planilla_rem`;
  `lib/Service/Planillas.php`, ADR-0010). The workbooks stay in Nextcloud Files; the app keeps no copy.
- **The stored Resumen and its nota:** the configured establishment's Resumen document, and beside it the
  nota a model wrote about what changed (`estadistica_resumen`; `lib/Goals/ResumenStore.php`, `lib/Goals/Nota.php`).
- **No patient data.** The README says (in Spanish) that the app stores only aggregate counts that DEIS
  and FONASA publish and touches no patient data (`## Qué es`), and `CONTEXT.md:4` says "no patient data
  enters this context".

## What an attacker wants

1. **Patient data elsewhere in the same Nextcloud.** The path is acting in a staff session: XSS
   through stored question or dashboard titles or published labels, or a forged request.
2. **Tampering with the figures** a CESFAM reports and plans against: REM data, PIV population, goals,
   the establishment's planillas. These are integrity attacks on management decisions.
3. **Someone else's saved questions or dashboards**: editing or deleting them (IDOR on `{id}`).
4. **Database access through the query endpoint**: injection via the consulta spec.
5. **SSRF** through the DEIS download; **XXE and zip bombs** through the XLSX parser; **formula injection**
   through the PIV CSV; **macro execution** through a staff-supplied `.xlsm` planilla.

## Entry points

| Entry point | Who can call it | Guard |
|---|---|---|
| `GET /` (`PageController`) | logged-in users | `#[NoAdminRequired]`, `#[NoCSRFRequired]` (GET) |
| OCS `GET /api/v1/fuentes`, `/fuentes/{id}`, `/indicadores` (`ApiController`) | logged-in users | `#[NoAdminRequired]`, `#[UserRateLimit]` |
| OCS **`POST /api/v1/consultas`** (`ApiController`) | logged-in users | `#[UserRateLimit]`; the spec is compiled to bound SQL (`lib/Query/QueryCompiler.php`, ADR-0006) |
| OCS preguntas and tableros: `GET`, `POST`, `PUT {id}`, `DELETE {id}` (`ApiController`) | logged-in users | `#[UserRateLimit]`; only the creator or an admin may change or delete (`SavedContent::owner`, `ForbiddenException`); unauthenticated writes return 403 (estadistica #4) |
| Admin: `PUT /admin/window`, `POST /admin/drop`, `GET /admin/ingests`, `PUT /admin/planillas`, `PUT /admin/piv`, `PUT /admin/goals` (`AdminController`) | **admins, and users delegated the Estadística admin setting** | `#[AuthorizedAdminSetting(settings: AdminSettings::class)]` on each action (`lib/Controller/AdminController.php:67,88,105,124,139,157`; ADR-0003) |
| Background job `RemJob` (`appinfo/info.xml:38`) | the server, on schedule | fetches DEIS at run time (ADR-0005, `lib/Rem/DeisRepository.php`); reads the planilla folder as the person who chose it (`Planillas::scan`) |
| The nota's answer: Nextcloud TaskProcessing's `TaskSuccessfulEvent` (`lib/Listener/NotaListener.php`) | whatever text-to-text provider the Nextcloud admin connects | only this app's text-to-text task, matched by the fingerprint it was asked for, kept only while that fingerprint is the stored one and only when `Nota::check` passes it |
| XLSX parser `lib/Xlsx/XlsxReader.php` (ZipArchive, then `XMLReader` and `DOMDocument`) | DEIS downloads; zips an admin drops; planillas staff save in the folder | `LIBXML_NONET` and no `LIBXML_NOENT` (`XlsxReader.php:277,287`); a 256 MiB inflate cap summed from the sizes the zip declares about itself (`:38`, `:60-63`), so a zip that under-declares is the edge to test |
| PIV reader `lib/Piv/PivReader.php` | a CSV an admin drops | a locked eleven-column header; a file that adds a RUT or a name is refused whole, because that would be patient data (`PivReader.php:22`). Risks left: formula prefixes, encoding |
| Dropped files: the path given to `POST /admin/drop` (`lib/Service/DroppedFiles.php`) | admins and delegates | resolved only inside the caller's own Nextcloud files; `Folder::get()` normalises `..` away (`DroppedFiles.php:237`) |
| The planilla folder: the path given to `PUT /admin/planillas` (`lib/Service/Planillas.php`) | admins and delegates choose it; **anyone who can write in it** supplies the workbooks | the path resolves only inside the chooser's own Files and must be a folder; the job reads it as the chooser, so it reaches nothing they cannot. Each file: at most 64 MB, then XlsxReader's guards; read by `lib/Rem/PlanillaReader.php`, which refuses it whole unless its NOMBRE sheet carries the configured establishment and the version of that year's diccionario, and keeps only numeric cell values |

## Trust boundaries

- **DEIS/FONASA/MINSAL files are untrusted** until parsed: XLSX is a zip of XML; PIV is a CSV an admin
  or a delegate drops.
- **Staff-supplied workbooks are untrusted.** A planilla is DEIS's macro-enabled form, saved by whoever can
  write in the folder. Macros never run: only the workbook's XML parts are read, `xl/vbaProject.bin` is
  never opened, and a formula counts as the value last saved with it, never evaluated. Its file name and
  refusal reason reach the admin section as text, which Vue escapes.
- **The consulta spec is untrusted user input:** dimensions, filters, grouping and metric come from
  the client. Only whitelisted, bound fields may reach SQL (ADR-0006).
- **Saved titles are untrusted on output.** `src/` has no `v-html`, and Vue text binding escapes them.
- **Model output is untrusted text.** The nota is bounded three ways. Its task carries aggregates only: the
  fixed Spanish instruction and the Resumen card figures that changed, as the screen writes them, with no
  names, files or planilla text. `Nota::check` drops the answer unless every number in it, as es-CL writes it,
  is a figure the stored Resumen shows, a year it names, or a month it names with its year («11/2099», never a
  bare month), and drops an answer it cannot read. Vue renders it as text, never `v-html`,
  labelled «Escrita por un modelo de IA a partir de las cifras».
- **The platform (Nextcloud)** owns authentication, sessions and CSRF tokens; PostgreSQL only (ADR-0002).

## Accepted risks

- **Whoever can write in the planilla folder sets the establishment's local series** for the months its
  planillas cover (ADR-0010). A planilla's figures, its cached formula values included, are taken as saved,
  like the report the establishment sends its Servicio de Salud. Who may write there is the folder's
  Nextcloud sharing, which the establishment controls; the admin section lists every file and its state.
- **The nota's words are the model's.** The validator checks the numbers written in digits. A number spelled
  in words, or a wrong claim with no number in it, is not caught. The label says a model wrote it, and every
  figure on the cards below it is the stored one.
