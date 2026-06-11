# API surface

Everything lives in the `Displace\Vector` namespace. Authoritative
signatures (IDE-ready, with docblocks) are in
[`stubs/vector.stubs.php`](https://github.com/DisplaceTech/ext-turbovec/blob/main/stubs/vector.stubs.php).

## `TurboQuantIndex`

Positional ids: the Nth vector added is id N. No removal.

| Method | Notes |
| ------ | ----- |
| `__construct(int $dim, int $bitWidth = 4)` | `dim`: positive multiple of 8, ≤ 65536. `bitWidth`: 2 or 4. |
| `add(string $vectors): void` | Packed batch; `strlen % (4*dim) === 0`. Empty string is a no-op. |
| `count(): int` | Vectors currently in the index. |
| `search(string $query, int $k = 10): SearchResult` | Exactly one packed vector; `k >= 1`. Returns `min(k, count)` rows, best-first. |
| `write(string $path): void` | Versioned `.tv` snapshot; bit-exact round-trip. |
| `static load(string $path): TurboQuantIndex` | |

## `IdMapIndex`

Stable external ids (`0..PHP_INT_MAX`), O(1) removal, allowlist
filtering. Same constructor, `count()`, `write()`/`load()` (`.tvim`
format) as above, plus:

| Method | Notes |
| ------ | ----- |
| `addWithIds(string $vectors, array $ids): void` | One non-negative int per vector. Duplicate/known ids rejected up front; never partially applies. |
| `search(string $query, int $k = 10, ?array $allowlist = null): SearchResult` | With an allowlist: results ⊆ allowlist, row count `min(k, count(unique allowlist))`. Empty allowlist throws; unknown ids throw. |
| `remove(int $id): void` | O(1). Absent id throws. |

## `SearchResult`

Immutable; implements `Countable` and `IteratorAggregate`. Not
directly constructible.

| Method | Notes |
| ------ | ----- |
| `ids(): array` | `list<int>`, best-first. Positional slots or your external ids, per the producing index. |
| `scores(): array` | `list<float>`, parallel to `ids()`. Inner-product similarity — higher is better; equals cosine for unit-length vectors. |
| `count(): int` | Row count (also `count($result)`). |
| `getIterator(): SearchResultIterator` | Rows iterate as `['id' => int, 'score' => float]` (also implicit in `foreach`). |

`SearchResultIterator` is an internal support class (`@internal`); it
implements `\Iterator` and exists so `getIterator()` satisfies the
interface — obtain it via `foreach`, not directly.

## `Vectors`

Static helpers; not instantiable.

| Method | Notes |
| ------ | ----- |
| `static pack(array $floats): string` | Byte-identical to `pack('g*', ...$floats)`. Ints accepted; anything else throws. |
| `static unpack(string $packed, int $dim): array` | Flat `list<float>`; validates `strlen % (4*dim) === 0`. Exact `pack()` round-trip. |

## Exceptions

See [Exceptions](./exceptions.md) for the hierarchy and which methods
throw what.
