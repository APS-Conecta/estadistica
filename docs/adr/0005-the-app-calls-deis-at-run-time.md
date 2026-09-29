# ADR-0005 — The app calls DEIS at run time, by Range, once a day

- **Status:** accepted (2026-09-28)
- **Affects:** `lib/Rem/HttpRange.php`, `lib/Rem/DeisRepository.php`, `lib/BackgroundJob/RemJob.php`,
  `lib/Controller/AdminController.php`

## Context

- The run plan's OUT list excluded runtime calls to public internet services. The owner's FRD decision supersedes that
  for DEIS. REM carries no licence (epidemiologia ADR-0004), so each install fetches it, and nothing ships.
- DEIS publishes one zip per year at `repositoriodeis.minsal.cl/DatosAbiertos/REM/SERIE_REM_<año>.zip`, 100–246 MB.
  Research measured it, and it was measured again on 2026-09-28, from this box and through Nextcloud 34's own client:
  - every year answers `Accept-Ranges: bytes`, and a range answers 206 with an exact `Content-Range`;
  - `If-None-Match` and `If-Modified-Since` answer 304 with 0 bytes, and a year not published answers 404;
  - `If-Range` with an ETag that is no longer current answers 200 with the whole file;
  - the ETag is IIS's mtime (`"<hex>:0"`), so it says when the file changed, not what changed;
  - past years are rewritten long afterwards (2025 on 2026-06-10, 2011–2018 on 2023-06-05), some of them shrinking.
- Nextcloud's client streams a body when asked (`stream => true`). One Range request read 2026 Serie A (72.7 MB
  compressed, 509 MB inflated) and inflated it in 5.6 s, with a 27.5 MiB peak.

## Decision

- **One repository for the publisher**, `DeisRepository` (epidemiologia ADR-0016). Every request names the app
  (ADR-0015), and none sets `verify`.
- **Read by Range, never whole.** `HttpRange` implements the importer's `RangeReader`:
  - a HEAD for the size and the ETag, then one streamed Range request per read;
  - every read carries the HEAD's ETag in `If-Range`, so a zip DEIS replaces mid-load is refused, not misread;
  - `Accept-Encoding: identity`, because the offsets are the file's own.
- **The same importer as a dropped zip** (ADR-0004). A queued (año, serie) loads from its drop when there is one, and
  from DEIS otherwise.
- **A daily check, and one member per hourly tick.** When nothing is queued and a day has passed, the check sends one
  conditional HEAD per year in the window. For each year that changed, it reads the central directory (≈ 2 × 66 KB)
  and queues each Serie A or P member whose CRC-32 or size differs from the one last judged. A year's ETag is kept
  only once nothing of the year is queued.
- **Each year is checked on its own.** A year that fails is recorded, and the next is still checked. A 404 is quiet
  only for the current year, which DEIS may not have published yet. For a past year it means the address moved
  (epidemiologia ADR-0014).
- **Narrowing the window is the job's work.** A year left outside is forgotten, one per tick: its rows and state in one
  transaction, and its ETag with them.
- **`'has_internet_connection' => false` turns DEIS off.** That install loads from dropped zips only, and the admin
  section says so.

## Consequences

- The default window fills in ≈ 8 hourly ticks after the first check. When idle, the cost is one HEAD per year per day.
- A failure is never quiet. A load's failure stays on its (año, serie) row, and a check's failure appears in the admin
  section under «Cifras REM cargadas», each with its Spanish sentence.
- A member that fails on its content is fetched again every day until DEIS fixes it. That is the price of never hiding
  a load behind a 304.
- Once DEIS publishes different bytes for a member, they replace a dropped copy of it. The exception is when DEIS's
  bytes are incomplete over a complete load (ADR-0004).
- An install behind a firewall that blocks DEIS sees the check fail every day in the admin section, and loads from
  drops.

## Amendment (2026-09-28, Slice 4): the dictionaries and the Base de Establecimientos

- **Context.** Each `SERIE_REM_<año>.zip` also carries that year's DICCIONARIO CODIGOS for Serie A and P: `.xlsx` or
  `.xlsm` from 2017, BIFF `.xls` before. The Base de Establecimientos is on the same host, one xlsx per year at
  `ContenidoSitioWeb2020/Establecimientos/Base de Establecimientos <año>.xlsx`. Measured 2026-09-28: 2025 answers
  200 at 1.0 MB with 5,563 establishments; 2026 is not out yet.
- **Decision.**
  - A serie's dictionary is read from the same zip, in the same attempt, as the serie: by Range from DEIS, or from
    the dropped zip. The daily check also compares the dictionary's CRC and size with the one last judged, so a
    dictionary republished alone is read again, with its serie.
  - The same daily pass looks for the newest Base de Establecimientos: this year's once it is out, last year's until
    then, with a conditional HEAD on the file last loaded. A new file is read whole (≈ 1 MB) and replaces the
    register in one transaction. A 404 is quiet only for this year, and only while this year's file was never
    loaded.
  - An admin may drop the Base de Establecimientos through the same door as a REM zip. It is loaded in the request.
- **Consequences.**
  - An idle day costs two more HEADs.
  - A dictionary republished alone costs a full re-read of its serie (up to ≈ 3.4 min). That is rare, and it keeps
    the labels the zip's own.
  - A register DEIS stops publishing is said in the admin section, and the one loaded keeps serving.
