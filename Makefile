# Convenience wrappers around cargo + cargo-php so PHP contributors don't
# need to memorize cargo invocations. Each target is a thin pass-through.

CARGO       ?= cargo
PHP         ?= php
PHP_CONFIG  ?= php-config

.PHONY: help build release test clippy fmt fmt-check stubs install uninstall clean check-cargo-php

help:
	@echo "ext-turbovec — common tasks"
	@echo ""
	@echo "  make build       Debug build (cargo build)"
	@echo "  make release     Optimized build (cargo build --release)"
	@echo "  make test        Run PHPT tests against the just-built extension"
	@echo "  make clippy      cargo clippy -- -D warnings"
	@echo "  make fmt         cargo fmt"
	@echo "  make fmt-check   cargo fmt --check"
	@echo "  make stubs       Regenerate stubs/vector.stubs.php"
	@echo "  make install     cargo php install (loads the extension into your PHP)"
	@echo "  make uninstall   cargo php remove"
	@echo "  make clean       cargo clean"
	@echo ""
	@echo "Linux note: building needs libopenblas-dev (upstream turbovec links"
	@echo "OpenBLAS); macOS uses the always-present Accelerate framework."

build:
	$(CARGO) build

release:
	$(CARGO) build --release

clippy:
	$(CARGO) clippy --all-targets -- -D warnings

fmt:
	$(CARGO) fmt

fmt-check:
	$(CARGO) fmt --check

# Run the PHPT suite against the just-built shared object. `cargo test`
# would only exercise Rust unit tests; for end-to-end coverage we load
# the extension into a real PHP and run the upstream PHP test harness.
# Override RUN_TESTS_PHP to point at a run-tests.php from your PHP source
# build (it isn't bundled with most binary distributions).
RUN_TESTS_PHP ?= run-tests.php

# Cargo names the cdylib per host convention: `.dylib` on macOS, `.so`
# everywhere else we support. (`php-config --extension-suffix` is unreliable
# — Homebrew's php-config, for example, omits it — so we detect by `uname`.)
UNAME_S       := $(shell uname -s)
ifeq ($(UNAME_S),Darwin)
EXT_SUFFIX    := dylib
else
EXT_SUFFIX    := so
endif
EXT_PATH      := $(CURDIR)/target/debug/libturbovec.$(EXT_SUFFIX)

test: build
	@test -f "$(EXT_PATH)" || { echo "missing $(EXT_PATH) — run 'make build'"; exit 1; }
	$(PHP) -d extension=$(EXT_PATH) \
		-r 'if (!extension_loaded("turbovec")) { fwrite(STDERR, "turbovec not loaded\n"); exit(1); }'
	@# `run-tests.php` requires TEST_PHP_EXECUTABLE to be an *absolute* path
	@# (it `file_exists()`-checks it), and parses TEST_PHP_ARGS by splitting
	@# on single spaces — so the ini override must be `-d extension=path`,
	@# not `-dextension=path`.
	TEST_PHP_EXECUTABLE=$$(command -v $(PHP)) \
	TEST_PHP_ARGS="-d extension=$(EXT_PATH)" \
		$(PHP) $(RUN_TESTS_PHP) -q --show-diff tests/phpt

stubs: check-cargo-php build
	$(CARGO) php stubs --stubs stubs/vector.stubs.php

install: check-cargo-php
	$(CARGO) php install --release

uninstall: check-cargo-php
	$(CARGO) php remove

clean:
	$(CARGO) clean

check-cargo-php:
	@command -v cargo-php >/dev/null 2>&1 || { \
		echo "cargo-php not found. Install with: cargo install cargo-php"; \
		exit 1; \
	}
