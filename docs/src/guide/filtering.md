# Filtered search

`IdMapIndex::search()` takes an optional allowlist of ids:

```php
$result = $index->search($query, k: 10, allowlist: [1001, 1007, 1042]);
```

Every returned id is from the allowlist; everything else is invisible
to the query. This is the hybrid-retrieval primitive: let SQL, BM25, an
ACL check, or a time window pick the *candidates*, and let the vector
index *rank* them.

## Semantics

- Row count is `min(k, count(allowlist))` after deduplication. An
  allowlist of 5 with `k: 10` returns exactly 5 rows — never padded
  fallbacks from outside the list.
- Duplicate ids in the allowlist are fine (deduplicated internally).
- An **empty allowlist throws** `InvalidArgumentException`. "No
  candidates" and "don't filter" are different intents — pass `null`
  (or omit the argument) to search unfiltered.
- Every allowlist id must currently be in the index; unknown ids throw
  rather than being silently ignored, because a stale candidate list
  usually means your index and your database have drifted.

## Performance

Filtering happens *inside* the SIMD kernel at 32-vector block
granularity: blocks containing no allowed slot are skipped before any
scoring work, and disallowed slots within scored blocks are dropped at
heap-insert. A selective allowlist therefore *reduces* work — you don't
pay for scanning the whole index and discarding rows afterwards, and
there is no recall penalty on small candidate sets (this is exact
filtering, not post-hoc).

The allowlist itself costs one O(1) `contains` check per entry at call
time plus a bitmask build proportional to the index size. For
per-request filters of thousands of ids this is noise; if you find
yourself passing *millions* of allowed ids per query, invert the
problem — search unfiltered and intersect afterwards.

Worked end-to-end example:
[filtered search over SQL ids →](../recipes/filtered-search-over-sql-ids.md)
