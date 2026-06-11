# Memory footprint

The index stores, per vector:

- `dim × bitWidth / 8` bytes of quantized codes
- 4 bytes (one float32 scale)

Plus per-index overhead that doesn't grow with the corpus: a `dim²`
float32 rotation matrix and small calibration tables, materialized
lazily on first search. `IdMapIndex` adds ~24 bytes per vector for the
id tables.

## Worked examples (4-bit)

| Corpus | Raw float32 | ext-turbovec | Ratio |
| ------ | ----------- | ------------ | ----- |
| 100K × 1024 | 410 MB | **~52 MB** | 8× |
| 1M × 768    | 3.1 GB | **~390 MB** | 8× |
| 10M × 768   | 31 GB  | **~3.9 GB** | 8× |

The math for the first row:

```
100,000 × 1024 × 4 bits = 51.2 MB   codes
100,000 × 4 bytes       =  0.4 MB   scales
                          ────────
                          ~52 MB    (vs 410 MB raw)
```

At `bitWidth: 2` the codes halve again (100K × 1024 ≈ 26 MB) — worth
evaluating when a downstream reranker can absorb a small recall dip.

## Transient costs to know about

- **First search** after `add()`/`load()` builds the SIMD-blocked
  layout — roughly the size of the codes, briefly held alongside them
  during the repack.
- **`add()` ingestion** processes your packed payload through rotation
  and quantization; peak memory during the call is a small multiple of
  the batch size. Feed multi-gigabyte corpora in chunks (e.g. 100K
  vectors per `add()`) rather than one giant string.
- **The rotation matrix** is `dim² × 4` bytes — 4 MB at dim 1024,
  67 MB at dim 4096. Per index instance, independent of corpus size.

## FPM sizing

Each worker that loads an index holds its own copy (`load()` reads into
private memory today). For a 50 MB index across 20 workers, budget
1 GB, or front the search with a small pool of dedicated workers
instead of loading in every FPM child. mmap-backed sharing is on the
roadmap.
