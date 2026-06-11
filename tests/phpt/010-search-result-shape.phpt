--TEST--
SearchResult implements Countable + IteratorAggregate yielding id/score rows
--SKIPIF--
<?php
if (!extension_loaded('turbovec')) {
    echo 'skip ext-turbovec not loaded';
}
?>
--FILE--
<?php
require __DIR__ . '/vectors.inc';

use Displace\Vector\SearchResult;
use Displace\Vector\SearchResultIterator;
use Displace\Vector\TurboQuantIndex;
use Displace\Vector\VectorException;

const DIM = 64;

$index = new TurboQuantIndex(DIM);
$index->add(packed_unit_vectors(20, DIM));
$result = $index->search(packed_vector_at(5, DIM), k: 7);

echo "instanceof_countable: ", $result instanceof Countable ? "yes" : "no", "\n";
echo "instanceof_aggregate: ", $result instanceof IteratorAggregate ? "yes" : "no", "\n";
echo "count_function: ", count($result) === 7 ? "yes" : "no", "\n";
echo "count_method: ", $result->count() === 7 ? "yes" : "no", "\n";

// ids() and scores() are parallel, and iteration yields matching rows.
$ids    = $result->ids();
$scores = $result->scores();
echo "parallel_arrays: ", count($ids) === count($scores) ? "yes" : "no", "\n";

$rowsOk = true;
$keysOk = true;
$n      = 0;
foreach ($result as $key => $row) {
    $keysOk = $keysOk && ($key === $n);
    $rowsOk = $rowsOk
        && is_array($row)
        && array_keys($row) === ['id', 'score']
        && is_int($row['id']) && $row['id'] === $ids[$n]
        && is_float($row['score']) && $row['score'] === $scores[$n];
    $n++;
}
echo "iterated_all: ", $n === 7 ? "yes" : "no", "\n";
echo "keys_sequential: ", $keysOk ? "yes" : "no", "\n";
echo "rows_match_arrays: ", $rowsOk ? "yes" : "no", "\n";

// getIterator() returns a fresh, well-typed iterator each time — the
// result itself is immutable and re-iterable.
$it = $result->getIterator();
echo "iterator_type: ", $it instanceof SearchResultIterator ? "yes" : "no", "\n";
echo "iterator_is_iterator: ", $it instanceof Iterator ? "yes" : "no", "\n";
$again = 0;
foreach ($result as $row) {
    $again++;
}
echo "reiterable: ", $again === 7 ? "yes" : "no", "\n";

// Searching an empty index yields an empty, still-well-formed result.
$empty = (new TurboQuantIndex(DIM))->search(packed_vector_at(0, DIM));
echo "empty_count: ", count($empty) === 0 ? "yes" : "no", "\n";
$iterated = false;
foreach ($empty as $row) {
    $iterated = true;
}
echo "empty_foreach_noop: ", $iterated ? "no" : "yes", "\n";

// Neither result class is directly constructible.
try {
    new SearchResult();
    echo "result_ctor: FAIL\n";
} catch (VectorException $e) {
    echo "result_ctor_throws: yes\n";
}
try {
    new SearchResultIterator();
    echo "iterator_ctor: FAIL\n";
} catch (VectorException $e) {
    echo "iterator_ctor_throws: yes\n";
}
?>
--EXPECT--
instanceof_countable: yes
instanceof_aggregate: yes
count_function: yes
count_method: yes
parallel_arrays: yes
iterated_all: yes
keys_sequential: yes
rows_match_arrays: yes
iterator_type: yes
iterator_is_iterator: yes
reiterable: yes
empty_count: yes
empty_foreach_noop: yes
result_ctor_throws: yes
iterator_ctor_throws: yes
