# Releasing

The full, authoritative flow lives in
[`RELEASE.md`](https://github.com/DisplaceTech/ext-turbovec/blob/main/RELEASE.md)
at the repo root. The short version:

1. Bump `Cargo.toml`'s version; `cargo update --workspace`.
2. Run the pre-flight checks: `cargo fmt --all --check`,
   `cargo clippy --all-targets -- -D warnings`, `make test`,
   `composer validate composer.json`. PHPTs must pass on macOS arm64
   locally before any tag.
3. Commit, push, then `git tag vX.Y.Z && git push --tags`.
4. The tag triggers `.github/workflows/release.yml`: nine jobs
   (PHP 8.3/8.4/8.5 × macos-arm64/linux-x86_64/linux-arm64) build
   release binaries and attach PIE-named tarballs + sha256 sidecars to
   a **draft** GitHub Release.
5. Review the draft, write notes (remember the Linux `libopenblas0`
   caveat and the upstream-pin/format statement), publish.

Two release-specific rules:

- **Stable tags are immutable on Packagist.** A broken release means a
  new patch version, never a re-tag.
- **Upstream pin bumps are release events.** If `turbovec` moves, the
  notes must state whether existing `.tv`/`.tvim` files remain
  loadable.
