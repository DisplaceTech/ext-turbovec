# Verifying your install

```sh
php -m | grep turbovec
```

A deeper check that exercises construction, add, and search:

```sh
php -r '
$i = new Displace\Vector\TurboQuantIndex(8);
$i->add(pack("g*", 1,0,0,0,0,0,0,0));
$r = $i->search(pack("g*", 1,0,0,0,0,0,0,0), 1);
echo $r->ids()[0] === 0 ? "ext-turbovec OK\n" : "unexpected result\n";
'
```

## Troubleshooting

**`libopenblas.so.0: cannot open shared object file` (Linux)** — install
the OpenBLAS runtime: `sudo apt install libopenblas0` (Debian/Ubuntu) or
your distro's equivalent.

**`undefined symbol` errors at load** — the binary was built for a
different PHP minor. PIE matches automatically; for source builds,
rebuild against the `php-config` of the PHP that's loading it.

**`extension_loaded('turbovec')` is false but no error** — check which
ini PHP is reading (`php --ini`) and that the `extension=` line points
at an absolute path.

**Old/exotic x86 CPU** — pre-Haswell (2013) processors run the scalar
fallback. Results are identical; throughput is lower. Nothing to
configure — kernel selection is automatic at runtime.
