<?php

/*
 * examples/semantic-search-large.php — timing + memory profile of the
 * full local RAG retrieval loop at corpus scale.
 *
 * Embeds N synthetic-but-meaningful documents with ext-infer, indexes
 * them with ext-turbovec, and reports wall-clock time for every phase
 * (embedding, indexing, persistence, cold + warm search) plus the real
 * memory overhead of the index.
 *
 *     php -d extension=$(pwd)/target/debug/libturbovec.so \
 *         examples/semantic-search-large.php models/bge-small-en-v1.5-q8_0.gguf [docs] [k]
 *
 * Dimensionality is a property of the *model*, not the index — bge-small
 * is 384-dim. For a 1024-dim run, use a 1024-dim embedding GGUF, e.g.
 * bge-large-en-v1.5 or Qwen3-Embedding-0.6B; the script auto-detects.
 *
 * Memory note: the quantized index lives in Rust-side allocations that
 * PHP's memory_get_usage() cannot see, so the index overhead is measured
 * as process RSS delta (with the theoretical code size alongside it for
 * comparison). PHP-side peak is reported separately.
 */

declare(strict_types=1);

use Displace\Infer\Model;
use Displace\Vector\IdMapIndex;
use Displace\Vector\Vectors;

if (!extension_loaded('turbovec') || !extension_loaded('infer')) {
    fwrite(STDERR, "requires both ext-turbovec and ext-infer loaded.\n");
    exit(1);
}

$modelPath = $argv[1] ?? null;
if ($modelPath === null || !is_file($modelPath)) {
    fwrite(STDERR, "usage: php examples/semantic-search-large.php <embedding-model.gguf> [docs=1000] [k=10]\n");
    exit(2);
}
$nDocs = max(10, (int) ($argv[2] ?? 1000));
$k     = max(1, (int) ($argv[3] ?? 10));

// ---------------------------------------------------------------------
// Synthetic corpus: 10 topics x combinatoric sentences. Real enough that
// topical queries retrieve the right cluster; deterministic so runs are
// comparable.
// ---------------------------------------------------------------------
function build_corpus(int $n): array
{
    $topics = [
        'astronomy'   => [['The James Webb telescope', 'A radio observatory', 'The Hubble survey', 'A lunar probe', 'The Gaia mission'],
                          ['captured images of', 'measured the redshift of', 'charted the orbit of', 'detected water ice on', 'mapped the surface of'],
                          ['a distant exoplanet.', 'a spiral galaxy.', 'a near-Earth asteroid.', "Jupiter's moon Europa."]],
        'cooking'     => [['A slow-braised brisket', 'Fresh sourdough bread', 'A classic risotto', 'Homemade pasta dough', 'A delicate hollandaise'],
                          ['needs constant attention to', 'develops deep flavor through', 'comes together quickly with', 'is easy to ruin without', 'rewards patience with'],
                          ['low and steady heat.', 'proper resting time.', 'good quality butter.', 'a heavy-bottomed pan.']],
        'security'    => [['A SQL injection flaw', 'The phishing campaign', 'An exposed API token', 'The ransomware variant', 'A misconfigured firewall'],
                          ['was discovered during', 'allowed attackers to bypass', 'can be mitigated by', 'went undetected despite', 'was patched after'],
                          ['a routine penetration test.', 'multi-factor authentication.', 'the security audit.', 'network segmentation.']],
        'gardening'   => [['Tomato seedlings', 'Perennial herbs', 'Raised garden beds', 'Native wildflowers', 'Fruit tree grafts'],
                          ['thrive best with', 'should be planted after', 'suffer quickly from', 'attract pollinators when', 'need protection from'],
                          ['the last spring frost.', 'well-drained soil.', 'morning sunlight.', 'a deep weekly watering.']],
        'finance'     => [['The central bank', 'A diversified portfolio', 'The quarterly earnings report', 'An index fund', 'The bond market'],
                          ['signaled a shift toward', 'hedges against', 'beat expectations despite', 'reacted sharply to', 'remains resilient amid'],
                          ['rising interest rates.', 'persistent inflation.', 'currency fluctuations.', 'slowing consumer demand.']],
        'medicine'    => [['The clinical trial', 'A new mRNA vaccine', 'The diagnostic imaging study', 'An antibiotic regimen', 'The gene therapy'],
                          ['showed strong results for', 'reduced complications in', 'was approved for treating', 'targets the root cause of', 'improved recovery from'],
                          ['chronic heart disease.', 'early-stage tumors.', 'autoimmune disorders.', 'bacterial infections.']],
        'mountains'   => [['The alpine ascent', 'A glacier crossing', 'The exposed ridge line', 'Base camp logistics', 'An acclimatization rotation'],
                          ['demands careful planning for', 'becomes dangerous during', 'is the crux of', 'requires fixed ropes on', 'tests climbers with'],
                          ['sudden weather changes.', 'the summit push.', 'high-altitude sickness.', 'crevasse fields.']],
        'programming' => [['The PHP runtime', 'A Rust borrow checker error', 'The async event loop', 'A flaky integration test', 'The legacy codebase'],
                          ['surprises newcomers with', 'prevents whole classes of', 'struggles under', 'was refactored to handle', 'gets faster with'],
                          ['memory safety bugs.', 'concurrent workloads.', 'just-in-time compilation.', 'proper dependency injection.']],
        'oceans'      => [['The coral reef survey', 'A deep-sea submersible', 'The tidal monitoring buoy', 'A migrating whale pod', 'The kelp forest ecosystem'],
                          ['documented the decline of', 'recorded unusual activity near', 'depends entirely on', 'recovered rapidly after', 'sustains thousands of'],
                          ['hydrothermal vents.', 'juvenile fish species.', 'the warming current.', 'plankton blooms.']],
        'history'     => [['The medieval manuscript', 'An archaeological dig', 'The trade route network', 'A recently translated tablet', 'The restored fresco'],
                          ['revealed new details about', 'changed our understanding of', 'documents daily life in', 'predates earlier estimates of', 'connects scholars to'],
                          ['the Bronze Age collapse.', 'ancient Roman commerce.', 'a forgotten dynasty.', 'early astronomical records.']],
    ];

    // 10 topics x 5 x 5 x 4 = 1000 base sentences; each full pass through
    // the combinations appends a different framing tail, so the corpus
    // stays unique up to 10,000 documents (and beyond, the tails cycle
    // with a pass number).
    $tails = [
        '', ' This was reported earlier this year.', ' Researchers confirmed it last month.',
        ' The findings were published recently.', ' Experts had long suspected this.',
        ' The result surprised the field.', ' Follow-up work is already underway.',
        ' It took years of effort to demonstrate.', ' The community is still debating it.',
        ' Funding for further study was approved.',
    ];

    $docs = [];
    $id   = 1000;
    $pass = 0;
    while (count($docs) < $n) {
        $tail = $tails[$pass % count($tails)] . ($pass >= count($tails) ? " (series {$pass})" : '');
        foreach ($topics as $parts) {
            [$subjects, $verbs, $objects] = $parts;
            foreach ($subjects as $s) {
                foreach ($verbs as $v) {
                    foreach ($objects as $o) {
                        if (count($docs) >= $n) {
                            break 4;
                        }
                        $docs[$id++] = "$s $v $o$tail";
                    }
                }
            }
        }
        $pass++;
    }
    return $docs;
}

