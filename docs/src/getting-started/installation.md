# Installation

## Via PIE (recommended)

[PIE](https://github.com/php/pie) is PHP's installer for extensions:

```sh
pie install displace/ext-turbovec
```

PIE picks the pre-built binary for your PHP minor (8.3/8.4/8.5),
platform (macOS arm64, Linux x86_64, Linux arm64), and thread-safety
mode, drops it into your extension directory, and enables it.

No runtime libraries are needed on any platform. (ext-turbovec 0.1.x
linked OpenBLAS on Linux; since 0.2.0 the upstream engine is pure Rust
and the `libopenblas0` package can be removed.)

## From source

Requirements: Rust 1.89+ (`rustup` will pick up the repo's toolchain
pin), PHP 8.3+ with development headers (`php-dev` /
`php-config` on PATH), and libclang (`libclang-dev`).

```sh
git clone https://github.com/DisplaceTech/ext-turbovec
cd ext-turbovec
make release             # target/release/libturbovec.{so,dylib}
```

Load it ad hoc:

```sh
php -d extension=$PWD/target/release/libturbovec.so your-script.php
```

…or permanently, by adding `extension=/path/to/libturbovec.so` to your
`php.ini`. `make install` (via [`cargo-php`](https://crates.io/crates/cargo-php))
automates the copy-and-enable.

## Supported targets

PHP 8.3 / 8.4 / 8.5 on macOS arm64, Linux x86_64 (Haswell 2013+ for the
SIMD fast path; older CPUs use a scalar fallback), and Linux arm64.
Windows is not supported. See the
[compatibility matrix](../reference/compatibility.md).
