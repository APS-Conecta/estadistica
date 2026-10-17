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
- **No patient data.** The README says "It stores only aggregate counts that DEIS and FONASA publish,
  and touches no patient data" (`README.md:10`), and `CONTEXT.md:4` says "no patient data enters
  this context".

## What an attacker wants

1. **Patient data elsewhere in the same Nextcloud.** The path is acting in a staff session: XSS
   through stored question or dashboard titles or published labels, or a forged request.
2. **Tampering with the figures** a CESFAM reports and plans against: REM data, PIV population, goals.
   These are integrity attacks on management decisions.
3. **Someone else's saved questions or dashboards**: editing or deleting them (IDOR on `{id}`).
4. **Database access through the query endpoint**: injection via the consulta spec.
5. **SSRF** through the DEIS download; **XXE and zip bombs** through the XLSX parser; **formula injection**
   through the PIV CSV.

## Entry points

| Entry point | Who can call it | Guard |
|---|---|---|
| `GET /` (`PageController`) | logged-in users | `#[NoAdminRequired]`, `#[NoCSRFRequired]` (GET) |
| OCS `GET /api/v1/fuentes`, `/fuentes/{id}`, `/indicadores` (`ApiController`) | logged-in users | `#[NoAdminRequired]`, `#[UserRateLimit]` |
| OCS **`POST /api/v1/consultas`** (`ApiController`) | logged-in users | `#[UserRateLimit]`; the spec is compiled to bound SQL (`lib/Query/QueryCompiler.php`, ADR-0006) |
| OCS preguntas and tableros: `GET`, `POST`, `PUT {id}`, `DELETE {id}` (`ApiController`) | logged-in users | `#[UserRateLimit]`; only the creator or an admin may change or delete (`SavedContent::owner`, `ForbiddenException`); unauthenticated writes return 403 (estadistica #4) |
| Admin: `PUT /admin/window`, `POST /admin/drop`, `GET /admin/ingests`, `PUT /admin/piv`, `PUT /admin/goals` (`AdminController`) | **admins, and users delegated the Estadística admin setting** | `#[AuthorizedAdminSetting(settings: AdminSettings::class)]` on each action (`lib/Controller/AdminController.php:65,86,102,124,142`; ADR-0003) |
| Background job `RemJob` (`appinfo/info.xml:33`) | the server, on schedule | fetches DEIS at run time (ADR-0005, `lib/Rem/DeisRepository.php`) |
| XLSX parser `lib/Xlsx/XlsxReader.php` (ZipArchive, then `XMLReader` and `DOMDocument`) | DEIS downloads; zips an admin drops | `LIBXML_NONET` and no `LIBXML_NOENT` (`XlsxReader.php:277,287`); a 256 MiB inflate cap summed from the sizes the zip declares about itself (`:38`, `:60-63`), so a zip that under-declares is the edge to test |
| PIV reader `lib/Piv/PivReader.php` | a CSV an admin drops | a locked eleven-column header; a file that adds a RUT or a name is refused whole, because that would be patient data (`PivReader.php:22`). Risks left: formula prefixes, encoding |
| Dropped files: the path given to `POST /admin/drop` (`lib/Service/DroppedFiles.php`) | admins and delegates | resolved only inside the caller's own Nextcloud files; `Folder::get()` normalises `..` away (`DroppedFiles.php:237`) |

## Trust boundaries

- **DEIS/FONASA/MINSAL files are untrusted** until parsed: XLSX is a zip of XML; PIV is a CSV an admin
  or a delegate drops.
- **The consulta spec is untrusted user input:** dimensions, filters, grouping and metric come from
  the client. Only whitelisted, bound fields may reach SQL (ADR-0006).
- **Saved titles are untrusted on output.** `src/` has no `v-html`, and Vue text binding escapes them.
- **The platform (Nextcloud)** owns authentication, sessions and CSRF tokens; PostgreSQL only (ADR-0002).

## Accepted risks

None recorded.
