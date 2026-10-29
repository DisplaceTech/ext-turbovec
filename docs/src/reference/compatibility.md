# Compatibility matrix

|                | macOS arm64 | Linux x86_64 | Linux arm64 | Windows |
| -------------- | :---------: | :----------: | :---------: | :-----: |
| **PHP 8.3**    |      ✅     |      ✅      |      ✅     |    —    |
| **PHP 8.4**    |      ✅     |      ✅      |      ✅     |    —    |
| **PHP 8.5**    |      ✅     |      ✅      |      ✅     |    —    |

Release binaries are NTS. ZTS is enabled in `composer.json` (PIE builds
from source on thread-safe PHP) and the code is thread-safe by design
(search is immutable-shared; objects are request-local). CI runs the
PHPT suite on ZTS PHP 8.4 and 8.5 (Linux) every week.

## CPU

CPU-only by design — no GPU path exists upstream.

- **x86_64**: binaries target the x86-64-v3 baseline (Haswell 2013+ —
  AVX2/FMA) for general code; the AVX-512 kernels (BW, plus VNNI /
  VBMI / VPOPCNTDQ / GFNI variants where present) are selected at
  runtime by CPU feature detection, with AVX2 below them.
- **arm64**: NEON kernels, no special requirements (Apple Silicon and
  Graviton-class cores both qualify).
- Big-endian platforms are unsupported (the packed-vector ABI is
  little-endian; the extension refuses to compile there).

## Runtime dependencies

None. Since 0.2.0 the upstream engine is pure Rust — binaries link only
the C runtime. (0.1.x needed `libopenblas0` on Linux.)

## Upstream pin

| ext-turbovec | turbovec crate | index format |
| ------------ | -------------- | ------------ |
| 0.2.x | =1.1.2 | writes v8, reads v7–v8; refuses 0.1.x (v3) files with a rebuild hint |
| 0.1.x | =0.9.0 | v3 (reads v2; refuses v1 with a rebuild hint) |

The pin is exact so a given extension version always speaks one known
on-disk format. Index files are portable across all supported
platforms.

## musl / Alpine

Not in the release matrix. Source builds should work (the repo carries
the `crt-static` opt-out musl cdylibs need), but they're not
CI-verified.
