# Compatibility matrix

|                | macOS arm64 | Linux x86_64 | Linux arm64 | Windows |
| -------------- | :---------: | :----------: | :---------: | :-----: |
| **PHP 8.3**    |      ✅     |      ✅      |      ✅     |    —    |
| **PHP 8.4**    |      ✅     |      ✅      |      ✅     |    —    |
| **PHP 8.5**    |      ✅     |      ✅      |      ✅     |    —    |

Release binaries are NTS. ZTS is enabled in `composer.json` and the
code is thread-safe by design (search is immutable-shared; objects are
request-local), but no ZTS runner exercises it in CI yet.

## CPU

CPU-only by design — no GPU path exists upstream.

- **x86_64**: binaries target the x86-64-v3 baseline (Haswell 2013+ —
  AVX2/FMA) for general code; the AVX-512BW kernel engages automatically
  at runtime where available, and pre-AVX2 CPUs fall back to a scalar
  path with identical results.
- **arm64**: NEON kernels, no special requirements (Apple Silicon and
  Graviton-class cores both qualify).
- Big-endian platforms are unsupported (the packed-vector ABI is
  little-endian; the extension refuses to compile there).

## Runtime dependencies

| Platform | Needs |
| -------- | ----- |
| Linux | `libopenblas0` (`libopenblas-dev` to build from source) |
| macOS | nothing — Accelerate ships with the OS |

## Upstream pin

| ext-turbovec | turbovec crate | index format |
| ------------ | -------------- | ------------ |
| 0.1.x | =0.9.0 | v3 (reads v2; refuses v1 with a rebuild hint) |

The pin is exact so a given extension version always speaks one known
on-disk format. Index files are portable across all supported
platforms.

## musl / Alpine

Not in the release matrix. Source builds should work (the repo carries
the `crt-static` opt-out musl cdylibs need; install `openblas-dev`),
but they're not CI-verified.
