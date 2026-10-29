# ext-turbovec — design notes

Design notes and decision history: why the surface looks the way it
does, what was tried, and the constraints that bind future work. It is
not a to-do list.

- Status and surface: [`README.md`](README.md)
- What shipped, per release: [`CHANGELOG.md`](CHANGELOG.md)
- Remaining work: [`future` issues](https://github.com/DisplaceTech/ext-turbovec/issues?q=is%3Aissue+is%3Aopen+label%3Afuture), one per idea, each carrying
  the reasoning that used to live here
- How to cut a release: [`RELEASE.md`](RELEASE.md)

## Surface (as of 0.2.0)

| Surface                                        | Status   |
| ---------------------------------------------- | -------- |
| `TurboQuantIndex` (construct/add/count/search/write/load) | shipped |
| `IdMapIndex` (+ addWithIds/remove/allowlist search)        | shipped |
| `SearchResult` (Countable, IteratorAggregate)  | shipped  |
| `Vectors::pack` / `Vectors::unpack`            | shipped  |
| `VectorException` hierarchy                    | shipped  |
| Packed-vector contract (LE float32, validated) | shipped  |
| PHPT suite (12 tests, no model downloads)      | shipped  |
| CI matrix (8.3/8.4/8.5 × {macos-arm64, ubuntu}; weekly schedule) | shipped |
| `composer.json` (PIE-compatible)               | shipped  |
| Tag-triggered binary release workflow          | shipped  |
| `RELEASE.md`                                   | shipped  |
| `examples/` (2 examples + README)              | shipped  |
| Documentation site (turbovec.displace.tech)    | shipped  |
| ZTS-PHP support                                | PHPT suite on ZTS 8.4/8.5 in CI (0.2.0) |

## Releases

Details per release live in [`CHANGELOG.md`](CHANGELOG.md); this table is the one-line history.

| Version | Date | Notes |
| ------- | ---- | ----- |
| v0.2.0  | unreleased | turbovec 1.1.2: index format v8 (0.1.x files must be rebuilt), `dim` ≤ 16384, ~half the memory, ~3× faster search, no OpenBLAS. |
| v0.1.0  | 2026-06-11 | First release (turbovec 0.9.0, format v3). |

## Remaining work

Tracked as [`future` issues](https://github.com/DisplaceTech/ext-turbovec/issues?q=is%3Aissue+is%3Aopen+label%3Afuture); the design reasoning moved into each
issue's body:

- [#2](https://github.com/DisplaceTech/ext-turbovec/issues/2) Batch search (multiple queries per call)
- [#3](https://github.com/DisplaceTech/ext-turbovec/issues/3) mmap-backed `load()`
- [#4](https://github.com/DisplaceTech/ext-turbovec/issues/4) Range / threshold search
- [#5](https://github.com/DisplaceTech/ext-turbovec/issues/5) Zero-copy packed decode (measure first)

Done since the last revision of this file: the packed handoff from
ext-infer (`Embedding::packed()`, used by the examples and recipes) and
a ZTS CI leg.

## Decisions

| Date | Decision | Why |
| ---- | -------- | --- |
| 2026-10-29 | Bump turbovec 0.9.0 → 1.1.2 and ship as 0.2.0 despite the format break. | 0.1.x's v3 files can't be read or converted by any turbovec ≥ 1.0 (the v5 rotation change re-encodes every byte), so staying on 0.9 meant freezing on an abandoned format. 1.x halves resident memory, is ~3× faster, and drops OpenBLAS. `load()` names the old release and says to rebuild. |
| 2026-10-29 | Accept upstream's `MAX_DIM` = 16384 rather than patching around it. | It bounds allocations an untrusted file header can request; every common embedding size is ≤ 4096. Documented as breaking in 0.2.0. |
| 2026-06-11 | Restrict `bitWidth` to {2, 4}. | The configurations the SIMD kernels are optimized for and the only ones this extension commits to supporting. |

## Deliberately out of scope

- **Windows** — `os-families-exclude` in composer.json makes PIE skip
  Windows hosts cleanly.
- **GPU** — TurboQuant is a CPU-SIMD design; upstream has no GPU path.
- **Graph indexes (HNSW-style)** — the flat quantized scan with
  kernel-level filtering is the niche this extension serves; graph
  structures bring rebuild/tombstone complexity that contradicts it.

## Operational notes

### Upstream `turbovec` is pinned exactly

`=1.1.2`. The `.tv`/`.tvim` formats are versioned upstream: 1.1.2
writes v8 and reads v7/v8; `turbovec::convert` can move v5–v8 files
between versions, but nothing reads v1–v4. Bumping the pin is a
deliberate event: read upstream's changelog for format changes, write
an index of every type with the old build and `load()` it with the new
one (the 0.2.0 check is in the PR), run the full PHPT suite, and state
index compatibility first in the release notes. ext-template's upstream
watcher opens an issue when crates.io moves ahead.

### OpenBLAS (history)

turbovec 0.9 linked `-lopenblas` on Linux through `ndarray`'s `blas`
feature, so 0.1.x Linux binaries carried a `DT_NEEDED` on
`libopenblas.so.0`. turbovec 1.x dropped `ndarray`, BLAS and `faer`;
since 0.2.0 the binaries link only the C runtime and the build no
longer needs `libopenblas-dev`.

### Rust 1.89 floor

Upstream's AVX-512BW kernel uses `#[target_feature(enable =
"avx512bw", ...)]`, stabilized in Rust 1.89 — that's why the
platform-wide toolchain floor (ext-template's `versions.conf`) is 1.89.
turbovec 1.1.2 still declares `rust-version = "1.89"`.

### `target-cpu=x86-64-v3` vs `RUSTFLAGS`

`.cargo/config.toml` sets the flag for local builds, but any `RUSTFLAGS`
in the environment silently replaces config-file rustflags. CI sets
`RUSTFLAGS=-D warnings`, so CI builds *drop* the flag — harmless, since
the SIMD kernels are runtime-dispatched and only non-kernel codegen
loses vectorization. The release workflow re-asserts it inside
`RUSTFLAGS` on x86_64 legs so shipped binaries keep it. If you touch
either file, keep them coherent.

### `ext-php-rs` is pre-1.0

Pinned through ext-template's `versions.conf` (`=0.16.3`), same as
every extension. The `#[php(implements(...))]` linkage `SearchResult`
relies on (and the class-typed `getIterator()` return arginfo) should be
re-verified with the 010 PHPT on any bump, along with a Reflection dump
diff.

### `spl_ce_RuntimeException`

`VectorException` extends `\RuntimeException` via the PHPAPI-exposed
SPL symbol, resolved at extension load. Same caveat as ext-infer: it's
a stable symbol, not a documented contract — see `src/error.rs`.

## Working agreements

- Pre-1.0, breaking changes happen between minors (0.1 → 0.2), not
  patches. Once we tag `v1.0.0`, the class/method surface is frozen
  and we follow strict SemVer.
- All new public surface lands behind a PHPT test that fails before
  the implementation and passes after.
- Every `unsafe` block carries a `// SAFETY:` comment naming the
  invariant it relies on. (Today there are zero `unsafe` blocks outside
  the SPL class-entry accessor.)
- Each commit is reviewable in isolation: one logical change, a
  message that says *why* (the *what* is in the diff).
