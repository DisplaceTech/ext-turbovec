--TEST--
IdMapIndex::remove() — removed ids vanish from search; other ids stay stable
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
const N   = 50;

$ids = array_map(static fn (int $i): int => 100 + $i, range(0, N - 1));

$index = new IdMapIndex(DIM);
$index->addWithIds(packed_unit_vectors(N, DIM), $ids);

// Remove vector 17 (id 117), then query with its own vector: it must not
// appear anywhere in the results, even at k = full index.
$index->remove(117);
echo "count_after_remove: ", $index->count() === N - 1 ? "yes" : "no", "\n";

$result = $index->search(packed_vector_at(17, DIM), k: N);
echo "removed_id_excluded: ", !in_array(117, $result->ids(), true) ? "yes" : "no", "\n";
echo "result_size_shrunk: ", $result->count() === N - 1 ? "yes" : "no", "\n";

// remove() uses swap-remove internally — the previously-last vector keeps
// its *external* id. Self-match for the last-added id must still hold.
$lastId = 100 + (N - 1);
$got    = $index->search(packed_vector_at(N - 1, DIM), k: 1)->ids()[0];
echo "swapped_id_stable: ", $got === $lastId ? "yes" : "no (got {$got})", "\n";

// Other untouched ids still self-match too.
$got = $index->search(packed_vector_at(3, DIM), k: 1)->ids()[0];
echo "other_ids_stable: ", $got === 103 ? "yes" : "no", "\n";

// Removing an absent id throws — a remove that removes nothing is a bug.
try {
    $index->remove(117);
    echo "double_remove: FAIL\n";
} catch (InvalidArgumentException $e) {
    echo "double_remove_throws: yes\n";
}
try {
    $index->remove(-1);
    echo "negative_remove: FAIL\n";
} catch (InvalidArgumentException $e) {
    echo "negative_remove_throws: yes\n";
}

// A removed id can be re-added (with a fresh vector).
$index->addWithIds(packed_vector_at(17, DIM), [117]);
echo "readd_after_remove: ", $index->count() === N ? "yes" : "no", "\n";
$got = $index->search(packed_vector_at(17, DIM), k: 1)->ids()[0];
echo "readded_self_match: ", $got === 117 ? "yes" : "no", "\n";
?>
--EXPECT--
count_after_remove: yes
removed_id_excluded: yes
result_size_shrunk: yes
swapped_id_stable: yes
other_ids_stable: yes
double_remove_throws: yes
negative_remove_throws: yes
readd_after_remove: yes
readded_self_match: yes
