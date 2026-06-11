//! `Displace\Vector\TurboQuantIndex` — the positional-id quantized index.
//!
//! ```php
//! $index = new \Displace\Vector\TurboQuantIndex(dim: 1024, bitWidth: 4);
//! $index->add($packedVectors);                  // pack('g*', ...) payload
//! $result = $index->search($packedQuery, k: 10);
//! $index->write('corpus.tv');
//! $loaded = \Displace\Vector\TurboQuantIndex::load('corpus.tv');
//! ```
//!
//! Ids are *positional*: the Nth vector added is id N. There is no remove —
//! use `IdMapIndex` when you need stable external ids or deletion.
//!
//! ## Ownership and `&mut self`
//!
//! `add()` mutates through `&mut self`, which ext-php-rs derives from the
//! owning `ZendClassObject`. That is sound here: a PHP object is only ever
//! touched by its request's thread (trivially on NTS; ZTS objects are
//! request-local), and no `&mut` method calls back into PHP userland, so
//! re-entrant aliasing cannot occur. `search()` takes `&self`, matching
//! upstream's concurrent-search contract (lazy caches behind `OnceLock`).
//
// camelCase parameter idents (`bitWidth`) are intentional: PHP named
// arguments echo the Rust ident verbatim, and the public API is PSR-12
// camelCase. The proc-macro expansion shifts those idents into generated
// code where per-method `#[allow]` doesn't reach, so the lint is silenced
// at the module level instead. (Same pattern as ext-infer's model.rs.)
#![allow(non_snake_case)]

use ext_php_rs::binary::Binary;
use ext_php_rs::prelude::*;

use crate::error::VectorError;
use crate::packed;
use crate::result::SearchResult;

/// Validate the PHP-facing `(dim, bitWidth)` constructor arguments shared
/// by both index classes, returning them as upstream-ready `usize`s.
///
/// We restrict bitWidth to {2, 4} even though upstream also implements
/// 3-bit: 2 and 4 are the configurations the SIMD kernels are optimized
/// for and the only ones this extension commits to supporting. Upstream's
/// own dim rules (positive multiple of 8, <= 65536) surface through the
/// `ConstructError` mapping when `new()` runs.
pub(crate) fn validate_construct_args(
    dim: i64,
    bit_width: i64,
) -> Result<(usize, usize), VectorError> {
    if bit_width != 2 && bit_width != 4 {
        return Err(VectorError::InvalidArgument(format!(
            "bitWidth must be 2 or 4, got {bit_width}",
        )));
    }
    if dim < 1 {
        return Err(VectorError::InvalidArgument(format!(
            "dim must be a positive multiple of 8, got {dim}",
        )));
    }
    let dim = usize::try_from(dim).expect("dim >= 1 fits usize");
    let bit_width = usize::try_from(bit_width).expect("bitWidth in {2,4} fits usize");
    Ok((dim, bit_width))
}

/// Attach the offending path to an I/O error — `std::io::Error` messages
/// ("No such file or directory") don't name the file on their own.
pub(crate) fn io_error(path: &str, err: std::io::Error) -> VectorError {
    VectorError::IndexIO(format!("{path}: {err}"))
}

/// PHP-visible handle to an upstream [`turbovec::TurboQuantIndex`].
#[php_class]
#[php(name = "Displace\\Vector\\TurboQuantIndex")]
pub struct TurboQuantIndex {
    inner: turbovec::TurboQuantIndex,
}

// ext-php-rs requires `Default` to materialize the object shell before
// `__construct` runs. Use upstream's lazy constructor — a real, empty,
// dim-uncommitted index — so an object that somehow bypasses `__construct`
// (e.g. `ReflectionClass::newInstanceWithoutConstructor`) is inert rather
// than undefined: every method guards on `dim_opt()` before touching it.
impl Default for TurboQuantIndex {
    fn default() -> Self {
        Self {
            inner: turbovec::TurboQuantIndex::new_lazy(4).expect("bit width 4 is always valid"),
        }
    }
}

