<?php

// Stubs for ext-turbovec — IDE / static-analysis only, not loaded at runtime.
//
// Regenerate from the registered classes after building:
//
//     make stubs   # wraps `cargo php stubs --stubs stubs/vector.stubs.php`

namespace Displace\Vector;

/**
 * Base exception for all ext-turbovec failures. Extends \RuntimeException
 * so existing catch (\RuntimeException $e) clauses continue to work.
 */
class VectorException extends \RuntimeException
{
}

/**
 * A caller-supplied argument was malformed: a bit width other than 2 or 4,
 * `k < 1`, NaN/Inf vector values, a negative or unknown id, an empty
 * allowlist, ...
 */
class InvalidArgumentException extends \Displace\Vector\VectorException
{
}

/**
 * A packed-vector payload disagrees with the index dimensionality:
 * `strlen($vectors)` not a multiple of `4 * dim`, or a query that isn't
 * exactly one `dim`-sized vector. A dimension mismatch is a kind of
 * invalid argument, hence the parent class.
 */
class DimensionMismatchException extends \Displace\Vector\InvalidArgumentException
{
}

/**
 * `write()` / `load()` failed: unreadable path, permissions, truncated
 * file, bad magic bytes, or an incompatible index-format version.
 */
class IndexIOException extends \Displace\Vector\VectorException
{
}

/**
 * Static pack/unpack helpers for the packed-vector contract.
 *
 * The index classes only accept packed little-endian float32 strings (the
 * output of `pack('g*', ...$floats)`). These helpers are the on-ramp for
 * array-minded callers; on the hot path, prefer producing packed strings
 * directly.
 */
final class Vectors
{
    /** @throws \Displace\Vector\VectorException Always — static helper class. */
    public function __construct() {}

    /**
     * Pack a flat list of floats — byte-identical to `pack('g*', ...$floats)`.
     * Ints are accepted and packed as floats; anything else throws. PHP
     * floats are doubles and narrow to float32 here, exactly as `pack('g*')`
     * would.
     *
     * @param list<int|float> $floats
     *
     * @throws \Displace\Vector\InvalidArgumentException If an element is not an int or float.
     */
    public static function pack(array $floats): string {}

    /**
     * Unpack a binary string back into a flat list of floats. `$dim`
     * validates that the payload holds whole vectors — `strlen($packed)`
     * must be a multiple of `4 * $dim`. Use `array_chunk($floats, $dim)`
     * for per-vector rows. Round-trips `pack()` exactly for
     * f32-representable values.
     *
     * @return list<float>
     *
     * @throws \Displace\Vector\InvalidArgumentException   If `$dim < 1`.
     * @throws \Displace\Vector\DimensionMismatchException If the length check fails.
     */
    public static function unpack(string $packed, int $dim): array {}
}

/**
 * Cursor over a SearchResult's rows.
 *
 * @internal Support class that exists to satisfy `IteratorAggregate` with
 *           a covariant return type. Obtain one via
 *           `SearchResult::getIterator()` (or just `foreach`).
 *
 * @implements \Iterator<int, array{id: int, score: float}>
 */
final class SearchResultIterator implements \Iterator
{
    /** @throws \Displace\Vector\VectorException Always. */
    public function __construct() {}

    /**
     * The current row.
     *
     * @return array{id: int, score: float}
     */
    public function current(): array {}

    /** Zero-based row position. */
    public function key(): int {}

    public function next(): void {}

    public function valid(): bool {}

    public function rewind(): void {}
}

/**
 * Immutable result of a top-k search, ordered best-first.
 *
 * Scores are inner-product similarities from the quantized kernel (higher
 * is better). Ids are positional slots for `TurboQuantIndex` and your
 * stable external ids for `IdMapIndex`. Iterates as
 * `['id' => int, 'score' => float]` rows; direct construction is refused.
 *
 * @implements \IteratorAggregate<int, array{id: int, score: float}>
 */
final class SearchResult implements \Countable, \IteratorAggregate
{
    /** @throws \Displace\Vector\VectorException Always. */
    public function __construct() {}

    /**
     * Result ids, best-first, parallel to `scores()`.
     *
     * @return list<int>
     */
    public function ids(): array {}

    /**
     * Similarity scores, best-first, parallel to `ids()`. Higher is better.
     *
     * @return list<float>
     */
    public function scores(): array {}

    /**
     * Number of result rows — may be less than the requested `k` when the
     * index (or the allowlist) holds fewer vectors.
     */
    public function count(): int {}

    /** Iterator over `['id' => int, 'score' => float]` rows, best-first. */
    public function getIterator(): \Displace\Vector\SearchResultIterator {}
}

/**
 * Quantized vector index with positional ids: the Nth vector added is
 * id N. No removal — use `IdMapIndex` for stable external ids and O(1)
 * `remove()`.
 *
 * Vectors enter as packed little-endian float32 strings — the output of
 * `pack('g*', ...$floats)`, batches by plain concatenation. Search takes
 * `&self` internally and is safe to call concurrently.
 */
