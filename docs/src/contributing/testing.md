# Testing

End-to-end coverage lives in `tests/phpt/` and runs through PHP's own
test harness against the just-built extension — `cargo test` would only
see Rust, and the things worth testing here (zval conversions,
exception classes, interface wiring) only exist inside a real PHP.

```sh
# One-time: fetch the harness matching your PHP (it isn't bundled
# with binary PHP distributions).
PHP_BRANCH=PHP-$(php -r 'echo PHP_MAJOR_VERSION,".",PHP_MINOR_VERSION;')
curl -sSL "https://raw.githubusercontent.com/php/php-src/${PHP_BRANCH}/run-tests.php" -o run-tests.php

make test
```

`make test` builds, sanity-loads the extension, and runs the suite.
Failures leave `.diff`/`.out` artifacts next to the `.phpt` files
(gitignored).

## Suite conventions

- **No downloads, no RNG.** Vectors come from the seeded LCG in
  `tests/phpt/vectors.inc` — the same seed produces the same packed
  bytes on every platform. The whole suite runs in seconds.
- **Every public method is covered**, including its failure modes; the
  partial-application guarantees ("a throwing call adds nothing") are
  asserted, not assumed.
- Output is `label: yes` lines compared with `--EXPECT--` — when a test
  fails, the diff names exactly which property broke.
- New public surface lands behind a PHPT that fails before the
  implementation and passes after (see PLAN.md's working agreements).

## Rust-side checks

```sh
make clippy       # -D warnings, enforced in CI
make fmt-check
```

There are no Rust unit tests by design: the crate is a thin binding
layer, and `cargo test` can't link Zend symbols anyway (the same
constraint ext-infer documents). Logic worth unit-testing lives
upstream in the turbovec crate, which has its own suite.