#[php_impl]
impl TurboQuantIndex {
    /// Create an empty index for `dim`-dimensional vectors quantized to
    /// `bitWidth` bits per coordinate.
    ///
    /// `dim` must be a positive multiple of 8 (every common embedding
    /// dimensionality qualifies) and at most 65536. `bitWidth` must be
    /// 2 or 4.
    #[php(defaults(bitWidth = 4))]
    pub fn __construct(dim: i64, bitWidth: i64) -> PhpResult<Self> {
        let (dim, bit_width) = validate_construct_args(dim, bitWidth)?;
        let inner = turbovec::TurboQuantIndex::new(dim, bit_width).map_err(VectorError::from)?;
        Ok(Self { inner })
    }

    /// Add a batch of vectors. `$vectors` is one or more concatenated
    /// packed float32 vectors — `strlen($vectors)` must be a multiple of
    /// `4 * dim`. The Nth vector ever added gets positional id N. An empty
    /// string is a no-op.
    pub fn add(&mut self, vectors: Binary<u8>) -> PhpResult<()> {
        let dim = self.dim()?;
        let floats = packed::decode_vectors(&vectors, dim, "vectors")?;
        // `add_2d` (not `add`): the `_2d` form returns typed errors where
        // the flat form panics on bad input. Our pre-validation should
        // leave nothing for it to reject, but a typed error beats a panic
        // if that ever drifts.
        self.inner.add_2d(&floats, dim).map_err(VectorError::from)?;
        Ok(())
    }

    /// Number of vectors currently in the index.
    pub fn count(&self) -> i64 {
        self.inner.len() as i64
    }

    /// Top-`k` search for a single packed query vector. Returns up to `k`
    /// rows (fewer when the index holds fewer vectors), best-first.
    #[php(defaults(k = 10))]
    pub fn search(&self, query: Binary<u8>, k: i64) -> PhpResult<SearchResult> {
        let k = validate_k(k)?;
        let dim = self.dim()?;
        let floats = packed::decode_query(&query, dim)?;
        Ok(single_query_result(self.inner.search(&floats, k)))
    }

    /// Persist the index to `path` (upstream's versioned `.tv` format).
    /// Round-trips exactly through [`TurboQuantIndex::load`].
    pub fn write(&self, path: String) -> PhpResult<()> {
        self.inner.write(&path).map_err(|e| io_error(&path, e))?;
        Ok(())
    }

    /// Load an index previously persisted with [`TurboQuantIndex::write`].
    pub fn load(path: String) -> PhpResult<Self> {
        let inner = turbovec::TurboQuantIndex::load(&path).map_err(|e| io_error(&path, e))?;
        Ok(Self { inner })
    }
}

impl TurboQuantIndex {
    /// The committed dim. `None` is only reachable by constructing the
    /// object without `__construct` (reflection); turn that into a clear
    /// error instead of letting upstream's lazy-index paths panic.
    fn dim(&self) -> Result<usize, VectorError> {
        self.inner.dim_opt().ok_or_else(|| {
            VectorError::InvalidArgument(
                "index has no dimensionality — construct it with new TurboQuantIndex(dim) \
                 instead of bypassing the constructor"
                    .into(),
            )
        })
    }
}

/// Shared `k` validation for the search methods.
pub(crate) fn validate_k(k: i64) -> Result<usize, VectorError> {
    if k < 1 {
        return Err(VectorError::InvalidArgument(format!(
            "k must be >= 1, got {k}",
        )));
    }
    Ok(usize::try_from(k).expect("k >= 1 fits usize"))
}

/// Flatten upstream's multi-query `SearchResults` for our single-query
/// surface. `results.k` is the *effective* row count (`min(k, len)`), so
/// the first `k` entries are query 0's rows; on an empty index it is 0.
fn single_query_result(results: turbovec::SearchResults) -> SearchResult {
    let rows = results.k;
    let ids = results.indices[..rows].to_vec();
    let scores = results.scores[..rows]
        .iter()
        .map(|&s| f64::from(s))
        .collect();
    SearchResult::from_rows(ids, scores)
}
