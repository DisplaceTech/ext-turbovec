--TEST--
IdMapIndex::write()/load() round-trip preserves ids, results, and removability
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
use Displace\Vector\IndexIOException;

const DIM = 64;
const N   = 80;

$ids = array_map(static fn (int $i): int => 1000 + $i * 3, range(0, N - 1));

$index = new IdMapIndex(DIM);
$index->addWithIds(packed_unit_vectors(N, DIM), $ids);

// Mutate before persisting so the saved state includes post-remove
// swap-remove bookkeeping, not just a pristine append-only index.
$index->remove(1009);   // i = 3
$index->remove(1237);   // i = 79 (the last slot)

$path = tempnam(sys_get_temp_dir(), 'tvimtest') . '.tvim';
$index->write($path);

$queries = [0, 40, 70];
$before  = [];
foreach ($queries as $i) {
    $r = $index->search(packed_vector_at($i, DIM), k: 10);
    $before[$i] = [$r->ids(), $r->scores()];
}

$loaded = IdMapIndex::load($path);
echo "count_preserved: ", $loaded->count() === N - 2 ? "yes" : "no", "\n";

foreach ($queries as $i) {
    $r = $loaded->search(packed_vector_at($i, DIM), k: 10);
    echo "results_identical_{$i}: ",
        [$r->ids(), $r->scores()] === $before[$i] ? "yes" : "no", "\n";
}

// Removed ids stayed removed across the round-trip.
$r = $loaded->search(packed_vector_at(3, DIM), k: N);
echo "removed_stays_removed: ", !in_array(1009, $r->ids(), true) ? "yes" : "no", "\n";

// The loaded index is fully live: removable and appendable.
$loaded->remove(1000);
echo "loaded_removable: ", $loaded->count() === N - 3 ? "yes" : "no", "\n";
$loaded->addWithIds(packed_vector_at(500, DIM, seedBase: 9000), [777777]);
echo "loaded_appendable: ", $loaded->count() === N - 2 ? "yes" : "no", "\n";

unlink($path);

try {
    IdMapIndex::load('/nonexistent/path/index.tvim');
    echo "load_missing: FAIL\n";
} catch (IndexIOException $e) {
    echo "load_missing_throws: yes\n";
}
?>
--EXPECT--
count_preserved: yes
results_identical_0: yes
results_identical_40: yes
results_identical_70: yes
removed_stays_removed: yes
loaded_removable: yes
loaded_appendable: yes
load_missing_throws: yes
