--TEST--
IdMapIndex: external ids round-trip through search; id validation
--SKIPIF--
<?php
if (!extension_loaded('turbovec')) {
    echo 'skip ext-turbovec not loaded';
}
?>
--FILE--
<?php
require __DIR__ . '/vectors.inc';

use Displace\Vector\IdMapIndex;
use Displace\Vector\InvalidArgumentException;

const DIM = 64;
const N   = 100;

// Sparse, non-contiguous external ids — the point of the id map.
$ids = array_map(static fn (int $i): int => 1000 + $i * 7, range(0, N - 1));

$index = new IdMapIndex(DIM);
$index->addWithIds(packed_unit_vectors(N, DIM), $ids);
echo "count: ", $index->count(), "\n";

// Self-queries come back as the caller's external ids, not slots.
foreach ([0, 42, 99] as $i) {
    $got = $index->search(packed_vector_at($i, DIM), k: 1)->ids()[0];
    echo "external_id_{$i}: ", $got === $ids[$i] ? "yes" : "no (got {$got})", "\n";
}

// ids count must equal the vector count.
try {
    $index->addWithIds(packed_unit_vectors(3, DIM, seedBase: 500), [9001, 9002]);
    echo "count_mismatch: FAIL\n";
} catch (InvalidArgumentException $e) {
    echo "count_mismatch_throws: yes\n";
}

// Negative ids are rejected (upstream ids are uint64).
try {
    $index->addWithIds(packed_unit_vectors(1, DIM, seedBase: 600), [-5]);
    echo "negative_id: FAIL\n";
} catch (InvalidArgumentException $e) {
    echo "negative_id_throws: yes\n";
}

// Duplicates within one call are rejected...
try {
    $index->addWithIds(packed_unit_vectors(2, DIM, seedBase: 700), [9100, 9100]);
    echo "dup_in_call: FAIL\n";
} catch (InvalidArgumentException $e) {
    echo "dup_in_call_throws: yes\n";
}

// ...and so is an id that's already in the index.
try {
    $index->addWithIds(packed_unit_vectors(1, DIM, seedBase: 800), [$ids[0]]);
    echo "dup_across_calls: FAIL\n";
} catch (InvalidArgumentException $e) {
    echo "dup_across_calls_throws: yes\n";
}

// Rejected calls never partially apply.
echo "count_after_rejections: ", $index->count() === N ? "yes" : "no", "\n";

// PHP_INT_MAX is a valid id (the full non-negative int range works).
$index->addWithIds(packed_unit_vectors(1, DIM, seedBase: 900), [PHP_INT_MAX]);
$got = $index->search(packed_vector_at(0, DIM, seedBase: 900), k: 1)->ids()[0];
echo "max_int_id: ", $got === PHP_INT_MAX ? "yes" : "no", "\n";
?>
--EXPECT--
count: 100
external_id_0: yes
external_id_42: yes
external_id_99: yes
count_mismatch_throws: yes
negative_id_throws: yes
dup_in_call_throws: yes
dup_across_calls_throws: yes
count_after_rejections: yes
max_int_id: yes
