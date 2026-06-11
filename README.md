<h1 align="center">ext-turbovec</h1>

<p align="center">
  <strong>Native vector search for PHP, in-process.</strong><br>
  Quantized ANN indexing over millions of embeddings — no vector database, no sidecar, no remote API.
</p>

<p align="center">
  <a href="https://github.com/DisplaceTech/ext-turbovec/actions/workflows/ci.yml"><img src="https://github.com/DisplaceTech/ext-turbovec/actions/workflows/ci.yml/badge.svg" alt="CI" /></a>
  <a href="https://github.com/DisplaceTech/ext-turbovec/releases/latest"><img src="https://img.shields.io/github/v/release/DisplaceTech/ext-turbovec?sort=semver&include_prereleases" alt="Latest release" /></a>
  <img src="https://img.shields.io/badge/PHP-8.3%20%7C%208.4%20%7C%208.5-777BB4?logo=php&logoColor=white" alt="PHP 8.3 / 8.4 / 8.5" />
  <img src="https://img.shields.io/badge/Status-pre--release-orange" alt="Pre-release" />
  <a href="LICENSE"><img src="https://img.shields.io/badge/License-MIT-green" alt="MIT License" /></a>
  <a href="https://turbovec.displace.tech"><img src="https://img.shields.io/badge/docs-turbovec.displace.tech-blue" alt="Documentation" /></a>
</p>

---

## What is ext-turbovec?

