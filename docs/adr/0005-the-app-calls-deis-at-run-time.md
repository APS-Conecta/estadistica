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
