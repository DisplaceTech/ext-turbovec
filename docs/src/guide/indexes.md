# Choosing an index

Two index classes share the same engine and differ only in how vectors
are addressed.

## `TurboQuantIndex` — positional ids

The Nth vector added is id N, forever. There is no removal. Use it when
the corpus is append-only (or rebuilt wholesale) and you keep your own
side table mapping positions to documents — or when position *is* the
key, e.g. line numbers in a file.

```php
$index = new TurboQuantIndex(dim: 768, bitWidth: 4);
$index->add($packedBatch);            // ids 0..n-1, in order
```

## `IdMapIndex` — your ids, O(1) remove

Wraps the same engine with a bidirectional id table. You address
vectors by arbitrary non-negative ints — SQL primary keys, content
hashes truncated to 63 bits, whatever identifies a document in *your*
system — and those ids survive any number of other insertions and
removals.

```php
$index = new IdMapIndex(dim: 768, bitWidth: 4);
$index->addWithIds($packedBatch, [1001, 1002, 1003]);
$index->remove(1002);                 // O(1)
```

`addWithIds()` rejects ids already present (and duplicates within the
call) *before* adding anything — a failed call never partially applies.
`remove()` of an absent id throws rather than silently doing nothing.

Removal is constant-time because it's a swap-remove internally; the id
table absorbs the reshuffling so external ids never move. The cost is a
hash lookup per result row at search time — negligible against the scan.

**Default to `IdMapIndex`** for anything document-shaped. The positional
index is the right choice only when you genuinely don't need stable
identity, filtering by id, or deletion.

## Constructor parameters (both classes)

- **`dim`** — vector dimensionality. Must be a positive multiple of 8,
  at most 65536. Every common embedding size qualifies (384, 512, 768,
  1024, 1536, 3072, 4096). Locked at construction; payloads that
  disagree throw.
- **`bitWidth`** — bits per coordinate after quantization, `2` or `4`
  (default `4`). 4-bit is the right default: recall close to
  full-precision at an 8× size reduction. 2-bit halves memory again and
  is worth evaluating when your top-k feeds a reranker that can absorb
  a recall dip. The bit width is baked into the index (and its files) —
  changing it means re-adding the source vectors.
