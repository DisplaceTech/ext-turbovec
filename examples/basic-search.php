<?php

/*
 * examples/basic-search.php — index and search synthetic vectors.
 *
 * What you'll learn:
 *
 *     - how vectors cross into the extension: packed float32 strings,
 *       i.e. the output of pack('g*', ...$floats)
 *     - adding a batch, searching top-k, reading the SearchResult
 *     - allowlist filtering and O(1) removal with IdMapIndex
 *     - persisting an index to disk and loading it back
 *
 * Run it like so (no models, no downloads — vectors are synthetic):
 *
 *     php -d extension=$(pwd)/target/debug/libturbovec.so examples/basic-search.php
 *
 * (.dylib instead of .so on macOS.)
 */

declare(strict_types=1);

use Displace\Vector\IdMapIndex;
use Displace\Vector\TurboQuantIndex;
use Displace\Vector\VectorException;

if (!extension_loaded('turbovec')) {
    fwrite(STDERR, "ext-turbovec is not loaded.\n");
    exit(1);
}

const DIM = 128;
const N   = 5000;

/** Deterministic pseudo-random unit vector — stands in for a real embedding. */
function fake_embedding(int $seed): array
{
    $state = $seed & 0x7FFFFFFF;
    $v     = [];
    for ($i = 0; $i < DIM; $i++) {
        $state = (1103515245 * $state + 12345) & 0x7FFFFFFF;
        $v[]   = $state / 0x40000000 - 1.0;
    }
    $norm = sqrt(array_sum(array_map(fn (float $x): float => $x * $x, $v)));
    return array_map(fn (float $x): float => $x / $norm, $v);
}

try {
    // ---------------------------------------------------------------------
    // 1. Build an index of 5000 vectors
    // ---------------------------------------------------------------------
    //
    // The index accepts packed float32 strings — one or more vectors
    // concatenated. pack('g*', ...$floats) is the canonical way to produce
    // them (Vectors::pack() does the same thing). Batching the pack+add
    // keeps the hot path free of per-element PHP array overhead.
    $index  = new TurboQuantIndex(dim: DIM, bitWidth: 4);
    $packed = '';
    for ($i = 0; $i < N; $i++) {
        $packed .= pack('g*', ...fake_embedding($i));
    }
    $index->add($packed);

    printf("indexed %d vectors of dim %d\n", $index->count(), DIM);
    printf("packed payload: %.1f MB; 4-bit index core: ~%.1f MB\n\n",
        strlen($packed) / 1e6,
        (N * DIM * 4 / 8) / 1e6,
    );

    // ---------------------------------------------------------------------
    // 2. Search
    // ---------------------------------------------------------------------
    //
    // Querying with vector 1234's own embedding must return id 1234 at
    // rank 1 — a quick sanity check that quantization fidelity holds.
    $result = $index->search(pack('g*', ...fake_embedding(1234)), k: 5);
    echo "top-5 for the vector at position 1234:\n";
    foreach ($result as $row) {
        printf("    id %4d    score %+.4f\n", $row['id'], $row['score']);
    }
    echo "\n";

    // ---------------------------------------------------------------------
    // 3. Stable ids, filtering, and removal: IdMapIndex
    // ---------------------------------------------------------------------
    //
    // TurboQuantIndex ids are positional. When your vectors belong to rows
    // in a database, use IdMapIndex and address them by *your* ids.
    $docs = new IdMapIndex(dim: DIM);
    $ids  = [];
    $packed = '';
    for ($i = 0; $i < 100; $i++) {
        $ids[]   = 7000 + $i;          // e.g. SQL primary keys
        $packed .= pack('g*', ...fake_embedding($i));
    }
    $docs->addWithIds($packed, $ids);

    // Filtered search: only consider a candidate set (an ACL check, a SQL
    // WHERE clause, a BM25 prefilter, ...). Results come only from the
    // allowlist, and a small allowlist returns exactly that many rows.
    $allowlist = [7003, 7010, 7042];
    $result    = $docs->search(pack('g*', ...fake_embedding(10)), k: 10, allowlist: $allowlist);
    printf("allowlist of %d -> %d rows, best id %d\n",
        count($allowlist), count($result), $result->ids()[0]);

    // O(1) removal by id; the removed vector stops matching immediately.
    $docs->remove(7010);
    printf("after remove(7010): %d vectors\n\n", $docs->count());

    // ---------------------------------------------------------------------
    // 4. Persist and reload
    // ---------------------------------------------------------------------
    $path = sys_get_temp_dir() . '/basic-search-example.tvim';
    $docs->write($path);
    $loaded = IdMapIndex::load($path);
    printf("round-trip via %s: %d vectors\n", $path, $loaded->count());
    unlink($path);
} catch (VectorException $e) {
    fwrite(STDERR, get_class($e) . ': ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
