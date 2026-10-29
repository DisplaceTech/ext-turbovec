# Changelog

All notable changes to ext-turbovec are documented here. The format is
based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and
this project adheres to [Semantic Versioning](https://semver.org/)
(pre-1.0: breaking changes bump the minor). Dates are tag dates.

## [0.2.1] - 2026-10-29

### Fixed

- **Stubs match the extension exactly.** They declared `final` on
  SearchResult, SearchResultIterator and Vectors, which the runtime doesn't make final; `array $options = []`
  where the runtime has `?array $options = null`; and they omitted the
  constructors the runtime registers (the exception classes can't be
  constructed from PHP). Now enforced in CI on every leg
  (`make stubs-check` locally). No PHP-visible change: the extension
  behaves exactly as before; only IDE / static-analysis users see the
  corrected signatures.

### Changed

- CI: GitHub Actions on their Node 24 majors (Dependabot).

## [0.2.0] - 2026-10-29

Upstream `turbovec` 0.9.0 → 1.1.2: half the memory, roughly 3× faster
search, no OpenBLAS — and **index files must be rebuilt**.

### Breaking

- **Index files written by 0.1.x cannot be loaded.** 0.1.x wrote
  turbovec format v3; 0.2.0 writes v8 and reads v7–v8. Upstream's v5
  rotation change re-encodes every byte, so v1–v4 files cannot be
  converted, only rebuilt from the source vectors. `load()` on an old
  file throws `IndexIOException` naming ext-turbovec 0.1.x and the
  rebuild steps. See
  [Persistence → Upgrading from 0.1.x](https://turbovec.displace.tech/guide/persistence.html).
- **`dim` is limited to 16384** (was 65536). Upstream lowered `MAX_DIM`
  so an untrusted file header can't drive multi-gigabyte allocations.
  Every common embedding size (up to 4096) is unaffected;
  `new TurboQuantIndex(16392)` and up now throws
  `InvalidArgumentException`.

### Changed

- `turbovec` `=0.9.0` → `=1.1.2`. Measured on a 100K × 1024 4-bit
  `IdMapIndex` (release build, Linux x86_64 AVX2): resident memory after
  `load()` + first search **112 MB → 52 MB**; single-query top-10
  search **17–19 ms → 5.7 ms**; `load()` 45 ms → 200–255 ms; recall@10
  unchanged within noise.
- **No OpenBLAS.** turbovec 1.x is pure Rust (it dropped `ndarray`,
  BLAS and `faer`), so Linux binaries no longer need `libopenblas0` at
  runtime and builds no longer need `libopenblas-dev`.
- Allowlist semantics re-verified unchanged: results ⊆ allowlist,
  `count($allowlist) < k` returns exactly `count($allowlist)` rows,
  filtering happens in the kernel. New AVX-512 (VNNI/VBMI/VPOPCNTDQ)
  and GFNI kernels are runtime-detected; the x86-64-v3 baseline stands.
- `ext-php-rs` `=0.15.13` → `=0.16.3`. A wrong-typed argument now throws
  `\TypeError` (was a plain `\Exception`); `search()`'s trailing
  `?array $allowlist` reports its `null` default through Reflection.
- CI runs weekly as well as on push, tests PHP 8.5 on macOS arm64 again,
  adds ZTS PHP 8.4/8.5 legs on Linux, and pins bindgen's libclang on
  the ubuntu-26.04 runners. Dependabot keeps the non-upstream crates
  current.

### Documentation

- The packed-vector section no longer says ext-infer will emit packed
  vectors "in its next release": ext-infer 0.2.0's `Embedding::packed()`
  does, and the examples now use it.

## [0.1.0] - 2026-06-11

First release.

### Added

- `TurboQuantIndex` (positional ids): `add` / `count` / `search` /
  `write` / `load`.
- `IdMapIndex`: stable external ids, O(1) `remove()`, search-time
  allowlist filtering.
- `SearchResult` (immutable, `Countable`, iterates
  `['id' => int, 'score' => float]` rows best-first); `Vectors::pack()`
  / `unpack()` for the packed float32 contract.
- Typed exceptions rooted at `VectorException` (extends
  `\RuntimeException`).
- Persistence via upstream's versioned `.tv` / `.tvim` formats, with
  bit-exact search round-trips.
- 11-test PHPT suite on synthetic vectors, examples, and docs at
  turbovec.displace.tech. `turbovec` pinned at `=0.9.0` (format v3).

[0.2.1]: https://github.com/DisplaceTech/ext-turbovec/compare/v0.2.0...v0.2.1
[0.2.0]: https://github.com/DisplaceTech/ext-turbovec/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/DisplaceTech/ext-turbovec/releases/tag/v0.1.0
