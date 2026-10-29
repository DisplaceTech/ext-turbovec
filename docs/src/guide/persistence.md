# Persistence

Both index classes serialize to a single file and load back with
search results preserved **bit-exactly** — the quantized codes
round-trip unchanged, so a query against a loaded index returns
identical ids *and* identical scores.

```php
$index->write('corpus.tvim');
$index = IdMapIndex::load('corpus.tvim');
```

- `TurboQuantIndex` writes the `.tv` format (codes + scales +
  calibration).
- `IdMapIndex` writes `.tvim` (`.tv` payload plus the id tables —
  removals and id assignments survive the round-trip).

The extensions are conventions, not requirements — the loader checks
magic bytes, not filenames. Loading the wrong class for a file (or a
corrupt/truncated file) throws `IndexIOException`.

## Format stability

The on-disk formats are versioned by upstream turbovec. This extension
pins upstream **exactly** (`=1.1.2` for 0.2.x) so a given ext-turbovec
version always reads and writes one known format. When a release bumps
the upstream pin, the release notes state whether existing files remain
loadable.

| ext-turbovec | writes | reads |
| ------------ | ------ | ----- |
| 0.2.x | v8 | v7, v8 |
| 0.1.x | v3 | v2, v3 |

## Upgrading from 0.1.x: rebuild your indexes

**Index files written by ext-turbovec 0.1.x cannot be loaded by 0.2.x,
and cannot be converted.** turbovec changed its random rotation between
format v3 and v5, which changes every encoded byte, so an old file
carries codes no current build can decode. `load()` reports this as an
`IndexIOException` naming the old release and saying to rebuild.

Rebuilding is the same code path that built the index in the first
place: re-add the source vectors (or re-embed the source documents) and
`write()` again. If you follow the
[rebuild-from-system-of-record pattern](../recipes/persistence-patterns.md),
the upgrade is: deploy 0.2.x, run the rebuild job, swap the file in.
Recall is comparable (within measurement noise on the 0.2.0 release
benchmark), and the rebuilt index uses about half the resident memory.

Files are portable across all supported platforms — the format is
little-endian everywhere, and so are all targets the extension
compiles for.

## What persistence is (and isn't)

`write()` is a full snapshot, not a WAL: writing after every `add()`
rewrites the whole file. For corpora that mutate continuously, treat
the index as a cache rebuilt from your system of record (see
[persistence patterns](../recipes/persistence-patterns.md) for the
atomic-swap and rebuild idioms). `load()` currently reads the file
into memory; mmap-backed loading for very large indexes is on the
roadmap (PLAN.md).
