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
