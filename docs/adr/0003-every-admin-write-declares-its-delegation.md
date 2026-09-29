# ADR-0003 — every admin write declares its delegation, and nothing else checks

- **Status:** accepted (2026-09-28)
- **Affects:** `lib/Settings/AdminSettings.php`, `lib/Controller/AdminController.php`, `tests/unit/MetadataTest.php`

## Context

The admin section edits:
- the history window;
- metas comprometidas and committed denominators;
- the yearly metas seed;
- PIV;
- dropped files.

The unit that owns these is not always an administrator (FRD FR9). Nextcloud's delegation does this with no
permission code and no group name in the app:
- an admin hands the section to any group (`occ admin-delegation:add 'OCA\Estadistica\Settings\AdminSettings' <group>`);
- core's SecurityMiddleware lets a real admin through, lets a member of a delegated group through when the method
  carries `#[AuthorizedAdminSetting(settings: AdminSettings::class)]`, and answers 403 to everyone else.

The suite's only earlier delegated section (IntraVox) declares delegation but checks `isAdmin()` by hand in every
setter, so every delegate is refused. Core evaluates the attribute as a class-string compare against
`authorized_groups` and constructs nothing, so there is no dependency reason for the manual check.

## Decision

- `AdminSettings` implements `IDelegatedSettings`.
- Every public method of `AdminController` carries exactly that attribute and neither `#[NoAdminRequired]` nor any
  manual check.
- MetadataTest fails a method without it, and fails any controller that calls `isAdmin(`.
- smoke proves it on a real instance: a delegated member gets 200, an outsider 403.

## Consequences

- Who may administer Estadística is instance configuration, like the establishment (gestion ADR-0013).
- The first delegated write (the history window) lands before any data exists, so later slices build on a mechanism
  already proven end to end.
