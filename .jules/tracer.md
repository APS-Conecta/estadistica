## 2026-10-06 - Target specific XML parent element when querying duplicate element names in metadata tests
**Defect:** `MetadataTest::testTheRegisteredAdminSectionIsDelegable` searched `info.xml` using `$this->text($doc, 'admin')`, which gets the first `<admin>` element. When `<documentation><admin>` was added, `getElementsByTagName('admin')` returned the documentation URL instead of `<settings><admin>`.
**Learning:** `getElementsByTagName()` searches the entire document tree and returns all elements with that tag name across different parent sections.
**Prevention:** Scope XML DOM queries to the parent element (e.g., `<settings>`) when asserting on configuration tags like `<admin>` that can appear in multiple XML sections (such as `<documentation>`).