class TurboQuantIndex
{
    /**
     * @param int $dim      Vector dimensionality. Must be a positive
     *                      multiple of 8, at most 65536 (every common
     *                      embedding size qualifies).
     * @param int $bitWidth Bits per coordinate after quantization: 2 or 4.
     *
     * @throws \Displace\Vector\InvalidArgumentException On a bad dim or bitWidth.
     */
    public function __construct(int $dim, int $bitWidth = 4) {}

    /**
     * Add a batch of vectors. `strlen($vectors)` must be a multiple of
     * `4 * dim`; an empty string is a no-op.
     *
     * @throws \Displace\Vector\DimensionMismatchException If the payload length disagrees with dim.
     * @throws \Displace\Vector\InvalidArgumentException   If any coordinate is NaN/Inf.
     */
    public function add(string $vectors): void {}

    /** Number of vectors currently in the index. */
    public function count(): int {}

    /**
     * Top-`k` search for a single packed query vector. Returns up to `k`
     * rows, best-first — fewer when the index holds fewer vectors.
     *
     * @throws \Displace\Vector\DimensionMismatchException If `$query` is not exactly one packed vector.
     * @throws \Displace\Vector\InvalidArgumentException   If `k < 1` or the query contains NaN/Inf.
     */
    public function search(string $query, int $k = 10): \Displace\Vector\SearchResult {}

    /**
     * Persist to `$path` (the versioned `.tv` format). Round-trips
     * bit-exactly through `load()`.
     *
     * @throws \Displace\Vector\IndexIOException On filesystem failure.
     */
    public function write(string $path): void {}

    /**
     * Load an index previously persisted with `write()`.
     *
     * @throws \Displace\Vector\IndexIOException On a missing/corrupt file or incompatible format version.
     */
    public static function load(string $path): \Displace\Vector\TurboQuantIndex {}
}

/**
 * Quantized vector index addressed by stable external ids (e.g. SQL
 * primary keys) — ids survive other vectors' insertion and removal, and
 * `remove()` is O(1). Supports search-time allowlist filtering inside the
 * SIMD kernel.
 */
class IdMapIndex
{
    /**
     * @param int $dim      Vector dimensionality. Must be a positive
     *                      multiple of 8, at most 65536.
     * @param int $bitWidth Bits per coordinate after quantization: 2 or 4.
     *
     * @throws \Displace\Vector\InvalidArgumentException On a bad dim or bitWidth.
     */
    public function __construct(int $dim, int $bitWidth = 4) {}

    /**
     * Add a batch of vectors with caller-chosen stable ids — one
     * non-negative int per vector. Ids already present, or duplicated
     * within the call, are rejected before anything is added; a failed
     * call never partially applies.
     *
     * @param list<int> $ids
     *
     * @throws \Displace\Vector\DimensionMismatchException If the payload length disagrees with dim.
     * @throws \Displace\Vector\InvalidArgumentException   On an id count mismatch, negative id,
     *                                                     duplicate id, or NaN/Inf coordinate.
     */
    public function addWithIds(string $vectors, array $ids): void {}

    /** Number of vectors currently in the index. */
    public function count(): int {}

    /**
     * Top-`k` search, optionally restricted to `$allowlist` ids.
     *
     * With an allowlist, every returned id is from the allowlist and the
     * row count is `min($k, count($allowlist))` after deduplication — a
     * small allowlist yields exactly that many rows, never padded
     * fallbacks. The allowlist must be non-empty (pass `null` to search
     * unfiltered) and every id in it must currently be in the index.
     *
     * @param list<int>|null $allowlist
     *
     * @throws \Displace\Vector\DimensionMismatchException If `$query` is not exactly one packed vector.
     * @throws \Displace\Vector\InvalidArgumentException   If `k < 1`, the query contains NaN/Inf,
     *                                                     or the allowlist is empty / holds an
     *                                                     unknown or negative id.
     */
    public function search(string $query, int $k = 10, ?array $allowlist = null): \Displace\Vector\SearchResult {}

    /**
     * Remove the vector with this id, in O(1). Throws when the id is not
     * present — a remove that removes nothing is almost always a bug.
     *
     * @throws \Displace\Vector\InvalidArgumentException If `$id` is negative or not in the index.
     */
    public function remove(int $id): void {}

    /**
     * Persist to `$path` (the versioned `.tvim` format — quantized index
     * plus id tables). Round-trips bit-exactly through `load()`.
     *
     * @throws \Displace\Vector\IndexIOException On filesystem failure.
     */
    public function write(string $path): void {}

    /**
     * Load an index previously persisted with `write()`.
     *
     * @throws \Displace\Vector\IndexIOException On a missing/corrupt file or incompatible format version.
     */
    public static function load(string $path): \Displace\Vector\IdMapIndex {}
}
