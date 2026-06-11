<?php

/*
 * examples/semantic-search.php — the full local RAG retrieval loop:
 * ext-infer embeds a small document set, ext-turbovec indexes and
 * searches it. No services, no network — everything in this PHP process.
 *
 * Requires BOTH extensions plus an embedding-capable GGUF model:
 *
 *     php -d extension=/path/to/libinfer.so \
 *         -d extension=$(pwd)/target/debug/libturbovec.so \
 *         examples/semantic-search.php models/bge-small-en-v1.5-q8_0.gguf
 *
 * Without ext-infer the script explains itself and exits cleanly — see
 * examples/basic-search.php for a standalone, model-free walkthrough.
 */

declare(strict_types=1);

use Displace\Vector\IdMapIndex;
use Displace\Vector\Vectors;

if (!extension_loaded('turbovec')) {
    fwrite(STDERR, "ext-turbovec is not loaded.\n");
    exit(1);
}

if (!extension_loaded('infer')) {
    echo <<<MSG
    This example pairs ext-turbovec with ext-infer (the embedding side of
    the stack), and ext-infer is not loaded — skipping.

        pie install displace/ext-infer       # or build from source
        php -d extension=libinfer.so -d extension=libturbovec.so ...

    For a standalone ext-turbovec demo with synthetic vectors, run:

        php examples/basic-search.php

    MSG;
    exit(0);
}

$modelPath = $argv[1] ?? null;
if ($modelPath === null || !is_file($modelPath)) {
    fwrite(STDERR, "usage: php examples/semantic-search.php <path/to/embedding-model.gguf>\n");
    fwrite(STDERR, "(any purpose-built embedding GGUF: BGE, E5, GTE, Qwen3-Embedding, ...)\n");
    exit(2);
}

// ---------------------------------------------------------------------
// 1. The "document store" — id => text. In a real app this is your DB.
// ---------------------------------------------------------------------
$documents = [
    101 => 'The Eiffel Tower is a wrought-iron lattice tower in Paris, France.',
    102 => 'Photosynthesis converts light energy into chemical energy in plants.',
    103 => 'The French Revolution began in 1789 and reshaped European politics.',
    104 => 'PHP is a general-purpose scripting language suited to web development.',
    105 => 'Croissants are a buttery, flaky pastry of Austrian origin popular in France.',
    106 => 'Rust is a systems programming language focused on safety and speed.',
    107 => 'The Louvre in Paris is the world\'s most-visited museum.',
    108 => 'Chlorophyll gives plants their green color and absorbs sunlight.',
];

// ---------------------------------------------------------------------
// 2. Embed with ext-infer, index with ext-turbovec
// ---------------------------------------------------------------------
//
// Embedding::vector() returns a PHP float array; Vectors::pack() turns it
// into the packed float32 string the index consumes. (A future ext-infer
// release will hand back the packed string directly — see the roadmap.)
$model = \Displace\Infer\Model::load($modelPath, ['embedding' => true]);

$dim   = null;
$index = null;
foreach ($documents as $id => $text) {
    $embedding = $model->embed($text)->normalize();
    $dim     ??= $embedding->dimensions();
    $index   ??= new IdMapIndex(dim: $dim, bitWidth: 4);
    $index->addWithIds(Vectors::pack($embedding->vector()), [$id]);
}
printf("indexed %d documents at dim %d\n\n", $index->count(), $dim);

// ---------------------------------------------------------------------
// 3. Query
// ---------------------------------------------------------------------
$queries = [
    'famous landmarks in Paris',
    'how do plants make energy?',
    'programming languages',
];

foreach ($queries as $q) {
    $queryVec = $model->embed($q)->normalize();
    $result   = $index->search(Vectors::pack($queryVec->vector()), k: 3);

    echo "query: {$q}\n";
    foreach ($result as $row) {
        printf("    %+.4f  [%d] %s\n", $row['score'], $row['id'], $documents[$row['id']]);
    }
    echo "\n";
}

$model->close();
