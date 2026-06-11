//! `Displace\Vector\IdMapIndex` — stable external ids over the quantized
//! index, with O(1) removal and search-time allowlist filtering.
//!
//! ```php
//! $index = new \Displace\Vector\IdMapIndex(dim: 1024, bitWidth: 4);
//! $index->addWithIds($packedVectors, [1001, 1002, 1003]);
//! $index->remove(1002);                              // O(1) by id
//! $result = $index->search($q, k: 10, allowlist: [1001, 1003]);
//! ```
//!
//! Ids are unsigned 64-bit upstream; PHP ints are signed 64-bit, so this
//! binding accepts `0..=PHP_INT_MAX` — every id that goes in fits back
//! into a PHP int on the way out.
//!
//! Upstream's `search_with_allowlist` *panics* on an empty allowlist or an
//! id that is not in the index, so both are validated here first (each
//! `contains()` check is O(1)) and surface as `InvalidArgumentException`.
//
// camelCase parameter idents (`bitWidth`) are intentional — see the module
// note in turboquant.rs. The proc-macro expansion moves the idents past any
// per-method #[allow], so the lint is silenced module-wide.
#![allow(non_snake_case)]

use ext_php_rs::binary::Binary;
use ext_php_rs::prelude::*;

use crate::error::VectorError;
use crate::packed;
use crate::result::SearchResult;
use crate::turboquant::{io_error, validate_construct_args, validate_k};

/// PHP-visible handle to an upstream [`turbovec::IdMapIndex`].
#[php_class]
#[php(name = "Displace\\Vector\\IdMapIndex")]
pub struct IdMapIndex {
    inner: turbovec::IdMapIndex,
}

// Same rationale as `TurboQuantIndex`'s Default: a real lazy index keeps
// constructor-bypassing instantiation inert. See turboquant.rs.
impl Default for IdMapIndex {
    fn default() -> Self {
        Self {
            inner: turbovec::IdMapIndex::new_lazy(4).expect("bit width 4 is always valid"),
        }
    }
}

#[php_impl]
impl IdMapIndex {
    /// Create an empty id-mapped index for `dim`-dimensional vectors
    /// quantized to `bitWidth` bits per coordinate. Same `dim`/`bitWidth`
    /// rules as `TurboQuantIndex`.
    #[php(defaults(bitWidth = 4))]
    pub fn __construct(dim: i64, bitWidth: i64) -> PhpResult<Self> {
        let (dim, bit_width) = validate_construct_args(dim, bitWidth)?;
        let inner = turbovec::IdMapIndex::new(dim, bit_width).map_err(VectorError::from)?;
        Ok(Self { inner })
    }

    /// Add a batch of vectors with caller-chosen stable ids.
    ///
    /// `$ids` must hold exactly one non-negative int per vector in
    /// `$vectors` (`strlen($vectors) / (4 * dim)` of them). Ids already in
    /// the index — or duplicated within the call — are rejected before
    /// anything is added; a failed call never partially applies.
    pub fn add_with_ids(&mut self, vectors: Binary<u8>, ids: Vec<i64>) -> PhpResult<()> {
        let dim = self.dim()?;
        let floats = packed::decode_vectors(&vectors, dim, "vectors")?;
        let ids = validate_ids(&ids, "ids")?;
        // Upstream validates count-vs-vectors, in-index duplicates, and
        // in-call duplicates up front, so partial application is impossible.
        self.inner
            .add_with_ids_2d(&floats, dim, &ids)
            .map_err(VectorError::from)?;
        Ok(())
    }

    /// Number of vectors currently in the index.
    pub fn count(&self) -> i64 {
        self.inner.len() as i64
    }

