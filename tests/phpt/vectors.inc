<?php

/*
 * Deterministic vector generation shared by the PHPT suite.
 *
 * Tests must not download models or depend on RNG state, so vectors come
 * from a seeded LCG (constants from glibc's rand). Every value is derived
 * from the seed alone — the same seed always produces the same packed
 * bytes, on every platform we support.
 */

/** @return list<float> $count pseudo-random floats in [-1, 1). */
function lcg_floats(int $count, int $seed): array
{
    $state = $seed & 0x7FFFFFFF;
    $out   = [];
    for ($i = 0; $i < $count; $i++) {
        // 1103515245 * (2^31 - 1) < 2^61, so this never overflows int64.
        $state = (1103515245 * $state + 12345) & 0x7FFFFFFF;
        $out[] = $state / 0x40000000 - 1.0;
    }
    return $out;
}

/** @return list<float> A deterministic unit-length vector. */
function unit_vector(int $dim, int $seed): array
{
    $v    = lcg_floats($dim, $seed);
    $norm = sqrt(array_sum(array_map(static fn (float $x): float => $x * $x, $v)));
    return array_map(static fn (float $x): float => $x / $norm, $v);
}

/** Packed little-endian float32 batch of $n unit vectors (the index ABI). */
function packed_unit_vectors(int $n, int $dim, int $seedBase = 1): string
{
    $packed = '';
    for ($i = 0; $i < $n; $i++) {
        $packed .= pack('g*', ...unit_vector($dim, $seedBase + $i));
    }
    return $packed;
}

/** The packed form of the single vector at $seedBase + $i — for self-match queries. */
function packed_vector_at(int $i, int $dim, int $seedBase = 1): string
{
    return pack('g*', ...unit_vector($dim, $seedBase + $i));
}
