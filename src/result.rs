//! `Displace\Vector\SearchResult` — the immutable result of a top-k search.
//!
//! Conceptually a list of `(id, score)` rows, ordered best-first. Scores
//! are inner-product similarities from the quantized kernel (higher is
//! better); ids are positional slots for `TurboQuantIndex` and the
//! caller's stable external ids for `IdMapIndex`.
//!
//! Implements `Countable` and `IteratorAggregate`:
//!
//! ```php
//! $result = $index->search($query, k: 5);
//! count($result);                       // up to 5
//! foreach ($result as $row) {
//!     [$id, $score] = [$row['id'], $row['score']];
//! }
//! ```
//!
//! `getIterator()` returns the registered class [`SearchResultIterator`]
//! rather than a generic object: ext-php-rs derives a method's declared
//! return type from the Rust return type, and Zend's interface-variance
//! check requires `getIterator()` to declare something covariant with
//! `IteratorAggregate::getIterator(): Traversable`. A class that
//! implements `\Iterator` satisfies that; a bare `object` would not.

use ext_php_rs::boxed::ZBox;
use ext_php_rs::prelude::*;
use ext_php_rs::types::ZendHashTable;
use ext_php_rs::zend::ce;

use crate::error::VectorError;

/// Immutable `(ids, scores)` pair produced by the index search methods.
///
/// Direct construction is refused — a result built by PHP would lie about
/// which index produced it.
#[php_class]
#[php(name = "Displace\\Vector\\SearchResult")]
#[php(implements(ce = ce::countable, stub = "\\Countable"))]
#[php(implements(ce = ce::aggregate, stub = "\\IteratorAggregate"))]
#[derive(Default, Clone)]
pub struct SearchResult {
    ids: Vec<i64>,
    scores: Vec<f64>,
}

#[php_impl]
impl SearchResult {
    /// Refuse direct construction.
    pub fn __construct() -> PhpResult<Self> {
        Err(VectorError::InvalidConstruction(
            "Displace\\Vector\\SearchResult is produced by the index search() methods; \
             do not instantiate directly"
                .into(),
        )
        .into())
    }

    /// Result ids, best-first. Positional slots for `TurboQuantIndex`;
    /// your stable external ids for `IdMapIndex`.
    pub fn ids(&self) -> Vec<i64> {
        self.ids.clone()
    }

    /// Similarity scores, best-first, parallel to `ids()`. Higher is better.
    pub fn scores(&self) -> Vec<f64> {
        self.scores.clone()
    }

    /// Number of result rows. May be less than the requested `k` when the
    /// index (or the allowlist) holds fewer vectors.
    pub fn count(&self) -> i64 {
        self.ids.len() as i64
    }

    /// Iterator over `['id' => int, 'score' => float]` rows, best-first.
    /// Satisfies `IteratorAggregate` — used implicitly by `foreach`.
    pub fn get_iterator(&self) -> SearchResultIterator {
        SearchResultIterator {
            ids: self.ids.clone(),
            scores: self.scores.clone(),
            position: 0,
        }
    }
}

impl SearchResult {
    /// Rust-side constructor used by the index search methods.
    pub(crate) fn from_rows(ids: Vec<i64>, scores: Vec<f64>) -> Self {
        debug_assert_eq!(ids.len(), scores.len(), "ids and scores must be parallel");
        Self { ids, scores }
    }
}

/// Cursor over a [`SearchResult`]'s rows. Internal support class that
/// exists to satisfy `IteratorAggregate` with a covariant return type —
/// obtain one via `SearchResult::getIterator()` (or just `foreach`), not
/// directly.
#[php_class]
#[php(name = "Displace\\Vector\\SearchResultIterator")]
#[php(implements(ce = ce::iterator, stub = "\\Iterator"))]
#[derive(Default)]
pub struct SearchResultIterator {
    ids: Vec<i64>,
    scores: Vec<f64>,
    position: usize,
}

#[php_impl]
impl SearchResultIterator {
    /// Refuse direct construction.
    pub fn __construct() -> PhpResult<Self> {
        Err(VectorError::InvalidConstruction(
            "Displace\\Vector\\SearchResultIterator is produced by \
             SearchResult::getIterator(); do not instantiate directly"
                .into(),
        )
        .into())
    }

    /// The current `['id' => int, 'score' => float]` row.
    pub fn current(&self) -> PhpResult<ZBox<ZendHashTable>> {
        let (Some(&id), Some(&score)) =
            (self.ids.get(self.position), self.scores.get(self.position))
        else {
            return Err(VectorError::InvalidArgument(
                "current() called on an exhausted iterator".into(),
            )
            .into());
        };
        let mut row = ZendHashTable::new();
        row.insert("id", id)?;
        row.insert("score", score)?;
        Ok(row)
    }

    /// Zero-based row position.
    pub fn key(&self) -> i64 {
        self.position as i64
    }

    /// Advance to the next row.
    pub fn next(&mut self) {
        self.position += 1;
    }

    /// Whether the cursor points at a row.
    pub fn valid(&self) -> bool {
        self.position < self.ids.len()
    }

    /// Reset the cursor to the first row.
    pub fn rewind(&mut self) {
        self.position = 0;
    }
}
