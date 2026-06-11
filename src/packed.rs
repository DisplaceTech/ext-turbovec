//! The packed-vector boundary: PHP binary strings → `Vec<f32>`.
//!
//! Vectors cross the FFI boundary as packed little-endian float32 binary
//! strings — the output of PHP's `pack('g*', ...$floats)`. This module is
//! the single decode path every index method goes through, so validation
//! rules live here exactly once:
//!
//! 1. `strlen` must be a whole multiple of `4 * dim` (exactly `4 * dim`
//!    for a single query vector) → `DimensionMismatchException` otherwise.
//! 2. Every coordinate must be finite with |value| < 1e16 → checked via
//!    upstream's public [`turbovec::first_invalid_coord`], because
//!    upstream's `search()` *panics* on bad values rather than returning a
//!    typed error. We throw `InvalidArgumentException` instead.
//!
//! ## Why decode copies instead of reinterpreting in place
//!
//! The bytes of a zend_string are not guaranteed `f32`-aligned at the type
//! level (in practice the payload is 8-byte aligned, but that's an
//! allocator detail, not a contract). Rather than an `unsafe` pointer cast
//! with an alignment escape hatch, we always copy through
//! `f32::from_le_bytes`, which:
//!
//! - is alignment-proof and needs **zero unsafe**,
//! - makes the little-endian contract explicit in code (on our supported
//!   targets — all little-endian, enforced by the `compile_error!` in
//!   lib.rs — it compiles to a plain 4-byte load),
//! - costs O(input) against the O(input × dim) encode/search it feeds.
//!
//! A zero-copy fast path via `Zval::binary_slice` is a measured-later
//! optimization (see PLAN.md), not a correctness need.

use crate::error::VectorError;

/// Element width of the packed contract: one IEEE-754 single per coordinate.
pub const F32_BYTES: usize = 4;

/// Decode a packed batch of vectors. `bytes.len()` must be a (possibly
/// zero) multiple of `4 * dim`; an empty payload decodes to an empty batch,
/// which the index treats as a no-op add.
pub fn decode_vectors(bytes: &[u8], dim: usize, arg: &str) -> Result<Vec<f32>, VectorError> {
    debug_assert!(dim > 0, "callers must resolve dim before decoding");
    let stride = F32_BYTES * dim;
    if bytes.len() % stride != 0 {
        return Err(VectorError::DimensionMismatch(format!(
            "{arg}: byte length {} is not a multiple of {} (4 bytes x dim {}); \
             expected whole packed float32 vectors — the output of pack('g*', ...)",
            bytes.len(),
            stride,
            dim,
        )));
    }
    let floats = decode_f32(bytes);
    reject_non_finite(&floats, dim, arg)?;
    Ok(floats)
}

/// Decode a single packed query vector. Stricter than [`decode_vectors`]:
/// the payload must be *exactly* one `dim`-sized vector.
pub fn decode_query(bytes: &[u8], dim: usize) -> Result<Vec<f32>, VectorError> {
    debug_assert!(dim > 0, "callers must resolve dim before decoding");
    let stride = F32_BYTES * dim;
    if bytes.len() != stride {
        return Err(VectorError::DimensionMismatch(format!(
            "query: expected exactly one packed float32 vector of dim {dim} ({stride} bytes), \
             got {} bytes",
            bytes.len(),
        )));
    }
    let floats = decode_f32(bytes);
    reject_non_finite(&floats, dim, "query")?;
    Ok(floats)
}

/// Raw little-endian f32 decode with no value validation. Used by
/// `Vectors::unpack`, which is a transparency helper and must round-trip
/// whatever bytes it is given (including NaN/Inf) rather than judge them.
pub fn decode_f32(bytes: &[u8]) -> Vec<f32> {
    bytes
        .chunks_exact(F32_BYTES)
        .map(|chunk| f32::from_le_bytes(chunk.try_into().expect("chunks_exact yields 4 bytes")))
        .collect()
}

/// Length pre-check shared with `Vectors::unpack` (which skips the
/// finiteness check that `decode_vectors` adds on top).
pub fn check_multiple(bytes: &[u8], dim: usize, arg: &str) -> Result<(), VectorError> {
    let stride = F32_BYTES * dim;
    if bytes.len() % stride != 0 {
        return Err(VectorError::DimensionMismatch(format!(
            "{arg}: byte length {} is not a multiple of {} (4 bytes x dim {})",
            bytes.len(),
            stride,
            dim,
        )));
    }
    Ok(())
}

/// Map upstream's "this value would corrupt the index" detection to a
/// typed PHP exception. Upstream documents the failure modes: NaN/Inf
/// poison the per-vector scale; huge magnitudes overflow the f32 norm.
fn reject_non_finite(floats: &[f32], dim: usize, arg: &str) -> Result<(), VectorError> {
    if let Some((vector_index, coord_index, value)) = turbovec::first_invalid_coord(floats, dim) {
        return Err(VectorError::InvalidArgument(format!(
            "{arg}: invalid value {value} at vector {vector_index}, coordinate {coord_index} \
             (values must be finite and |value| < 1e16)",
        )));
    }
    Ok(())
}
