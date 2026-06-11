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

The on-disk formats are versioned by upstream turbovec (v3 as of
upstream 0.6+; v2 files load transparently; v1 is refused with a
rebuild hint). This extension pins upstream **exactly** (`=0.9.0` for
this release) so a given ext-turbovec version always reads and writes
one known format. When a release bumps the upstream pin, the release
notes state whether existing files remain loadable.

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
