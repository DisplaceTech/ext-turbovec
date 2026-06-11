--TEST--
TurboQuantIndex: add 1000 vectors, every probed self-query matches at rank 1
--SKIPIF--
<?php
if (!extension_loaded('turbovec')) {
    echo 'skip ext-turbovec not loaded';
}
?>
--FILE--
<?php
require __DIR__ . '/vectors.inc';

use Displace\Vector\TurboQuantIndex;

const DIM = 64;
const N   = 1000;

$index = new TurboQuantIndex(DIM);

// Two batches: the second add reuses the calibration locked by the first,
// which is the code path long-lived indexes actually exercise.
$index->add(packed_unit_vectors(500, DIM, seedBase: 1));
$index->add(packed_unit_vectors(500, DIM, seedBase: 501));
echo "count: ", $index->count(), "\n";

// Querying with a vector that is in the index must return its own
// positional id at rank 1 — quantization distortion at 4-bit is far below
// the gap between self-similarity (~1.0) and random cross-similarity.
foreach ([0, 7, 499, 500, 999] as $i) {
    $result = $index->search(packed_vector_at($i, DIM), k: 5);
    $ids    = $result->ids();
    echo "self_match_{$i}: ", $ids[0] === $i ? "yes" : "no (got {$ids[0]})", "\n";
    echo "row_count_{$i}: ", count($ids) === 5 ? "yes" : "no", "\n";
}

// Scores come back best-first.
$scores = $index->search(packed_vector_at(42, DIM), k: 10)->scores();
$sorted = $scores;
rsort($sorted);
echo "scores_descending: ", $scores === $sorted ? "yes" : "no", "\n";

// k larger than the index clamps to the index size.
echo "k_clamped: ", $index->search(packed_vector_at(0, DIM), k: 5000)->count() === N ? "yes" : "no", "\n";

// 2-bit quantization is coarser but self-match must still hold.
$coarse = new TurboQuantIndex(DIM, bitWidth: 2);
$coarse->add(packed_unit_vectors(N, DIM, seedBase: 1));
$ids = $coarse->search(packed_vector_at(123, DIM), k: 1)->ids();
echo "self_match_2bit: ", $ids[0] === 123 ? "yes" : "no (got {$ids[0]})", "\n";
?>
--EXPECT--
count: 1000
self_match_0: yes
row_count_0: yes
self_match_7: yes
row_count_7: yes
self_match_499: yes
row_count_499: yes
self_match_500: yes
row_count_500: yes
self_match_999: yes
row_count_999: yes
scores_descending: yes
k_clamped: yes
self_match_2bit: yes
