# Semantic search with ext-infer

The canonical pairing: [ext-infer](https://github.com/DisplaceTech/ext-infer)
turns text into vectors, ext-turbovec turns vectors into search. Both run
inside the PHP process — the whole retrieval loop is local.

A runnable version of this recipe ships in the repo as
[`examples/semantic-search.php`](https://github.com/DisplaceTech/ext-turbovec/blob/main/examples/semantic-search.php).

## Indexing

```php
use Displace\Infer\Model;
use Displace\Vector\IdMapIndex;
use Displace\Vector\Vectors;

// Any purpose-built embedding GGUF: BGE, E5, GTE, Qwen3-Embedding, ...
$model = Model::load('models/bge-small-en-v1.5-q8_0.gguf', ['embedding' => true]);

// $documents: id => text, e.g. straight out of your database.
$index = null;
foreach ($documents as $id => $text) {
    $embedding = $model->embed($text)->normalize();   // unit length -> cosine scores
    $index   ??= new IdMapIndex(dim: $embedding->dimensions(), bitWidth: 4);
    $index->addWithIds(Vectors::pack($embedding->vector()), [$id]);
}

$index->write('corpus.tvim');     // embed once, search forever
```

Two details that matter:

- **`normalize()`** — unit-length vectors make the index's inner-product
  scores equal cosine similarity, so a perfect match reads ≈ 1.0.
- **`Vectors::pack()`** bridges ext-infer's float array to the packed
  contract. ext-infer's roadmap includes emitting packed float32
  directly, which will make this line a pass-through.

For large corpora, batch: accumulate `Vectors::pack(...)` strings and
ids in PHP arrays, then call `addWithIds(implode('', $packed), $ids)`
every few thousand documents — packed vectors batch by plain string
concatenation.

Long documents retrieve better as chunks than as whole-file vectors.
[`displace/ai-toolkit`](https://github.com/DisplaceTech/ai-toolkit)
ships structure-aware chunkers that pair with this loop:

```php
use Displace\AI\Toolkit\Text\RecursiveCharacterChunker;

$chunker = new RecursiveCharacterChunker(size: 2000, overlap: 200);

foreach ($documents as $id => $text) {
    foreach ($chunker->chunk($text) as $chunk) {
        // embed $chunk, mapping your own composite id => chunk position
    }
}
```

## Querying

```php
$query  = $model->embed('how do I reset my password?')->normalize();
$result = $index->search(Vectors::pack($query->vector()), k: 5);

foreach ($result as $row) {
    printf("%.3f  %s\n", $row['score'], $documents[$row['id']]);
}
```

## Closing the RAG loop

Feed the hits back into a chat model — also via ext-infer — and you
have retrieval-augmented generation with zero services:

```php
$context = implode("\n\n", array_map(
    fn (array $row): string => $documents[$row['id']],
    iterator_to_array($result),
));

$chat   = Model::load('models/Qwen3-4B-Q4_K_M.gguf');
$answer = $chat->chat(
    \Displace\Infer\Prompt::system("Answer using only this context:\n{$context}")
        ->withUser($question),
    maxTokens: 512,
);
echo $answer->answer();
```

Use one model handle for embeddings and a separate one for chat — the
embedding flag is a load-time mode in ext-infer.

## Decoupling with ai-contracts

Everything above names concrete classes. If your application (or a
framework you're integrating with) should not depend on a specific
engine, code against the
[`displace/ai-contracts`](https://github.com/DisplaceTech/ai-contracts)
interfaces instead and wrap the extensions in thin adapters:

```php
use Displace\AI\Contracts\Embedder;
use Displace\AI\Contracts\VectorIndex;
use Displace\Infer\Model;
use Displace\Vector\IdMapIndex;
use Displace\Vector\Vectors;

final class InferEmbedder implements Embedder
{
    public function __construct(private readonly Model $model) {}

    public function embed(string $text): string
    {
        return Vectors::pack($this->model->embed($text)->normalize()->vector());
    }

    public function embedBatch(array $texts): string
    {
        return implode('', array_map($this->embed(...), $texts));
    }

    public function dimensions(): int
    {
        return $this->model->embed(' ')->dimensions();
    }
}

final class TurbovecIndex implements VectorIndex
{
    public function __construct(private readonly IdMapIndex $index) {}

    public function add(string $vectors, array $ids): void
    {
        $this->index->addWithIds($vectors, $ids);
    }

    public function search(string $query, int $k = 10, ?array $allowlist = null): array
    {
        return iterator_to_array($this->index->search($query, $k, $allowlist));
    }

    public function remove(int $id): void
    {
        $this->index->remove($id);
    }

    public function count(): int
    {
        return $this->index->count();
    }
}
```

Application code then takes `Embedder $embedder, VectorIndex $index`
and never mentions either extension — the packed-float32 buffers flow
from `embedBatch()` straight into `add()` with no conversion in
between. Swap in an API-backed embedder or a database-backed index
without touching the call sites.
