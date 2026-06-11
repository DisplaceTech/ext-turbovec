--TEST--
Index constructors validate dim and bitWidth; exception hierarchy is intact
--SKIPIF--
<?php
if (!extension_loaded('turbovec')) {
    echo 'skip ext-turbovec not loaded';
}
?>
--FILE--
<?php
use Displace\Vector\IdMapIndex;
use Displace\Vector\InvalidArgumentException;
use Displace\Vector\TurboQuantIndex;

// Valid constructions.
$a = new TurboQuantIndex(64);
echo "default_bitwidth_ok: ", $a->count() === 0 ? "yes" : "no", "\n";
$b = new TurboQuantIndex(64, 2);
echo "bitwidth_2_ok: yes\n";
$c = new IdMapIndex(dim: 1024, bitWidth: 4);
echo "idmap_named_args_ok: yes\n";

// bitWidth must be 2 or 4 — 3 is supported upstream but deliberately not
// exposed by this extension.
foreach ([3, 0, 5, -2] as $bw) {
    try {
        new TurboQuantIndex(64, $bw);
        echo "bitwidth_{$bw}: FAIL\n";
    } catch (InvalidArgumentException $e) {
        echo "bitwidth_{$bw}_throws: yes\n";
    }
}

// dim must be a positive multiple of 8.
foreach ([0, -8, 63, 65] as $dim) {
    try {
        new TurboQuantIndex($dim);
        echo "dim_{$dim}: FAIL\n";
    } catch (InvalidArgumentException $e) {
        echo "dim_{$dim}_throws: yes\n";
    }
}

// IdMapIndex shares the same validation.
try {
    new IdMapIndex(64, 3);
    echo "idmap_bitwidth_3: FAIL\n";
} catch (InvalidArgumentException $e) {
    echo "idmap_bitwidth_3_throws: yes\n";
}

// Exception hierarchy: DimensionMismatch < InvalidArgument < Vector < \RuntimeException.
$dimEx = new ReflectionClass(\Displace\Vector\DimensionMismatchException::class);
echo "dim_extends_invalid: ",
    $dimEx->isSubclassOf(InvalidArgumentException::class) ? "yes" : "no", "\n";
echo "invalid_extends_vector: ",
    is_subclass_of(InvalidArgumentException::class, \Displace\Vector\VectorException::class) ? "yes" : "no", "\n";
echo "io_extends_vector: ",
    is_subclass_of(\Displace\Vector\IndexIOException::class, \Displace\Vector\VectorException::class) ? "yes" : "no", "\n";
echo "vector_extends_runtime: ",
    is_subclass_of(\Displace\Vector\VectorException::class, \RuntimeException::class) ? "yes" : "no", "\n";
?>
--EXPECT--
default_bitwidth_ok: yes
bitwidth_2_ok: yes
idmap_named_args_ok: yes
bitwidth_3_throws: yes
bitwidth_0_throws: yes
bitwidth_5_throws: yes
bitwidth_-2_throws: yes
dim_0_throws: yes
dim_-8_throws: yes
dim_63_throws: yes
dim_65_throws: yes
idmap_bitwidth_3_throws: yes
dim_extends_invalid: yes
invalid_extends_vector: yes
io_extends_vector: yes
vector_extends_runtime: yes