/** Current process RSS in bytes (Linux: /proc, macOS: ps). */
function rss_bytes(): ?int
{
    if (is_readable('/proc/self/statm')) {
        $fields = explode(' ', trim((string) file_get_contents('/proc/self/statm')));
        return (int) $fields[1] * 4096;
    }
    $out = @shell_exec('ps -o rss= -p ' . getmypid());
    return $out === null ? null : ((int) trim($out)) * 1024;
}

function fmt_bytes(?int $b): string
{
    if ($b === null) {
        return 'n/a';
    }
    return $b >= 1048576 ? sprintf('%.1f MB', $b / 1048576) : sprintf('%.1f KB', $b / 1024);
}

function fmt_ms(float $ns): string
{
    $ms = $ns / 1e6;
    return $ms >= 1000 ? sprintf('%.2f s', $ms / 1000) : sprintf('%.2f ms', $ms);
}

$tScript = hrtime(true);
$docs    = build_corpus($nDocs);

// ---------------------------------------------------------------------
// Phase 1: embed
// ---------------------------------------------------------------------
$model = Model::load($modelPath, ['embedding' => true]);

$packed = [];
$dim    = null;
$t0     = hrtime(true);
foreach ($docs as $id => $text) {
    $e     = $model->embed($text)->normalize();
    $dim ??= $e->dimensions();
    $packed[$id] = Vectors::pack($e->vector());
}
$tEmbed = hrtime(true) - $t0;

// ---------------------------------------------------------------------
// Phase 2: index — chunked addWithIds (1000 vectors per call). One giant
// call would work, but each call's input (imploded string, FFI copy,
// f32 decode, encode temporaries) is transient scratch proportional to
// the batch — chunking bounds peak memory and is the realistic
// streaming-ingest pattern. Quantization calibration locks on the first
// batch, exactly as it would in production.
// ---------------------------------------------------------------------
$packedBytes = array_sum(array_map('strlen', $packed));
$rssBefore   = rss_bytes();
$index       = new IdMapIndex(dim: $dim, bitWidth: 4);

$t0 = hrtime(true);
foreach (array_chunk($packed, 1000, preserve_keys: true) as $chunk) {
    $index->addWithIds(implode('', $chunk), array_keys($chunk));
}
$tIndex   = hrtime(true) - $t0;
$rssAfter = rss_bytes();

