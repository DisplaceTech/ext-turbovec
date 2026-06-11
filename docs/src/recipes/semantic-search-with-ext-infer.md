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
every few thousand documents.

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
