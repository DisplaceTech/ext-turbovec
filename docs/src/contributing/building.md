# Building from source

## Prerequisites

- **Rust** — `rustup` honors the repo's `rust-toolchain.toml` (1.89.0;
  the floor comes from upstream's AVX-512 target features).
- **PHP 8.3+ with dev headers** — `php-config` must be on PATH (or set
  `PHP_CONFIG`). Debian/Ubuntu: `apt install php8.3-dev`; macOS:
  Homebrew PHP includes it.
- **libclang** — for ext-php-rs's bindgen. Debian/Ubuntu:
  `apt install libclang-dev`; macOS: ships with Xcode CLT.
- **OpenBLAS (Linux only)** — `apt install libopenblas-dev`. macOS uses
  the built-in Accelerate framework.

## Build

```sh
make build        # debug -> target/debug/libturbovec.{so,dylib}
make release      # optimized
make clippy       # cargo clippy --all-targets -- -D warnings (CI-enforced)
make fmt          # rustfmt
```

The extension builds against whichever PHP `php-config` describes —
ext-php-rs generates version-specific bindings at build time, so a
binary built against 8.3 will not load into 8.4. Rebuild per minor.

## Loading the dev build

```sh
php -d extension=$PWD/target/debug/libturbovec.so -m | grep turbovec
```

`make install` (requires `cargo install cargo-php`) copies the release
build into your PHP's extension dir and wires up the ini.

## Repo layout

```
src/lib.rs          module registration + compile-time platform guards
src/error.rs        VectorError + the PHP exception hierarchy
src/packed.rs       THE packed-vector decode/validation path (read this first)
src/vectors.rs      Vectors::pack / Vectors::unpack
src/result.rs       SearchResult + SearchResultIterator
src/turboquant.rs   TurboQuantIndex wrapper
src/idmap.rs        IdMapIndex wrapper
stubs/              IDE stubs — regenerate with `make stubs` after API changes
tests/phpt/         the PHPT suite (see Testing)
```

Design invariants worth knowing before changing code: every upstream
panic condition is guarded at the PHP boundary (see the module docs in
`idmap.rs`/`packed.rs`), and the packed decode path deliberately copies
— the rationale is in `packed.rs`'s header comment.
