# ext-turbovec examples

Build the extension first (`make build`), then run each script with the
extension loaded via `-d extension=...`. `.so` below is `.dylib` on macOS.

## [`basic-search.php`](basic-search.php) — standalone, no models

The whole API surface against synthetic vectors: packed-vector batching,
top-k search, `IdMapIndex` with stable ids, allowlist filtering, O(1)
removal, and write/load persistence.

```sh
php -d extension=$(pwd)/target/debug/libturbovec.so examples/basic-search.php
```

## [`semantic-search.php`](semantic-search.php) — the full local RAG loop

[ext-infer](https://github.com/DisplaceTech/ext-infer) embeds a small
document set; ext-turbovec indexes and answers natural-language queries.
Degrades gracefully (with instructions) when ext-infer isn't loaded.

```sh
php -d extension=/path/to/libinfer.so \
    -d extension=$(pwd)/target/debug/libturbovec.so \
    examples/semantic-search.php models/bge-small-en-v1.5-q8_0.gguf
```

Any purpose-built embedding GGUF works: BGE, E5, GTE, Qwen3-Embedding, …

## [`semantic-search-large.php`](semantic-search-large.php) — timing + memory at corpus scale

Embeds and indexes a 10,000-document synthetic corpus (configurable) and
reports wall-clock time per phase — embedding, chunked ingestion,
write/load, cold and warm search — plus honest memory accounting
(process RSS at three points, theoretical index core, PHP-side peak) and
total wall-vs-CPU time so `time`'s `user >> real` is explained by the
output itself.

```sh
php -d extension=/path/to/libinfer.so \
    -d extension=$(pwd)/target/debug/libturbovec.so \
    examples/semantic-search-large.php models/bge-small-en-v1.5-q8_0.gguf [docs=1000] [k=10]
```

The headline it demonstrates: embedding is ~99% of the wall clock;
indexing 10K vectors takes ~300 ms into a ~2 MB index, and warm searches
run in well under a millisecond. Dimensionality follows the model
(bge-small = 384); point it at a 1024-dim GGUF and everything scales
accordingly.
