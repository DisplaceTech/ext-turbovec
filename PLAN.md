# ext-turbovec — plan

Living document. Each section answers "what's left, and why does it
matter." Project status / surface description lives in
[`README.md`](README.md); how-to-cut-a-release lives in
[`RELEASE.md`](RELEASE.md).

## Status snapshot

| Surface                                        | Status   |
| ---------------------------------------------- | -------- |
| `TurboQuantIndex` (construct/add/count/search/write/load) | shipped |
| `IdMapIndex` (+ addWithIds/remove/allowlist search)        | shipped |
| `SearchResult` (Countable, IteratorAggregate)  | shipped  |
| `Vectors::pack` / `Vectors::unpack`            | shipped  |
| `VectorException` hierarchy                    | shipped  |
| Packed-vector contract (LE float32, validated) | shipped  |
| PHPT suite (11 tests, no model downloads)      | shipped  |
| CI matrix (8.3/8.4/8.5 × {macos-arm64, ubuntu, ubuntu-arm64}) | shipped |
| `composer.json` (PIE-compatible)               | shipped  |
| Tag-triggered binary release workflow          | shipped  |
| `RELEASE.md`                                   | shipped  |
| `examples/` (2 examples + README)              | shipped  |
| Documentation site (turbovec.displace.tech)    | shipped  |
| ZTS-PHP support                                | enabled in composer.json, untested in CI |

## Releases

| Version | Date | Notes |
| ------- | ---- | ----- |
| —       | —    | v0.1.0 pending |

## Up next

### Batch search

Upstream `search()` is natively multi-query — the binding currently
restricts to one query per call. A `searchBatch(string $queries, int $k):
array<SearchResult>` (or a `BatchResult` with per-query slicing) amortizes
the FFI crossing and lets the kernel exploit its 4-query SIMD path.
Decide result shape before implementing.

### Packed handoff from ext-infer

`examples/semantic-search.php` currently round-trips
`Embedding::vector()` (PHP float array) through `Vectors::pack()`.
ext-infer's next release will emit packed float32 directly; when it
does, update the example and the semantic-search recipe to the zero-copy
idiom and document the pairing as the canonical local RAG stack.

### mmap-backed load

`load()` reads the whole file into memory. For multi-GB indexes in FPM
fleets, an mmap-backed variant would let workers share page cache.
Upstream work required first — track upstream's roadmap before
designing the PHP surface.

### Range / threshold search

"All matches with score >= t" complements top-k for dedup and
clustering use cases. Needs upstream support; the PHP surface would be
`searchRange(string $query, float $threshold): SearchResult`.

### ZTS exercise

Same posture as ext-infer: the code is thread-safe by design (upstream
`search()` is `&self` with `OnceLock` caches; PHP objects are
request-local), `support-zts` is declared in composer.json, but no ZTS
runner exists in CI yet. Add a ZTS leg when the tooling allows.

### Zero-copy packed decode

`packed.rs` copies every payload through `f32::from_le_bytes` (see the
module comment for why that's the right default). If profiling ever
shows the copy mattering against the encode it feeds, add a
`binary_slice`-based aligned fast path behind the same validation.
Measure first; the copy is O(input) against O(input × dim) work.

## Deliberately out of scope

- **Windows** — `os-families-exclude` in composer.json makes PIE skip
  Windows hosts cleanly.
- **GPU** — TurboQuant is a CPU-SIMD design; upstream has no GPU path.
- **Graph indexes (HNSW-style)** — the flat quantized scan with
  kernel-level filtering is the niche this extension serves; graph
  structures bring rebuild/tombstone complexity that contradicts it.

## Operational notes

### Upstream `turbovec` is pinned exactly

`=0.9.0`. The `.tv`/`.tvim` on-disk formats are versioned upstream
(format v3 as of 0.6.x; v2 loads transparently; v1 is refused with a
rebuild hint). Bumping the pin is a deliberate event: check the
upstream changelog for format changes, run the full PHPT suite, and
call out index compatibility in the release notes.

### OpenBLAS on Linux

Upstream's build script links `-lopenblas` on Linux (macOS uses the
always-present Accelerate framework). Consequences:

- Building needs `libopenblas-dev` (CI installs it on every Linux leg).
- Shipped Linux binaries carry a `DT_NEEDED` on `libopenblas.so.0` —
  users need the `libopenblas0` package at runtime. Documented in the
  README compatibility section; keep it in the release-notes template.
- A static-BLAS or pure-Rust fallback build would remove the runtime
  dependency — investigate upstream appetite before doing anything
  downstream.

### Rust 1.89 floor

Upstream's AVX-512BW kernel uses `#[target_feature(enable =
"avx512bw", ...)]`, stabilized in Rust 1.89 — that's why this repo's
toolchain pin is newer than ext-infer's 1.88. Keep the pin and
`rust-version` in lockstep.

### `target-cpu=x86-64-v3` vs `RUSTFLAGS`

`.cargo/config.toml` sets the flag for local builds, but any `RUSTFLAGS`
in the environment silently replaces config-file rustflags. CI sets
`RUSTFLAGS=-D warnings`, so CI builds *drop* the flag — harmless, since
the SIMD kernels are runtime-dispatched and only non-kernel codegen
loses vectorization. The release workflow re-asserts it inside
`RUSTFLAGS` on x86_64 legs so shipped binaries keep it. If you touch
either file, keep them coherent.

### `ext-php-rs` is pre-1.0

Pinned at 0.15.13, same as ext-infer. The `#[php(implements(...))]`
linkage `SearchResult` relies on (and the class-typed `getIterator()`
return arginfo) should be re-verified with the 010 PHPT on any bump.

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
