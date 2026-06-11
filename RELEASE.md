# Releasing `ext-turbovec`

End-to-end guide for cutting a release. Each step is a single command
plus a sentence on why it exists.

## TL;DR

```sh
# 1. Bump versions
edit Cargo.toml       # [package].version = "0.1.0"
edit composer.json    # (no version key; PIE reads the git tag)
cargo update --workspace

# 2. Verify locally
cargo fmt --all --check
cargo clippy --all-targets -- -D warnings
make test
composer validate composer.json

# 3. Land the bump
git commit -am "chore(release): v0.1.0"
git push

# 4. Tag and push the tag — CI builds + uploads artifacts
git tag v0.1.0
git push --tags

# 5. Visit the draft release on GitHub, edit notes, hit Publish.
```

The rest of this document expands on each step.

## Versioning

We follow [SemVer](https://semver.org/) with one nuance: pre-1.0,
breaking changes happen between minors (0.1.x → 0.2.x), not patches.
Patches are bug-fixes only.

Two files carry the version explicitly:

- `Cargo.toml` (`[package].version`).
- The `git` tag (`v{semver}`). PIE reads its version from the tag, not
  from `composer.json`.

`composer.json` does **not** carry a version key — that would conflict
with the tag-derived version Composer infers. The `branch-alias` under
`extra` exists only so `dev-main` resolves to `0.x.x-dev` for users
pinning a dev branch.

One more version to keep in mind: the **upstream `turbovec` pin** in
`Cargo.toml` is exact (`=0.9.0`) because the `.tv`/`.tvim` on-disk
formats must stay stable. If a release bumps the pin, say so in the
release notes and state whether existing index files remain loadable
(upstream's format-versioning notes are in their `io.rs`).

## Pre-flight checklist

Before tagging, run:

```sh
# Rust formatting + lint
cargo fmt --all --check
cargo clippy --all-targets -- -D warnings

# Build the release-mode artifact at least once locally
make release

# Full PHPT suite (no models needed — synthetic vectors)
make test

# composer.json shape
composer validate composer.json

# Optional: regenerate IDE stubs and diff against the committed copy
make stubs && git diff stubs/vector.stubs.php
```

PHPTs must pass on macOS arm64 locally before any release tag — that's
the platform CI exercises least interactively. Linux builders need
`libopenblas-dev` installed first.

## Cutting the tag

```sh
git tag v0.1.0
git push --tags
```

That's the *only* user-facing action that triggers a release. The tag
must:

- be a regular tag matching the glob `v*` (the release workflow's
  trigger)
- correspond to a clean tree (the version bump + lint should already
  be on `main`)

## What the release workflow does

`.github/workflows/release.yml` fires on the `v*` tag push and runs
nine parallel jobs — three PHP minors (8.3, 8.4, 8.5) × three
platforms (macos-arm64, linux-x86_64, linux-arm64):

| Job                                       | Runner             |
| ----------------------------------------- | ------------------ |
| build php8.3-arm64-darwin                 | macos-14           |
| build php8.4-arm64-darwin                 | macos-14           |
| build php8.5-arm64-darwin                 | macos-14           |
| build php8.3-x86_64-linux-glibc           | ubuntu-latest      |
| build php8.4-x86_64-linux-glibc           | ubuntu-latest      |
| build php8.5-x86_64-linux-glibc           | ubuntu-latest      |
| build php8.3-arm64-linux-glibc            | ubuntu-24.04-arm   |
| build php8.4-arm64-linux-glibc            | ubuntu-24.04-arm   |
| build php8.5-arm64-linux-glibc            | ubuntu-24.04-arm   |

Each job:

1. Installs system deps (`libclang-dev`; Linux adds `libopenblas-dev`).
2. Installs the matrix PHP via `shivammathur/setup-php@v2`.
3. Runs `cargo build --release` (x86_64 legs re-assert
   `-C target-cpu=x86-64-v3` inside `RUSTFLAGS`; see PLAN.md).
4. Stages the artifact as `turbovec.so` (PIE expects `.so` inside the
   archive on every platform, macOS included).
5. Tarballs it per PIE's verified filename convention:
   `php_turbovec-{version}_php{minor}-{arch}-{os}-{libc}-nts.tgz` —
   version keeps the leading `v`, darwin's libc slot is `bsdlibc`,
   the extension is `.tgz`.
6. Computes a `.sha256` sidecar.
7. Uploads both to the GitHub Release (created as **draft**).

The first matrix leg creates the draft Release; later legs add files
to the same one.

## Publishing the draft

After CI is green:

1. Visit https://github.com/DisplaceTech/ext-turbovec/releases.
2. Find the draft for the tag, click *Edit*.
3. Write the release notes. Suggested skeleton:
   ```
   ## Highlights
   - <one-line summary>

   ## Added / Changed / Fixed
   - …

   ## Upstream
   - turbovec pinned at =X.Y.Z; index files from <range> load unchanged.

   ## Known caveats
   - Linux binaries require the libopenblas0 package at runtime.
   - ZTS support compiles but is not exercised in CI.
   ```
4. Verify all 9 tarballs + 9 sidecars (18 files total) are attached.
5. Hit *Publish release*.

Until you publish, drafts are visible only to repo maintainers — PIE,
Packagist, and `gh release view` from a non-owner account all see
nothing.

## One-time Packagist registration

PIE installs via Composer, which resolves packages through Packagist.
The first time you ship `ext-turbovec`:

1. Go to <https://packagist.org/login/> and sign in with GitHub.
2. Click **Submit**, paste
   `https://github.com/DisplaceTech/ext-turbovec`, submit.
3. Packagist validates the `type: php-ext` block and registers
   `displace/ext-turbovec`.

If your GitHub account is already linked to Packagist (it is, if
ext-infer's hook is the account-wide one), every future tag triggers a
metadata refresh automatically. Otherwise add the per-repo webhook —
see ext-infer's RELEASE.md for the URL format.

### Tags are immutable on Packagist

Once Packagist indexes a stable `vX.Y.Z`, re-tagging the same name at a
different commit is rejected. If a release is broken, **always bump to
the next patch version** — even if nobody installed the broken tag.
Prerelease tags (`v0.1.0-rc.1`) are not immutable; stable tags are.

## PIE-side install (smoke test post-release)

```sh
pie install displace/ext-turbovec
php -m | grep turbovec
php -r 'var_dump(class_exists("Displace\\Vector\\TurboQuantIndex"));'
```

On Linux, if the module fails to load with an `libopenblas.so.0`
error, that's the runtime dependency: `apt install libopenblas0` (or
the distro equivalent) and retry.

## Hotfix / patch releases

For a bug-fix release (e.g. `0.1.0` → `0.1.1`):

1. Branch from the tag: `git checkout -b hotfix/0.1.1 v0.1.0`
2. Apply the fix (single focused commit).
3. Bump `Cargo.toml` to `0.1.1`.
4. PR into `main`, merge, then tag from `main`.

Don't tag directly from the hotfix branch — `main` should always be
the source of truth for tags.

## Yanking a release

If a release is broken:

1. Mark the GitHub Release as a "pre-release" (lowest-effort signal)
   or delete it.
2. Open an issue documenting the problem.
3. Cut a fixed release with the next patch version. PIE always
   resolves to the latest non-yanked version.

## Caveats / known gaps

- **Linux OpenBLAS** — release binaries dynamically link
  `libopenblas.so.0`. This is inherited from upstream's BLAS usage;
  PLAN.md tracks the static-linking investigation. Keep the caveat in
  every release's notes until resolved.
- **ZTS PHP** is enabled in `composer.json` and thread-safe by design
  (upstream search is `&self`; PHP objects are request-local), but not
  exercised in CI. Treat as "should work, please report bugs".
- **Windows** is intentionally excluded via `os-families-exclude`.
- **musl Linux** is not in the release matrix. `.cargo/config.toml`
  carries the `crt-static` opt-out so an Alpine source build should
  succeed (with `openblas-dev` installed), but we don't ship binaries.
- **macOS deployment target** — if wide-distribution tarballs ever
  fail to load on older macOS, pin `MACOSX_DEPLOYMENT_TARGET` in
  `release.yml` (see ext-infer's PLAN.md note).

## When something goes wrong

| Symptom                                              | First thing to check |
| ---------------------------------------------------- | -------------------- |
| Release workflow doesn't fire                        | Did you push the tag? `git push --tags`. |
| Linux leg fails at link with `-lopenblas`            | The `libopenblas-dev` install step — package name drift on new runner images. |
| PIE can't find a matching binary                     | Verify the tarball filename — PIE matches verbatim on arch/os/libc/tsmode. |
| `php -m` doesn't show `turbovec` after `pie install` | Re-run PIE with `-v`; on Linux check `ldd turbovec.so` for missing `libopenblas.so.0`. |
