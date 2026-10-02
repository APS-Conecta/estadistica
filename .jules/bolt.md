## 2026-03-31 - Memoize Intl.Collator for sorting strings with options
**Learning:** Calling `String.prototype.localeCompare` with options (e.g. `{ numeric: true }`) inside array comparator functions constructs an `Intl.Collator` instance on every call, incurring heavy overhead (~30x slower in V8).
**Action:** Always instantiate and reuse a module-scoped `Intl.Collator('es', { numeric: true })` instance when performing locale-sensitive or numeric string sorting.
