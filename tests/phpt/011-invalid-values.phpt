--TEST--
NaN/Inf vector values are rejected with typed exceptions, never index corruption
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
use Displace\Vector\TurboQuantIndex;

const DIM = 64;

// A NaN/Inf coordinate would silently corrupt the index upstream (it
// poisons the per-vector scale), so the binding rejects it up front.
$nanVec = unit_vector(DIM, 1);
$nanVec[10] = NAN;
$infVec = unit_vector(DIM, 2);
$infVec[0] = INF;

$index = new TurboQuantIndex(DIM);
$index->add(packed_unit_vectors(5, DIM));

foreach (['nan' => $nanVec, 'inf' => $infVec] as $label => $vec) {
    try {
        $index->add(pack('g*', ...$vec));
        echo "add_{$label}: FAIL\n";
    } catch (InvalidArgumentException $e) {
        echo "add_{$label}_throws: yes\n";
    }
}
echo "count_unchanged: ", $index->count() === 5 ? "yes" : "no", "\n";

// Queries are validated the same way (upstream would panic, not throw).
try {
    $index->search(pack('g*', ...$nanVec));
    echo "query_nan: FAIL\n";
} catch (InvalidArgumentException $e) {
    echo "query_nan_throws: yes\n";
}

// IdMapIndex: a rejected batch leaves no ghost ids behind — the same ids
// are addable afterwards with clean vectors.
$idmap = new IdMapIndex(DIM);
try {
    $idmap->addWithIds(pack('g*', ...$nanVec), [42]);
    echo "idmap_nan: FAIL\n";
} catch (InvalidArgumentException $e) {
    echo "idmap_nan_throws: yes\n";
}
echo "idmap_empty_after_reject: ", $idmap->count() === 0 ? "yes" : "no", "\n";
$idmap->addWithIds(packed_vector_at(0, DIM), [42]);
echo "id_reusable_after_reject: ", $idmap->count() === 1 ? "yes" : "no", "\n";
?>
--EXPECT--
add_nan_throws: yes
add_inf_throws: yes
count_unchanged: yes
query_nan_throws: yes
idmap_nan_throws: yes
idmap_empty_after_reject: yes
id_reusable_after_reject: yes
