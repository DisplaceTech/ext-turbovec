# Searching

```php
$result = $index->search($packedQuery, k: 10);
```

The query is exactly one packed vector (`strlen === 4 * dim`); `k` is
the number of neighbors you want. The result is a
`SearchResult` — immutable, `Countable`, and iterable:

```php
count($result);                        // rows returned
$result->ids();                        // list<int>, best-first
$result->scores();                     // list<float>, parallel to ids()

foreach ($result as $row) {
    // $row = ['id' => int, 'score' => float]
}
```

## Scores

Scores are inner-product similarities computed against the quantized
codes — **higher is better**, and rows always arrive best-first. For
unit-length (normalized) embeddings, inner product *is* cosine
similarity, so a self-match scores ≈ 1.0 and unrelated vectors hover
near 0. Most embedding models emit (or recommend) normalized vectors;
normalize at embed time and the scores read naturally.

Treat absolute scores as model-specific: thresholds that mean
"relevant" for one embedding model won't transfer to another. Ranking
is what the index guarantees.

## How many rows come back

`min(k, eligible)` — where eligible is the index size, or the
(deduplicated) allowlist size when [filtering](./filtering.md). Asking
an index of 3 vectors for `k: 10` returns 3 rows; there are no padded
or sentinel rows to skip.

## Concurrency

`search()` never mutates the index and is safe to call from multiple
threads on the same instance (relevant under ZTS/parallel; trivially
true in FPM where each worker has its own objects). The first search
after `add()` or `load()` pays a one-time cost building the SIMD-blocked
code layout; subsequent searches read it lock-free.
