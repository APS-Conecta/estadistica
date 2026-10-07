## 2026-10-07 - Avoid JSON.stringify and array allocations in chart/table hot loops

When indexing and rendering query results with up to 5,000 group entries across 3 series (~15,000 points), `JSON.stringify` on group arrays in lookup maps and `.map().find()` in label resolution created significant allocation and serialization overhead.

Key takeaways:
- Use primitive key functions (`toKey`) for group tuples (length 0, 1, or 2) instead of `JSON.stringify(grupo)`.
- Use separate `Map` instances per series (`points[serie.id]`) to eliminate string key concatenation (`${serie.id}|${key}`).
- Use index-based comparisons (`compareGroups`) instead of `.reduce()` inside sort callbacks.
- Memoize `groupLabel` calls across `tableOf` row generation and use early-return `for..of` loops instead of `.map().find()`.
