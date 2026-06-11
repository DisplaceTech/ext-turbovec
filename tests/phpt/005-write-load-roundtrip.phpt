--TEST--
TurboQuantIndex::write()/load() round-trip preserves search results exactly
--SKIPIF--
<?php
if (!extension_loaded('turbovec')) {
    echo 'skip ext-turbovec not loaded';
}
?>
--FILE--
<?php
require __DIR__ . '/vectors.inc';

use Displace\Vector\IndexIOException;
use Displace\Vector\TurboQuantIndex;

const DIM = 64;

$path = tempnam(sys_get_temp_dir(), 'tvtest') . '.tv';

$index = new TurboQuantIndex(DIM);
$index->add(packed_unit_vectors(200, DIM));

$queries = [3, 77, 150];
$before  = [];
foreach ($queries as $i) {
    $r = $index->search(packed_vector_at($i, DIM), k: 10);
    $before[$i] = [$r->ids(), $r->scores()];
}

$index->write($path);
$loaded = TurboQuantIndex::load($path);

echo "count_preserved: ", $loaded->count() === 200 ? "yes" : "no", "\n";

// Identical ids AND identical scores — the quantized codes round-trip
// bit-exactly, so this is === comparison, not approximate.
foreach ($queries as $i) {
    $r = $loaded->search(packed_vector_at($i, DIM), k: 10);
    echo "results_identical_{$i}: ",
        [$r->ids(), $r->scores()] === $before[$i] ? "yes" : "no", "\n";
}

// A loaded index keeps working as a live index.
$loaded->add(packed_unit_vectors(1, DIM, seedBase: 9001));
echo "loaded_still_writable: ", $loaded->count() === 201 ? "yes" : "no", "\n";

unlink($path);

// I/O failures are typed.
try {
    TurboQuantIndex::load('/nonexistent/path/index.tv');
    echo "load_missing: FAIL\n";
} catch (IndexIOException $e) {
    echo "load_missing_throws: yes\n";
}
try {
    $index->write('/nonexistent/dir/index.tv');
    echo "write_bad_dir: FAIL\n";
} catch (IndexIOException $e) {
    echo "write_bad_dir_throws: yes\n";
}
?>
--EXPECT--
count_preserved: yes
results_identical_3: yes
results_identical_77: yes
results_identical_150: yes
loaded_still_writable: yes
load_missing_throws: yes
write_bad_dir_throws: yes
