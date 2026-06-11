# Packed vectors

Every index method takes vectors as **packed little-endian float32
binary strings** — the output of PHP's `pack()` with the `g` format
code:

```php
$one   = pack('g*', ...$floats);        // one vector
$batch = $one . $another . $third;      // batches: plain concatenation
```

## Why packed strings, not arrays?

A PHP array of one million floats is one million zvals — each a 16-byte
tagged value behind a hashtable, inflated and walked on every call. The
same data as a packed string is a single contiguous buffer the extension
reads in one pass. It's the difference between an FFI boundary crossing
that's effectively a `memcpy` and one that allocates a small heap.

There is deliberately **only one input path**. Methods don't silently
accept arrays and convert them, because that would make the slow path
invisible. If you have arrays, convert explicitly:

```php
use Displace\Vector\Vectors;

$packed = Vectors::pack($floats);              // === pack('g*', ...$floats)
$floats = Vectors::unpack($packed, dim: 768);  // flat list<float> back out
```

`unpack()` returns a *flat* list (it round-trips `pack()` exactly);
use `array_chunk($floats, $dim)` if you want per-vector rows.

## The validation rules

For an index of dimensionality `dim`:

| Input | Rule | On violation |
| ----- | ---- | ------------ |
| `add()` / `addWithIds()` payload | `strlen % (4 * dim) === 0` | `DimensionMismatchException` |
| `search()` query | `strlen === 4 * dim` (exactly one vector) | `DimensionMismatchException` |
| every coordinate | finite, `abs(value) < 1e16` | `InvalidArgumentException` |

The NaN/Inf rule is not pedantry: a single NaN coordinate would silently
corrupt the per-vector scale inside the quantizer — the vector would
count toward `count()` but never match any query. The extension rejects
the payload up front, and a rejected call never partially applies.

## Precision

PHP floats are 64-bit doubles; the packed format is 32-bit floats. The
narrowing happens once, at pack time — identical to what `pack('g*')`
itself does, and far above the precision the 2/4-bit quantizer keeps
anyway.

## Endianness

The format is explicitly little-endian (`g`, not `G`). All supported
platforms are little-endian, and the extension refuses to compile for
big-endian targets, so `pack('g*')` output is portable across every
machine ext-turbovec runs on — including index files moved between
them.

## Where packed vectors come from

- **ext-infer**: `Vectors::pack($embedding->vector())` today; a packed
  fast path on the ext-infer side is planned (see the
  [semantic search recipe](../recipes/semantic-search-with-ext-infer.md)).
- **Remote APIs**: most embedding APIs return JSON arrays —
  `Vectors::pack($response['embedding'])`.
- **Files/DB columns**: if you stored `pack('g*')` blobs, pass them
  through unchanged; concatenation is batching.
