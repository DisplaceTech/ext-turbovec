//! `ext-turbovec` — PHP 8.3+ native, in-process vector indexing and ANN
//! search, as bindings over the [`turbovec`](https://crates.io/crates/turbovec)
//! crate (Google Research's TurboQuant quantization, arXiv:2504.19874).
//!
//! Public surface:
//!
//! - `Displace\Vector\TurboQuantIndex`     — positional-id quantized index
//! - `Displace\Vector\IdMapIndex`          — stable external uint64 ids, O(1) remove
//! - `Displace\Vector\SearchResult`        — immutable top-k result (Countable, IteratorAggregate)
//! - `Displace\Vector\Vectors`             — static pack/unpack helpers
//! - `Displace\Vector\VectorException`     — base exception (extends `\RuntimeException`)
//! - `Displace\Vector\InvalidArgumentException`
//! - `Displace\Vector\DimensionMismatchException`
//! - `Displace\Vector\IndexIOException`
//!
//! Vectors cross the FFI boundary as packed little-endian float32 binary
//! strings — the output of PHP's `pack('g*', ...$floats)`. See `packed.rs`
//! for the single decode path and its validation rules.

#![deny(clippy::all)]

// The packed-vector contract is little-endian float32 (`pack('g*')`). On a
// big-endian host every coordinate would be byte-swapped garbage, so refuse
// to compile rather than corrupt data at runtime. (Upstream turbovec
// likewise refuses non-64-bit targets with its own compile_error!.)
#[cfg(target_endian = "big")]
compile_error!(
    "ext-turbovec requires a little-endian target: the packed-vector ABI is \
     little-endian float32 (PHP pack('g*'))"
);

mod error;
mod idmap;
mod packed;
mod result;
mod turboquant;
mod vectors;

use ext_php_rs::prelude::*;

// Re-export so `cargo php stubs` and module registration can see them by
// their crate-root paths.
pub use error::{
    DimensionMismatchException, IndexIOException, InvalidArgumentException, VectorException,
};
pub use idmap::IdMapIndex;
pub use result::{SearchResult, SearchResultIterator};
pub use turboquant::TurboQuantIndex;
pub use vectors::Vectors;

/// PHP module entry point.
///
/// The default module name is `CARGO_PKG_NAME` (`ext-turbovec`); we override
/// it to plain `turbovec` so userland calls `extension_loaded('turbovec')` —
/// matching PHP's convention of dropping the `ext-` prefix.
///
/// The order of `class::<T>()` calls is significant twice over: child
/// exceptions reference their parent's `ClassEntry`, so parents register
/// first; and `SearchResult::getIterator()` declares a
/// `SearchResultIterator` return type, so the iterator class registers
/// before the class whose arginfo names it.
#[php_module]
pub fn get_module(module: ModuleBuilder) -> ModuleBuilder {
    module
        .name("turbovec")
        .class::<VectorException>()
        .class::<InvalidArgumentException>()
        .class::<DimensionMismatchException>()
        .class::<IndexIOException>()
        .class::<SearchResultIterator>()
        .class::<SearchResult>()
        .class::<Vectors>()
        .class::<TurboQuantIndex>()
        .class::<IdMapIndex>()
}
