--TEST--
Vectors::pack()/unpack() round-trip and match the pack('g*') idiom
--SKIPIF--
<?php
if (!extension_loaded('turbovec')) {
    echo 'skip ext-turbovec not loaded';
}
?>
--FILE--
<?php
use Displace\Vector\DimensionMismatchException;
use Displace\Vector\InvalidArgumentException;
use Displace\Vector\VectorException;
use Displace\Vector\Vectors;

// pack() is byte-identical to the pack('g*') idiom. Every value here is
// exactly representable in f32, so the round-trip below can demand ===.
$floats = [0.5, -1.25, 3.0, 0.0625, 100.0, -0.0, 0.0009765625, 42.0];
echo "pack_matches_idiom: ",
    Vectors::pack($floats) === pack('g*', ...$floats) ? "yes" : "no", "\n";

// Exact round-trip for f32-representable values.
echo "roundtrip_exact: ",
    Vectors::unpack(Vectors::pack($floats), 8) === $floats ? "yes" : "no", "\n";

// Arbitrary doubles narrow to f32 once; after that the bytes are stable.
$lossy  = [0.1, 0.2, 0.3, 0.4];
$packed = Vectors::pack($lossy);
echo "renarrowing_stable: ",
    Vectors::pack(Vectors::unpack($packed, 4)) === $packed ? "yes" : "no", "\n";

// Ints are accepted and packed as floats.
echo "ints_accepted: ",
    Vectors::pack([1, 2, 3, 4]) === pack('g*', 1.0, 2.0, 3.0, 4.0) ? "yes" : "no", "\n";

// unpack validates that the payload holds whole dim-sized vectors.
try {
    Vectors::unpack(pack('g*', 1.0, 2.0, 3.0), 4);
    echo "unpack_partial: FAIL\n";
} catch (DimensionMismatchException $e) {
    echo "unpack_partial_throws: yes\n";
}

// Empty payload is zero whole vectors — valid, empty result.
echo "unpack_empty: ", Vectors::unpack('', 4) === [] ? "yes" : "no", "\n";

// dim must be positive.
try {
    Vectors::unpack('', 0);
    echo "unpack_dim_zero: FAIL\n";
} catch (InvalidArgumentException $e) {
    echo "unpack_dim_zero_throws: yes\n";
}

// Non-numeric elements are rejected.
try {
    Vectors::pack([1.0, 'two', 3.0]);
    echo "pack_string: FAIL\n";
} catch (InvalidArgumentException $e) {
    echo "pack_string_throws: yes\n";
}

// Static helper class — not instantiable.
try {
    new Vectors();
    echo "ctor: FAIL\n";
} catch (VectorException $e) {
    echo "ctor_throws: yes\n";
}
?>
--EXPECT--
pack_matches_idiom: yes
roundtrip_exact: yes
renarrowing_stable: yes
ints_accepted: yes
unpack_partial_throws: yes
unpack_empty: yes
unpack_dim_zero_throws: yes
pack_string_throws: yes
ctor_throws: yes