// ---------------------------------------------------------------------
// Phase 3: persistence round-trip
// ---------------------------------------------------------------------
$file = sys_get_temp_dir() . '/large-corpus.tvim';
$t0 = hrtime(true);
$index->write($file);
$tWrite = hrtime(true) - $t0;
$t0 = hrtime(true);
$index = IdMapIndex::load($file);
$tLoad  = hrtime(true) - $t0;
$diskSize = filesize($file);
unlink($file);

// ---------------------------------------------------------------------
// Phase 4: search — cold (first query builds the SIMD-blocked cache),
// then warm average over real topical queries.
// ---------------------------------------------------------------------
$queries = [
    'telescopes observing distant galaxies and planets',
    'how do I keep my web application secure?',
    'baking bread and slow cooking techniques',
    'investment strategy during inflation',
    'climbing high mountains safely',
    'marine life and ocean ecosystems',
    'new treatments in clinical medicine',
    'growing vegetables in my backyard',
    'ancient civilizations and archaeology',
    'writing fast concurrent software',
];
$queryVecs = array_map(
    fn (string $q): string => Vectors::pack($model->embed($q)->normalize()->vector()),
    $queries,
);

$t0 = hrtime(true);
$coldResult = $index->search($queryVecs[0], $k);
$tCold = hrtime(true) - $t0;
$rssAfterSearch = rss_bytes();

$t0 = hrtime(true);
$warmRuns = 0;
foreach ($queryVecs as $qv) {
    $index->search($qv, $k);
    $warmRuns++;
}
$tWarmAvg = (hrtime(true) - $t0) / $warmRuns;

// ---------------------------------------------------------------------
// Report
// ---------------------------------------------------------------------
$theoretical = (int) ($nDocs * $dim * 4 / 8) + $nDocs * 4;   // codes + scales

printf("\next-turbovec corpus timing — %s\n", basename($modelPath));
printf("%s\n", str_repeat('=', 64));
printf("documents      %d\n", count($docs));
printf("dimensions     %d (determined by the embedding model)\n", $dim);
printf("bit width      4 (quantized codes: %d bits/coordinate)\n\n", 4);

printf("embed          %-10s  %s/doc — ext-infer, CPU\n", fmt_ms($tEmbed), fmt_ms($tEmbed / $nDocs));
printf("index          %-10s  %s packed in (1000/batch), %s quantized\n", fmt_ms($tIndex), fmt_bytes($packedBytes), fmt_bytes($theoretical));
printf("write          %-10s  %s on disk (.tvim)\n", fmt_ms($tWrite), fmt_bytes($diskSize));
printf("load           %-10s\n", fmt_ms($tLoad));
printf("search (cold)  %-10s  first query builds the SIMD cache\n", fmt_ms($tCold));
printf("search (warm)  %-10s  avg over %d queries, top-%d\n\n", fmt_ms($tWarmAvg), $warmRuns, $k);

printf("index core (codes + scales)   %s for %d vectors — vs %s as raw float32\n",
    fmt_bytes($theoretical), $nDocs, fmt_bytes($nDocs * $dim * 4));
printf("process rss before indexing   %s\n", fmt_bytes($rssBefore));
printf("process rss after indexing    %s  (+%s — index + one-time encode scratch)\n",
    fmt_bytes($rssAfter),
    $rssBefore !== null && $rssAfter !== null ? fmt_bytes($rssAfter - $rssBefore) : 'n/a',
);
printf("process rss after 1st search  %s  (+%s — adds rotation matrix + SIMD cache)\n",
    fmt_bytes($rssAfterSearch),
    $rssAfter !== null && $rssAfterSearch !== null ? fmt_bytes($rssAfterSearch - $rssAfter) : 'n/a',
);
printf("php-side peak memory          %s  (packed buffers; the index lives in Rust)\n\n", fmt_bytes(memory_get_peak_usage(true)));

// Wall vs CPU time: llama.cpp embeds across multiple threads, so CPU
// time runs well ahead of the clock — `time` will show user >> real.
// Report both so that's a feature in the output, not a mystery.
$wall = hrtime(true) - $tScript;
$ru   = getrusage();
$cpu  = ($ru['ru_utime.tv_sec'] + $ru['ru_stime.tv_sec'])
      + ($ru['ru_utime.tv_usec'] + $ru['ru_stime.tv_usec']) / 1e6;
printf("total wall time       %s  (%.1f%% of it is embedding)\n", fmt_ms($wall), 100 * $tEmbed / $wall);
printf("total cpu time        %.1f s across all threads (~%.1f cores avg — llama.cpp parallelism)\n\n",
    $cpu, $cpu / ($wall / 1e9));

echo "sample: \"{$queries[0]}\"\n";
foreach ($coldResult as $i => $row) {
    printf("    %+.4f  [%d] %s\n", $row['score'], $row['id'], $docs[$row['id']]);
    if ($i === 2) {
        break; // top 3 is plenty
    }
}

$model->close();
