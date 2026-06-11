# Persistence patterns

`write()` is a full snapshot. These idioms make snapshots safe and
cheap in real deployments.

## Atomic swap

Never write over the file a live process might be loading. Write to a
temp path on the same filesystem, then `rename()` — atomic on POSIX:

```php
$tmp = $path . '.tmp.' . getmypid();
$index->write($tmp);
rename($tmp, $path);          // readers see old-or-new, never partial
```

## Embed once, serve many

Embedding is the expensive step; searching is cheap. Split the
lifecycle:

- A **builder** (cron job, queue worker, deploy step) embeds documents
  and writes `corpus.tvim`.
- **Servers** `load()` the snapshot at startup (or on mtime change) and
  only search. `search()` is concurrency-safe; a loaded index serving
  reads needs no locking.

```php
// In a long-lived worker:
static $index = null, $loadedAt = 0;
$mtime = filemtime('corpus.tvim');
if ($index === null || $mtime > $loadedAt) {
    $index    = IdMapIndex::load('corpus.tvim');
    $loadedAt = $mtime;
}
```

## Incremental updates vs rebuilds

`IdMapIndex` handles live `addWithIds()`/`remove()` fine — persist by
re-snapshotting on a schedule (the atomic swap above), not after every
mutation. Two caveats that suggest periodic *rebuilds from source*
instead of indefinite incremental mutation:

- Quantization calibration is fitted on the **first batch** and reused
  for all later adds (by design — all vectors must share a coordinate
  system). If your embedding distribution drifts far from that first
  batch, a fresh rebuild re-fits calibration.
- Removals are swap-removes; space is reclaimed, but a corpus that has
  churned 90% since its first batch deserves a clean rebuild anyway.

A rebuild is just: new index, re-add from your system of record, write
to temp, swap. With stored `pack('g*')` blobs (no re-embedding), it
runs at ingest speed — typically seconds per million vectors.

## Versioning your snapshots

Name files by content, not just `corpus.tvim`:

```php
$file = sprintf('corpus-%s-d%d-b%d.tvim', $modelTag, $dim, $bitWidth);
```

An index is only meaningful with the embedding model that produced its
vectors — encode the model identity in the filename so a model upgrade
can't silently mix old and new vector spaces. Keep the previous
snapshot for instant rollback.
