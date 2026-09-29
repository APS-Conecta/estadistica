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

## Amendment (2026-09-29, Slice 7): who moderates saved content

Preguntas and tableros are not admin writes. Anyone may save one, and a saved one is changed by its creator or by
«an admin» (FRD FR7, acceptance a8). The owner read «an admin» as whoever administers Estadística. That is a
Nextcloud admin, or a member of a group this section is delegated to, so the unit that runs Estadística moderates
what the staff share, and IT is not the only one who can.

- The OCS routes stay `#[NoAdminRequired]`, because saving is everyone's. The check lives in
  `lib/Service/SavedContent.php`, the one place that changes saved content, and it asks core both questions:
  `IGroupManager::isAdmin()` and `IManager::getAllAllowedAdminSettings()`, which contains `AdminSettings` for a
  delegate. No group name enters the app, and the delegation is still the one an admin grants with
  `occ admin-delegation:add`.
- This is not the IntraVox anti-pattern the Decision rules out. That pattern is a hand-written admin check in front
  of an admin write, and it refuses every delegate. This check lets delegates through by core's own answer.
  MetadataTest's `isAdmin(` gate stays on `lib/Controller`, where an admin write lives.
- Core builds every app's admin-settings class to answer the delegation question. So SavedContent asks it at most
  once per request, and only about content that is not the person's own.
- smoke proves it on a real instance: a delegate changes someone else's pregunta (200), and a user in no delegated
  group gets 403.
