# Filtered search over SQL ids

The pattern: your database decides *which* documents are eligible
(tenancy, ACLs, status, date ranges — things SQL is good at), and the
vector index ranks *within* that candidate set. Because `IdMapIndex`
uses your primary keys as vector ids, the handoff is just an array of
ints.

## Index with primary keys

```php
use Displace\Vector\IdMapIndex;

// One-time (or scheduled) build: vectors keyed by the documents table's PK.
$index = new IdMapIndex(dim: 768, bitWidth: 4);

$stmt = $pdo->query('SELECT id, embedding FROM documents');   // embedding: pack('g*') BLOB
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $index->addWithIds($row['embedding'], [(int) $row['id']]);
}
$index->write('documents.tvim');
```

## Query within a SQL-defined candidate set

```php
$index = IdMapIndex::load('documents.tvim');

// Stage 1: SQL narrows to what this user may see.
$stmt = $pdo->prepare(
    'SELECT id FROM documents WHERE tenant_id = ? AND status = "published"'
);
$stmt->execute([$tenantId]);
$allowed = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

if ($allowed === []) {
    return [];                       // no candidates -> no search
    // (an empty allowlist throws by design: "no candidates" must be
    //  handled by you, not silently treated as "no filter")
}

// Stage 2: dense rerank inside the candidate set.
$result = $index->search($packedQueryVector, k: 10, allowlist: $allowed);

// Stage 3: hydrate the hits, preserving rank order.
$in   = implode(',', array_fill(0, count($result), '?'));
$stmt = $pdo->prepare("SELECT * FROM documents WHERE id IN ($in)");
$stmt->execute($result->ids());
$byId = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), null, 'id');

foreach ($result as $row) {
    $hit = $byId[$row['id']];
    printf("%.3f  %s\n", $row['score'], $hit['title']);
}
```

## Keeping index and table in sync

- `INSERT` → embed + `addWithIds($packed, [$pk])`
- `DELETE` → `remove($pk)` (O(1))
- `UPDATE` of content → `remove($pk)` then re-add with the new embedding

An unknown id in the allowlist **throws** — that's drift detection, not
an inconvenience. If you see it, a row was deleted from the index (or
never embedded) while SQL still returns it; reconcile rather than
catching and ignoring.

Filtering is exact and happens inside the SIMD kernel, so small
candidate sets are *cheaper* than unfiltered searches — details in
[the filtering guide](../guide/filtering.md).
