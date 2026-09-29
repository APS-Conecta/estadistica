# ADR-0001 — Estadística is cloned from the farmacia template

- **Status:** accepted (2026-09-28)
- **Affects:** the whole repository

## Context

The run plan (D1) makes Estadística a native Nextcloud 34 app built from the org template. farmacia is that template:
its Makefile, CI, smoke and MetadataTest already carry the fixes a fresh app would otherwise rediscover:
- the APCu restart after `app:enable` (farmacia `d4b7e43`);
- aps-drift before the tests (`773d0bf`);
- the smoke envelope check that requires `"status":"ok"` (`ba5e6a3`).

## Decision

Clone farmacia's harness and change only the app id, the namespace and the dev port (8092). The files whose content
is farmacia's own decision are rewritten here, not copied: the schema, the controllers and the Vue app.

## Consequences

- A fix to the template's harness is a fix to make here too: the clone does not follow farmacia on its own.
- aps-common arrives the template's way: a committed `vendor/aps/common/src` plus `src/aps`, kept honest by
  `make aps-drift`.
