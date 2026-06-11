# Exceptions

```text
\RuntimeException
  └── Displace\Vector\VectorException
        ├── Displace\Vector\InvalidArgumentException
        │     └── Displace\Vector\DimensionMismatchException
        └── Displace\Vector\IndexIOException
```

Catch `VectorException` for "anything this extension threw";
`\RuntimeException` clauses you already have keep working. A dimension
mismatch *is* an invalid argument, hence the nesting — catch at
whichever precision you need.

## `InvalidArgumentException`

A malformed argument that isn't a payload-length problem:

- `bitWidth` other than 2 or 4; `dim` not a positive multiple of 8 or
  over 65536
- `k < 1`
- NaN/Inf/`|x| >= 1e16` coordinates in a vector or query (these would
  silently corrupt the index, so they're rejected up front)
- negative ids; ids already present; duplicate ids within one
  `addWithIds()` call; id-count ≠ vector-count
- `remove()` of an id not in the index
- an empty allowlist (pass `null` to search unfiltered) or an allowlist
  id not in the index

## `DimensionMismatchException`

The packed payload disagrees with the index's `dim`:

- `add()`/`addWithIds()`: `strlen($vectors)` not a multiple of `4 * dim`
- `search()`: `strlen($query) !== 4 * dim` (exactly one vector)
- `Vectors::unpack()`: length not a multiple of `4 * dim`

## `IndexIOException`

`write()`/`load()` filesystem and format failures: missing or
unreadable path, permissions, truncated file, wrong magic bytes
(e.g. loading a `.tvim` with `TurboQuantIndex`), or an incompatible
format version. Messages include the offending path.

## `VectorException` (directly)

Thrown directly only for refused construction of result/helper classes
(`new SearchResult`, `new Vectors`, ...), which are produced by the API
rather than instantiated.

## Guarantees

A throwing call never partially applies: a rejected batch adds nothing,
ids from a failed `addWithIds()` are not reserved, and the index is
left exactly as it was.
