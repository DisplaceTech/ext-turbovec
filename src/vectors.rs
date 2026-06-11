//! `Displace\Vector\Vectors` — static pack/unpack helpers.
//!
//! The index classes only accept packed little-endian float32 strings (one
//! code path, no silent zval-array inflation on the hot path). These two
//! helpers are the on-ramp for array-minded callers:
//!
//! ```php
//! $packed = Vectors::pack([0.1, 0.2, 0.3, 0.4]);   // === pack('g*', 0.1, 0.2, 0.3, 0.4)
//! $floats = Vectors::unpack($packed, dim: 4);       // flat list<float>, exact round-trip
//! ```
//!
//! `unpack` returns a *flat* float list (use `array_chunk($floats, $dim)`
//! for per-vector rows); `$dim` exists to validate that the payload holds
//! whole vectors. Unlike the index methods, `unpack` does NOT reject
//! NaN/Inf — it is a transparency tool and must show you whatever bytes
//! you actually have.

use ext_php_rs::binary::Binary;
use ext_php_rs::prelude::*;
use ext_php_rs::types::ZendHashTable;

use crate::error::VectorError;
use crate::packed;

/// Static helper class. Not instantiable — both methods are static.
#[php_class]
#[php(name = "Displace\\Vector\\Vectors")]
#[derive(Default)]
pub struct Vectors;

#[php_impl]
impl Vectors {
    /// Refuse direct construction — `Vectors` is a static utility class.
    pub fn __construct() -> PhpResult<Self> {
        Err(VectorError::InvalidConstruction(
            "Displace\\Vector\\Vectors is a static helper; call Vectors::pack() or \
             Vectors::unpack() directly"
                .into(),
        )
        .into())
    }

    /// Pack a flat list of floats into the binary format the index classes
    /// accept — byte-identical to `pack('g*', ...$floats)`.
    ///
    /// Accepts ints and floats; anything else throws. PHP floats are
    /// doubles, so each value narrows to f32 here — the same narrowing
    /// `pack('g*')` performs.
    pub fn pack(floats: &ZendHashTable) -> PhpResult<Binary<u8>> {
        let mut bytes: Vec<u8> = Vec::with_capacity(floats.len() * packed::F32_BYTES);
        for (i, zv) in floats.values().enumerate() {
            let value = zv
                .double()
                .or_else(|| zv.long().map(|n| n as f64))
                .ok_or_else(|| {
                    VectorError::InvalidArgument(format!(
                        "floats: element {i} is not a float or int",
                    ))
                })?;
            bytes.extend_from_slice(&(value as f32).to_le_bytes());
        }
        Ok(Binary::new(bytes))
    }

    /// Unpack a binary string produced by [`Vectors::pack`] (or PHP's
    /// `pack('g*', ...)`) back into a flat list of floats.
    ///
    /// `$dim` validates that the payload holds whole `dim`-sized vectors —
    /// `strlen($packed)` must be a multiple of `4 * $dim`. The returned
    /// list round-trips `pack` exactly: `unpack(pack($f), $dim) === $f`
    /// for f32-representable inputs.
    pub fn unpack(packed: Binary<u8>, dim: i64) -> PhpResult<Vec<f64>> {
        if dim < 1 {
            return Err(
                VectorError::InvalidArgument(format!("dim must be >= 1, got {dim}")).into(),
            );
        }
        let dim = usize::try_from(dim).expect("dim >= 1 fits usize");
        packed::check_multiple(&packed, dim, "packed")?;
        Ok(packed::decode_f32(&packed)
            .into_iter()
            .map(f64::from)
            .collect())
    }
}
