--TEST--
Index files written by ext-turbovec 0.1.x (turbovec format v3) fail to load with a rebuild hint
--SKIPIF--
<?php
if (!extension_loaded('turbovec')) {
    echo 'skip ext-turbovec not loaded';
}
?>
--FILE--
<?php
use Displace\Vector\IdMapIndex;
use Displace\Vector\IndexIOException;
use Displace\Vector\TurboQuantIndex;

// Fixtures were written by the ext-turbovec 0.1.0 release build
// (turbovec 0.9.0). turbovec 1.x reads only formats v7/v8, and v1-v4 files
// cannot be converted, so the only fix is a rebuild — the message must say so.
$cases = [
    'v0.1-format-v3.tv'   => fn ($p) => TurboQuantIndex::load($p),
    'v0.1-format-v3.tvim' => fn ($p) => IdMapIndex::load($p),
];
foreach ($cases as $file => $load) {
    try {
        $load(__DIR__ . '/../fixtures/' . $file);
        echo "$file: loaded?!\n";
    } catch (IndexIOException $e) {
        $m = $e->getMessage();
        echo $file, ': ',
            str_contains($m, 'format v3') ? 'version ' : '',
            str_contains($m, 'ext-turbovec 0.1.x') ? 'release ' : '',
            str_contains($m, 'Rebuild it') ? 'rebuild-hint' : "unexpected: $m", "\n";
    }
}

// A non-index file is still reported as such, not as a legacy index.
$junk = tempnam(sys_get_temp_dir(), 'tv');
file_put_contents($junk, str_repeat("\0", 64));
try {
    TurboQuantIndex::load($junk);
} catch (IndexIOException $e) {
    echo 'junk: ', str_contains($e->getMessage(), 'ext-turbovec 0.1.x') ? 'misreported' : 'ok', "\n";
}
unlink($junk);
?>
--EXPECT--
v0.1-format-v3.tv: version release rebuild-hint
v0.1-format-v3.tvim: version release rebuild-hint
junk: ok
