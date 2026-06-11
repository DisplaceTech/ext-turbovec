# Quick start

An index of three vectors, searched, in one script:

```php
<?php
use Displace\Vector\TurboQuantIndex;
use Displace\Vector\Vectors;

// dim must be a positive multiple of 8 — real embeddings (384, 768,
// 1024, 1536, ...) all qualify. We'll use 8 to keep the example tiny.
$index = new TurboQuantIndex(dim: 8, bitWidth: 4);

// Vectors enter as packed float32 strings: pack('g*', ...$floats).
// Batches are plain string concatenation.
$index->add(
    pack('g*', 1.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0) .   // id 0
    pack('g*', 0.0, 1.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0) .   // id 1
    pack('g*', 0.7, 0.7, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0)     // id 2
);

// Search with a query vector; get back ids + scores, best-first.
$result = $index->search(pack('g*', 0.9, 0.1, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0), k: 2);

foreach ($result as $row) {
    printf("id %d  score %+.4f\n", $row['id'], $row['score']);
}
// id 0  score +0.9...    <- closest to the query
// id 2  score +0.5...
```

Three things to take away:

1. **Vectors are packed strings.** `pack('g*', ...$floats)` — or
   `Vectors::pack($floats)` — is the only input format.
   [Why, and the full rules →](../guide/packed-vectors.md)
2. **Ids are positional** in `TurboQuantIndex` (the Nth added vector is
   id N). When your vectors belong to database rows, use
   [`IdMapIndex`](../guide/indexes.md) and address them by your own ids.
3. **Scores are similarities** — higher is better, results come
   best-first.

For something real, feed it actual embeddings:
[semantic search with ext-infer →](../recipes/semantic-search-with-ext-infer.md)
