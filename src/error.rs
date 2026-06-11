//! Error types for `ext-turbovec`.
//!
//! On the Rust side, every fallible operation returns a [`VectorError`]. On
//! the PHP side, those errors surface as a small hierarchy of exception
//! classes rooted at `Displace\Vector\VectorException`, which itself extends
//! the built-in `\RuntimeException`.
//!
//! ```text
//! \RuntimeException
//!   └── Displace\Vector\VectorException
//!         ├── Displace\Vector\InvalidArgumentException
//!         │     └── Displace\Vector\DimensionMismatchException
//!         └── Displace\Vector\IndexIOException
//! ```
//!
//! A dimension mismatch *is* an invalid argument, so the mismatch class
//! nests under `InvalidArgumentException` — `catch` either level depending
//! on how precisely you care. `IndexIOException` covers `write()`/`load()`
//! filesystem and format failures.
//!
//! Upstream `turbovec` reports recoverable input problems through the typed
//! [`ConstructError`] / [`AddError`] enums; the `From` impls below are the
//! single place those map onto the PHP-visible hierarchy.

use ext_php_rs::exception::PhpException;
use ext_php_rs::ffi::zend_class_entry;
use ext_php_rs::prelude::*;
use ext_php_rs::zend::ClassEntry;
use thiserror::Error;
use turbovec::{AddError, ConstructError};

/// Internal error type for fallible operations inside the extension.
///
/// Variants map 1:1 to the PHP-visible exception subclasses defined below.
/// New variants should be added alongside a corresponding `#[php_class]` so
/// that PHP callers can catch them precisely.
#[derive(Debug, Error)]
pub enum VectorError {
    /// A caller-supplied argument was malformed: bad bit width, bad k,
    /// non-finite vector values, unknown or negative ids, ...
    #[error("{0}")]
    InvalidArgument(String),

    /// Vector payload length disagrees with the index dimensionality —
    /// `strlen($vectors)` is not a multiple of `4 * dim`, or a batch's
    /// dim doesn't match the dim the index was constructed with.
    #[error("{0}")]
    DimensionMismatch(String),

    /// `write()` / `load()` failed: unreadable path, permission, short
    /// file, bad magic, or an incompatible format version.
    #[error("{0}")]
    IndexIO(String),

    /// Direct `new ClassName()` is not supported. Wraps a hint pointing the
    /// caller at the producing method (`TurboQuantIndex::search()`, ...).
    #[error("{0}")]
    InvalidConstruction(String),
}

impl From<VectorError> for PhpException {
    fn from(err: VectorError) -> Self {
        let message = err.to_string();
        match err {
            VectorError::InvalidArgument(_) => {
                PhpException::from_class::<InvalidArgumentException>(message)
            }
            VectorError::DimensionMismatch(_) => {
                PhpException::from_class::<DimensionMismatchException>(message)
            }
            VectorError::IndexIO(_) => PhpException::from_class::<IndexIOException>(message),
            VectorError::InvalidConstruction(_) => {
                PhpException::from_class::<VectorException>(message)
            }
        }
    }
}

impl From<ConstructError> for VectorError {
    fn from(err: ConstructError) -> Self {
        // All construct errors are bad arguments to `__construct`. The
        // bit-width variant is unreachable in practice (we pre-validate to
        // the stricter {2, 4} set before calling upstream), but mapping it
        // keeps this exhaustive-with-wildcard match honest. ConstructError
        // is #[non_exhaustive] upstream, hence the catch-all arm.
        match err {
            ConstructError::DimNotPositiveMultipleOf8(_) | ConstructError::DimTooLarge { .. } => {
                VectorError::InvalidArgument(err.to_string())
            }
            _ => VectorError::InvalidArgument(err.to_string()),
        }
    }
}

impl From<AddError> for VectorError {
    fn from(err: AddError) -> Self {
        match err {
            // Shape disagreements between the payload and the index dim.
            AddError::DimMismatch { .. }
            | AddError::DimNotMultipleOf8(_)
            | AddError::DimTooLarge { .. }
            | AddError::VectorBufferNotMultipleOfDim { .. } => {
                VectorError::DimensionMismatch(err.to_string())
            }
            // Everything else (id count mismatch, duplicate id, non-finite
            // values) is a recoverable bad argument. AddError is
            // #[non_exhaustive] upstream, hence the catch-all arm.
            _ => VectorError::InvalidArgument(err.to_string()),
        }
    }
}

impl From<std::io::Error> for VectorError {
    fn from(err: std::io::Error) -> Self {
        VectorError::IndexIO(err.to_string())
    }
}

/// Base exception for all `ext-turbovec` failures. Extends `\RuntimeException`
/// so existing `catch (\RuntimeException $e)` clauses continue to work.
#[php_class]
#[php(name = "Displace\\Vector\\VectorException")]
#[php(extends(ce = runtime_exception_ce, stub = "\\RuntimeException"))]
#[derive(Default)]
pub struct VectorException;

/// Thrown when a caller-supplied argument is malformed: a bit width other
/// than 2 or 4, `k < 1`, NaN/Inf vector values, a negative or unknown id,
/// an empty allowlist, ...
#[php_class]
#[php(name = "Displace\\Vector\\InvalidArgumentException")]
#[php(extends(VectorException))]
#[derive(Default)]
pub struct InvalidArgumentException;

/// Thrown when a packed-vector payload disagrees with the index
/// dimensionality: `strlen($vectors)` not a multiple of `4 * dim`, or a
/// query that isn't exactly one `dim`-sized vector.
#[php_class]
#[php(name = "Displace\\Vector\\DimensionMismatchException")]
#[php(extends(InvalidArgumentException))]
#[derive(Default)]
pub struct DimensionMismatchException;

/// Thrown when `write()` or `load()` fails — unreadable path, permissions,
/// truncated file, bad magic bytes, or an incompatible index-format version.
#[php_class]
#[php(name = "Displace\\Vector\\IndexIOException")]
#[php(extends(VectorException))]
#[derive(Default)]
pub struct IndexIOException;

// `\RuntimeException` is defined by SPL, which exposes its `zend_class_entry *`
// as a `PHPAPI` global — same convention as the engine's `zend_ce_*` globals
// that `ext_php_rs::zend::ce::*` wraps. SPL is a built-in module that is
// always loaded before user extensions, so by the time our MINIT runs (and
// `runtime_exception_ce()` is called for the `extends` linkage) this pointer
// is non-null.
//
// The alternative — `ClassEntry::try_find("RuntimeException")` — goes through
// `EG(class_table)`, which is not yet initialized during MINIT and would
// return `None`. Linking against the global directly avoids that ordering
// hazard entirely. (Pattern shared with ext-infer.)
#[allow(non_upper_case_globals)]
unsafe extern "C" {
    static spl_ce_RuntimeException: *mut zend_class_entry;
}

/// Class-entry accessor for PHP's SPL `\RuntimeException`, used by the
/// `extends(ce = ...)` linkage on [`VectorException`].
fn runtime_exception_ce() -> &'static ClassEntry {
    // SAFETY: `spl_ce_RuntimeException` is a stable PHPAPI symbol exported by
    // any SAPI we support. It is written once during SPL's MINIT (well before
    // ours) and never reassigned, so reading it as a shared `&'static` is
    // sound. A null pointer here would mean the host PHP is not SPL-enabled,
    // which is unsupported.
    unsafe { spl_ce_RuntimeException.as_ref() }
        .expect("SPL \\RuntimeException is required (host PHP missing the SPL extension?)")
}
