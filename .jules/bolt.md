## 2026-10-02 - Memoize Intl.Collator for sorting strings with options
**Learning:** Calling `String.prototype.localeCompare` with options (e.g. `{ numeric: true }`) inside array comparator functions constructs an `Intl.Collator` instance on every call, incurring heavy overhead (~30x slower in V8).
**Action:** Always instantiate and reuse a module-scoped `Intl.Collator('es', { numeric: true })` instance when performing locale-sensitive or numeric string sorting.

## 2026-10-07 - Avoid JSON.stringify and array allocations in chart indexing

When indexing query results with up to 5,000 group entries across 3 series (~15,000 points), calling `JSON.stringify` on group arrays in lookup maps and sort callbacks created significant serialization and object allocation overhead.

Key takeaways:
- Use primitive key helpers (`keyOf` / `toKey`) for group tuples instead of `JSON.stringify(grupo)`.
- Use one `Map` per series (`points[serie.id]`) to eliminate string key concatenation (`${serie.id}|${key}`).
- Use index-based comparisons (`compareGroups`) instead of `.reduce()` inside sort callbacks.
- Compute maximum values in `panelsOf` with direct O(N) iteration over point values.
