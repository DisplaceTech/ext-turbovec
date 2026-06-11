# ext-turbovec

`ext-turbovec` is a PHP 8.3+ extension for in-process vector indexing and
approximate-nearest-neighbor search. It is the retrieval half of a fully
local RAG stack for PHP — [ext-infer](https://github.com/DisplaceTech/ext-infer)
generates embeddings, ext-turbovec indexes and searches them. No vector
database, no Python sidecar, no data leaving the machine.

The engine is the [`turbovec`](https://github.com/RyanCodrai/turbovec)
Rust crate by Ryan Codrai, an implementation of Google Research's
**TurboQuant** algorithm
([Gollapudi et al., arXiv:2504.19874](https://arxiv.org/abs/2504.19874)):

- **Data-oblivious quantization** — 2 or 4 bits per coordinate with
  near-optimal distortion and *no training phase*. Add vectors and
  they're searchable; the index never needs rebuilding.
- **SIMD search kernels** — hand-written NEON on ARM, AVX2/AVX-512BW on
  x86 (selected at runtime), competitive with or faster than FAISS
  `IndexPQFastScan`.
- **Kernel-level filtering** — id allowlists are honored inside the scan,
  so selective filters get faster, not less accurate.

This extension is a downstream bindings consumer of upstream turbovec,
not a fork — index behavior, file formats, and the quantization math are
all upstream's work.

## Where to go next

- [Installation](./getting-started/installation.md) — PIE or from source.
- [Quick start](./getting-started/quick-start.md) — an index searching in
  ten lines.
- [Packed vectors](./guide/packed-vectors.md) — the one contract you must
  understand: how vectors cross into the extension.
- [Semantic search with ext-infer](./recipes/semantic-search-with-ext-infer.md)
  — the full local RAG loop.
