--TEST--
IdMapIndex: allowlist filtering — subset property, small-allowlist sizing, validation
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
const N   = 200;

$ids = array_map(static fn (int $i): int => 5000 + $i, range(0, N - 1));

$index = new IdMapIndex(DIM);
$index->addWithIds(packed_unit_vectors(N, DIM), $ids);

$query = packed_vector_at(10, DIM);

// Every returned id is from the allowlist.
$allowlist = array_map(static fn (int $i): int => 5000 + $i * 6, range(0, 29)); // 30 ids
$result    = $index->search($query, k: 10, allowlist: $allowlist);
echo "row_count: ", $result->count() === 10 ? "yes" : "no", "\n";
echo "subset_of_allowlist: ",
    array_diff($result->ids(), $allowlist) === [] ? "yes" : "no", "\n";

// The best allowed match wins: 5010 (the query's own vector) is allowed
// via 5000 + 10*... no — 5010 is not in that allowlist (10 % 6 != 0), so
// rank 1 must be an allowed id even though a better global match exists.
echo "excluded_best_not_returned: ",
    !in_array(5010, $result->ids(), true) ? "yes" : "no", "\n";

// Allowlist smaller than k returns exactly count($allowlist) rows —
// upstream returns min(k, n_allowed), never padded fallbacks.
$small  = [5003, 5050, 5100, 5150, 5199];
$result = $index->search($query, k: 10, allowlist: $small);
echo "small_allowlist_exact: ", $result->count() === count($small) ? "yes" : "no", "\n";
$got = $result->ids();
sort($got);
echo "small_allowlist_complete: ", $got === $small ? "yes" : "no", "\n";

// Duplicate allowlist entries are deduplicated, not double-counted.
$result = $index->search($query, k: 10, allowlist: [5003, 5003, 5050]);
echo "dups_deduplicated: ", $result->count() === 2 ? "yes" : "no", "\n";

// When the query's own vector is allowed, it is rank 1.
$result = $index->search($query, k: 3, allowlist: [5010, 5020, 5030]);
echo "allowed_self_rank1: ", $result->ids()[0] === 5010 ? "yes" : "no", "\n";

// null allowlist means unfiltered.
echo "null_unfiltered: ",
    $index->search($query, k: 10, allowlist: null)->count() === 10 ? "yes" : "no", "\n";

// Empty allowlist is ambiguous — rejected with a hint.
try {
    $index->search($query, k: 10, allowlist: []);
    echo "empty_allowlist: FAIL\n";
} catch (InvalidArgumentException $e) {
    echo "empty_allowlist_throws: yes\n";
}

// Every allowlist id must currently be in the index.
try {
    $index->search($query, k: 10, allowlist: [5003, 99999]);
    echo "unknown_id: FAIL\n";
} catch (InvalidArgumentException $e) {
    echo "unknown_id_throws: yes\n";
}

// Negative allowlist ids are rejected.
try {
    $index->search($query, k: 10, allowlist: [-1]);
    echo "negative_id: FAIL\n";
} catch (InvalidArgumentException $e) {
    echo "negative_id_throws: yes\n";
}
?>
--EXPECT--
row_count: yes
subset_of_allowlist: yes
excluded_best_not_returned: yes
small_allowlist_exact: yes
small_allowlist_complete: yes
dups_deduplicated: yes
allowed_self_rank1: yes
null_unfiltered: yes
empty_allowlist_throws: yes
unknown_id_throws: yes
negative_id_throws: yes
