# Threat model

*What an attacker would want from this repository, and where they would try. Read before any security
work; the 🗡️ Breaker agent starts here.*

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
5. **SSRF and XXE** through the DEIS download and the XLSX/PIV parsers.

## Entry points

| Entry point | Who can call it | Guard |
|---|---|---|
| `GET /` (`PageController`) | logged-in users | `#[NoAdminRequired]`, `#[NoCSRFRequired]` (GET) |
| OCS `GET /api/v1/fuentes`, `/fuentes/{id}`, `/indicadores` (`ApiController`) | logged-in users | `#[NoAdminRequired]`, `#[UserRateLimit]` |
| OCS **`POST /api/v1/consultas`** (`ApiController`) | logged-in users | `#[UserRateLimit]`; the spec is compiled to bound SQL (`lib/Query/QueryCompiler.php`, ADR-0006) |
| OCS preguntas and tableros: `GET`, `POST`, `PUT {id}`, `DELETE {id}` (`ApiController`) | logged-in users | `#[UserRateLimit]`; only the creator or an admin may change or delete (`SavedContent::owner`, `ForbiddenException`); unauthenticated writes return 403 (estadistica #4) |
| Admin: `PUT /admin/window`, `POST /admin/drop`, `GET /admin/ingests`, `PUT /admin/piv`, `PUT /admin/goals` (`AdminController`) | **admins only** | no `#[NoAdminRequired]`; each admin write declares its delegation (ADR-0003) |
| Background job `RemJob` (`appinfo/info.xml:33`) | the server, on schedule | fetches DEIS at run time (ADR-0005, `lib/Rem/DeisRepository.php`) |
| Parsers of external files: `lib/Xlsx/XlsxReader.php` (ZipArchive + `XMLReader`), `lib/Piv/PivReader.php`; dropped files (`lib/Service/DroppedFiles.php`) | DEIS downloads; admin uploads | `XMLReader` is used without an explicit `LIBXML_NONET` — check it |

## Trust boundaries

- **DEIS/FONASA/MINSAL files are untrusted** until parsed: XLSX is a zip of XML; PIV is uploaded by an
  admin.
- **The consulta spec is untrusted user input:** dimensions, filters, grouping and metric come from
  the client. Only whitelisted, bound fields may reach SQL (ADR-0006).
- **Saved titles are untrusted on output.** `src/` has no `v-html`, and Vue text binding escapes them.
- **The platform (Nextcloud)** owns authentication, sessions and CSRF tokens; PostgreSQL only (ADR-0002).

## Accepted risks

None recorded.