    /// Top-`k` search for a single packed query vector, optionally
    /// restricted to an `$allowlist` of ids.
    ///
    /// With an allowlist, every returned id is from the allowlist and the
    /// row count is `min(k, count($allowlist))` — a smaller allowlist
    /// yields exactly that many rows, never padded fallbacks. The
    /// allowlist must be non-empty (pass `null` to search unfiltered) and
    /// every id in it must currently be in the index. Duplicates within
    /// the allowlist are accepted and deduplicated.
    #[php(defaults(k = 10))]
    pub fn search(
        &self,
        query: Binary<u8>,
        k: i64,
        allowlist: Option<Vec<i64>>,
    ) -> PhpResult<SearchResult> {
        let k = validate_k(k)?;
        let allowlist = match allowlist {
            Some(ids) => Some(self.validate_allowlist(&ids)?),
            None => None,
        };
        let dim = self.dim()?;
        let floats = packed::decode_query(&query, dim)?;

        let (scores, ids) = self
            .inner
            .search_with_allowlist(&floats, k, allowlist.as_deref());

        // Every id in the index passed through validate_ids() on the way
        // in, so it fits i64; try_from is a crash-loud guard, not a path.
        let ids = ids
            .into_iter()
            .map(|id| i64::try_from(id).expect("stored ids were validated to fit PHP int"))
            .collect();
        let scores = scores.into_iter().map(f64::from).collect();
        Ok(SearchResult::from_rows(ids, scores))
    }

    /// Remove the vector with the given id, in O(1). Throws
    /// `InvalidArgumentException` when the id is not in the index — a
    /// remove that removes nothing is almost always a caller bug.
    pub fn remove(&mut self, id: i64) -> PhpResult<()> {
        let id = validate_id(id, "id")?;
        if !self.inner.remove(id) {
            return Err(VectorError::InvalidArgument(format!(
                "id {id} is not present in the index",
            ))
            .into());
        }
        Ok(())
    }

    /// Persist the index to `path` (upstream's versioned `.tvim` format —
    /// the quantized index plus the id tables). Round-trips exactly
    /// through [`IdMapIndex::load`].
    pub fn write(&self, path: String) -> PhpResult<()> {
        self.inner.write(&path).map_err(|e| io_error(&path, e))?;
        Ok(())
    }

    /// Load an index previously persisted with [`IdMapIndex::write`].
    pub fn load(path: String) -> PhpResult<Self> {
        let inner = turbovec::IdMapIndex::load(&path).map_err(|e| io_error(&path, e))?;
        Ok(Self { inner })
    }
}

impl IdMapIndex {
    /// See `TurboQuantIndex::dim` — only reachable via constructor bypass.
    fn dim(&self) -> Result<usize, VectorError> {
        self.inner.dim_opt().ok_or_else(|| {
            VectorError::InvalidArgument(
                "index has no dimensionality — construct it with new IdMapIndex(dim) \
                 instead of bypassing the constructor"
                    .into(),
            )
        })
    }

    /// Guard the upstream panic conditions: non-empty, and every id known.
    fn validate_allowlist(&self, ids: &[i64]) -> Result<Vec<u64>, VectorError> {
        if ids.is_empty() {
            return Err(VectorError::InvalidArgument(
                "allowlist must not be empty — pass null to search unfiltered".into(),
            ));
        }
        let ids = validate_ids(ids, "allowlist")?;
        for &id in &ids {
            if !self.inner.contains(id) {
                return Err(VectorError::InvalidArgument(format!(
                    "allowlist id {id} is not present in the index",
                )));
            }
        }
        Ok(ids)
    }
}

fn validate_id(id: i64, arg: &str) -> Result<u64, VectorError> {
    u64::try_from(id)
        .map_err(|_| VectorError::InvalidArgument(format!("{arg}: must be non-negative, got {id}")))
}

fn validate_ids(ids: &[i64], arg: &str) -> Result<Vec<u64>, VectorError> {
    ids.iter()
        .enumerate()
        .map(|(i, &id)| {
            u64::try_from(id).map_err(|_| {
                VectorError::InvalidArgument(format!(
                    "{arg}: ids must be non-negative, got {id} at index {i}",
                ))
            })
        })
        .collect()
}