`ext-turbovec` is a PHP 8.3+ extension for in-process vector indexing and
approximate-nearest-neighbor search. It is the retrieval half of a fully
local RAG stack for PHP: [ext-infer](https://github.com/DisplaceTech/ext-infer)
generates the embeddings, ext-turbovec indexes and searches them. No
Python sidecar, no vector database, no data leaving the machine.

Under the hood it binds the [`turbovec`](https://github.com/RyanCodrai/turbovec)
Rust crate by Ryan Codrai — an implementation of Google Research's
**TurboQuant** quantization algorithm
([Gollapudi et al., arXiv:2504.19874](https://arxiv.org/abs/2504.19874)):
data-oblivious 2–4-bit compression with near-optimal distortion, no training
phase, and SIMD search kernels (NEON on ARM, AVX2/AVX-512BW on x86) that
beat FAISS `IndexPQFastScan` on most configurations. All credit for the
index engine goes upstream — this extension is a downstream bindings
consumer, not a fork.

- 🗜️ **4-bit vectors** — a 10M-document corpus that needs 31 GB as float32 fits in ~4 GB.
- ➕ **Online ingest** — add vectors and they're searchable; no train step, no rebuilds.
- 🔍 **Filtered search** — pass an id allowlist and the SIMD kernel honors it directly; selective filters get *faster*, not less accurate.
- 🆔 **Stable ids** — `IdMapIndex` addresses vectors by your own uint64 ids (SQL primary keys), with O(1) `remove()`.
- 💾 **Persistence** — versioned on-disk formats (`.tv` / `.tvim`) that round-trip search results bit-exactly.
- ⚡ **In-process** — search latency is the kernel time; there's no IPC or network in the loop.

## Quick start

```sh
pie install displace/ext-turbovec      # or: make build && php -d extension=...
```

```php
<?php
use Displace\Vector\IdMapIndex;

$index = new IdMapIndex(dim: 1024, bitWidth: 4);

// Vectors enter as packed float32 strings — pack('g*', ...) output.
$index->addWithIds(
    pack('g*', ...$embeddingA) . pack('g*', ...$embeddingB),
    [101, 102],                       // your ids, e.g. SQL primary keys
);

$result = $index->search(pack('g*', ...$queryEmbedding), k: 10);
foreach ($result as $row) {
    printf("doc %d scored %.4f\n", $row['id'], $row['score']);
}

$index->write('corpus.tvim');         // ...and IdMapIndex::load() it back
```

Pair it with ext-infer for end-to-end semantic search — see
[`examples/semantic-search.php`](examples/semantic-search.php), or
[`examples/basic-search.php`](examples/basic-search.php) for a standalone,
model-free walkthrough.

## The packed-vector contract

Index methods accept vectors as **packed little-endian float32 binary
strings** — exactly what PHP's `pack()` produces with the `g` format code:

```php
$packed = pack('g*', ...$floats);             // one vector
$batch  = $packedA . $packedB . $packedC;     // batches are plain concatenation
```

`strlen($payload)` must be a whole multiple of `4 * $dim`; anything else
throws `DimensionMismatchException`. This is deliberately the *only* input
path — packed strings cross the FFI boundary as a single memcpy-sized blob
instead of millions of PHP zvals, and it's the format ext-infer's
`EmbeddingModel` will emit natively in its next release. For array-minded
code, `Vectors::pack(array $floats): string` and
`Vectors::unpack(string $packed, int $dim): array` are the on-ramp.

## Memory math

The index stores `bitWidth` bits per coordinate plus one float32 scale per
vector. For 100,000 documents at dim 1024 with 4-bit quantization:

```
100,000 × 1024 × 4 bits  =  51.2 MB   (codes)
100,000 × 4 bytes        =   0.4 MB   (scales)
                            ─────────
                            ~52 MB    vs 410 MB as raw float32
```

`bitWidth: 2` halves the codes again when recall requirements allow.
`dim` must be a positive multiple of 8 (every common embedding size
qualifies: 384, 512, 768, 1024, 1536, 3072, 4096, …).

## API surface (v0.1)

| Class | Methods |
| ----- | ------- |
| `Displace\Vector\TurboQuantIndex` | `__construct(int $dim, int $bitWidth = 4)` · `add(string $vectors)` · `count()` · `search(string $query, int $k = 10): SearchResult` · `write(string $path)` · `static load(string $path)` |
| `Displace\Vector\IdMapIndex` | as above, plus `addWithIds(string $vectors, array $ids)` · `search(string $query, int $k = 10, ?array $allowlist = null)` · `remove(int $id)` |
| `Displace\Vector\SearchResult` | `ids(): array` · `scores(): array` · `count(): int` · `Countable` · `IteratorAggregate` yielding `['id' => int, 'score' => float]` |
| `Displace\Vector\Vectors` | `static pack(array $floats): string` · `static unpack(string $packed, int $dim): array` |

Exceptions: `VectorException` (extends `\RuntimeException`) with
`InvalidArgumentException`, `DimensionMismatchException`, and
`IndexIOException` beneath it. Full reference with semantics at
[turbovec.displace.tech](https://turbovec.displace.tech).

## Compatibility

|                | macOS arm64 | Linux x86_64 | Linux arm64 | Windows |
| -------------- | :---------: | :----------: | :---------: | :-----: |
| **PHP 8.3**    |      ✅     |      ✅      |      ✅     |    —    |
| **PHP 8.4**    |      ✅     |      ✅      |      ✅     |    —    |
| **PHP 8.5**    |      ✅     |      ✅      |      ✅     |    —    |

CPU-only by design. On x86_64 the AVX-512BW kernel engages automatically
when the CPU has it, with an AVX2 fallback (Haswell 2013+) below it.
**Linux note:** the upstream engine links OpenBLAS — binaries need the
`libopenblas0` package at runtime (`libopenblas-dev` to build from
source). macOS uses the built-in Accelerate framework; nothing to install.

This release pins upstream [`turbovec` 0.9.0](https://crates.io/crates/turbovec).
The pin is exact because the on-disk index formats must stay stable;
upstream bumps are deliberate, documented events (see RELEASE.md).

## Roadmap

**Shipped (v0.1)** &nbsp; `TurboQuantIndex` · `IdMapIndex` with allowlist
filtering and O(1) remove · packed-vector fast path · `Vectors` helpers ·
write/load persistence · typed exceptions · PHPT suite · CI matrix ·
PIE-compatible binary releases.

**Next (v0.2)** &nbsp; batch search (multi-query in one call) ·
mmap-backed `load()` · range/threshold search · zero-copy packed handoff
from ext-infer's `EmbeddingModel`.

**Deliberately out of scope** &nbsp; Windows · GPU · graph indexes
(HNSW-style) — TurboQuant's flat quantized scan is the sweet spot this
extension is built around.

## License

[MIT](LICENSE) © 2026 Eric Mann / Displace Technologies.
Bundles [turbovec](https://github.com/RyanCodrai/turbovec) © Ryan Codrai,
MIT — see [THIRD-PARTY-NOTICES.md](THIRD-PARTY-NOTICES.md). TurboQuant is
the work of Gollapudi et al., [arXiv:2504.19874](https://arxiv.org/abs/2504.19874).
