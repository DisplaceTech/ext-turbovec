--TEST--
Packed payloads that disagree with dim throw DimensionMismatchException
--SKIPIF--
<?php
if (!extension_loaded('turbovec')) {
    echo 'skip ext-turbovec not loaded';
}
?>
--FILE--
<?php
require __DIR__ . '/vectors.inc';

use Displace\Vector\DimensionMismatchException;
use Displace\Vector\InvalidArgumentException;
use Displace\Vector\TurboQuantIndex;

const DIM = 64;

$index = new TurboQuantIndex(DIM);
$index->add(packed_unit_vectors(10, DIM));

// add(): strlen must be a multiple of 4 * dim.
try {
    $index->add(substr(packed_unit_vectors(1, DIM), 0, 100));
    echo "add_partial: FAIL\n";
} catch (DimensionMismatchException $e) {
    echo "add_partial_throws: yes\n";
}

// A payload of whole floats but the wrong dimensionality is still wrong.
try {
    $index->add(pack('g*', ...unit_vector(48, 7)));
    echo "add_wrong_dim: FAIL\n";
} catch (DimensionMismatchException $e) {
    echo "add_wrong_dim_throws: yes\n";
}

// search(): the query must be exactly one vector.
try {
    $index->search(pack('g*', ...unit_vector(63, 7)));
    echo "query_short: FAIL\n";
} catch (DimensionMismatchException $e) {
    echo "query_short_throws: yes\n";
}
try {
    $index->search(packed_unit_vectors(2, DIM));
    echo "query_two_vectors: FAIL\n";
} catch (DimensionMismatchException $e) {
    echo "query_two_vectors_throws: yes\n";
}

// Failed calls never partially apply.
echo "count_unchanged: ", $index->count() === 10 ? "yes" : "no", "\n";

// An empty payload is zero whole vectors — a no-op, not an error.
$index->add('');
echo "empty_add_noop: ", $index->count() === 10 ? "yes" : "no", "\n";

// k < 1 is rejected.
try {
    $index->search(packed_vector_at(0, DIM), k: 0);
    echo "k_zero: FAIL\n";
} catch (InvalidArgumentException $e) {
    echo "k_zero_throws: yes\n";
}
?>
--EXPECT--
add_partial_throws: yes
add_wrong_dim_throws: yes
query_short_throws: yes
query_two_vectors_throws: yes
count_unchanged: yes
empty_add_noop: yes
k_zero_throws: yes
