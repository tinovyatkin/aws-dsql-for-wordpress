# Native engine prototype — 19 September 2026

A loadable Rust PHP extension executed complete MySQL operations against the
synthetic Aurora DSQL lab. Production and staging configuration were not changed.

## Complete PHP-FPM requests

PHP 8.5.10 NTS, OPcache enabled, macOS arm64, Rust release build, one disposable
FPM worker, remote DSQL in Frankfurt. The baseline is the released PHP engine
0.10.0 with its normal translation cache. Each request constructs a new client;
only the Rust implementation retains its native pool, plans and prepared
statements across requests. Results were compared by checksum for every sample.
The six-query workload includes a join, grouped count, filter, pagination,
IFNULL and LIKE; fixtures contain 80 synthetic rows across two tables.

Final run, median server elapsed milliseconds:

| Workload | PHP engine | Native Rust | Samples per implementation |
| --- | ---: | ---: | ---: |
| One SELECT, warmed native worker | 699.9 | 44.8 | 12 |
| Six SELECTs, warmed native worker | 1,585.8 | 553.9 | 12 |
| One SELECT, native backend recreated each request | 714.2 | 779.0 | 6 |
| Six SELECTs, native backend recreated each request | 1,063.8 | 1,269.6 | 6 |

Modes were interleaved with alternating order and short idle gaps, without
concurrent traffic. The first sample of each workload/mode was excluded from
these summaries. The fresh-backend comparison explicitly clears the Rust pool
and plan cache before each native request, retaining only the process runtime.
The PHP comparison retains its usual filesystem translation cache.

There was considerable timing variation between runs. An earlier two-connection
pool run measured 457.8/35.2 ms for PHP/native single-query requests and
1,144.9/233.3 ms for six-query requests. It also exposed unnecessary cold
connection creation while an earlier connection was asynchronously returning to
the pool; limiting the prototype to one connection per worker configuration
reduced that cold penalty. The table above describes the final configuration.

This is a complete query-service request benchmark, **not a complete WordPress
page render**, same-host production benchmark, load test or RUM measurement.
The remote network amplifies saved round trips. These ratios must not be applied
to production TTFB. With six fresh-backend samples, no confident tail-latency
claim is possible.

## What the measurements establish

- The native path works without PHP parsing, translation, PDO or intermediate
  JSON/PHP serialization. No PHP parser class loaded on native requests.
- Warm requests reused native plans and skipped schema metadata lookup. They also
  reused the physical connection and SQLx protocol-prepared statements, and did
  not add an acquisition health-check query.
- Across the final warm samples, the median difference between the PHP call timer
  and Rust's internal total was **0.068 ms**. This includes final row/Zend
  conversion and call/timer overhead; it does not isolate hypothetical AST
  serialization in a split architecture. It is small for these result sizes.
- Rust tokenization/key construction took a median **0.097 ms** across those warm
  operations. This does not demonstrate a parser/CPU improvement over the
  existing PHP translator's separate microbenchmarks. Network execution dominated.
- The native fresh-backend path did **not** outperform the PHP baseline. The main
  demonstrated benefit is retaining connections, prepared statements and schema-
  dependent plans between requests. Rewriting the language without preserving
  those resources is not supported by these results.
- PHP-reported memory excludes native allocations, so equal PHP memory counters
  are not evidence of equal total memory use.

## Correctness and boundaries

Seven compiler test groups and 74 live DSQL checks passed. Coverage includes
read parity with the PHP engine, Unicode/escaping, hostile-looking bound literals,
nulls, temporal sentinels, identity results, mutation counts/effects, explicit
commit/rollback, failed and abandoned transactions, object-lifetime cache reuse,
server-prepared-statement eviction, reset guards and unsupported-SQL rejection.
`cargo clippy --all-targets -- -D warnings` passed. The final disposable FPM run
completed without worker failures.

The Rust MySQL parser accepted all 266 entries in the saved synthetic WordPress
SQL capture. This is syntax acceptance only; the prototype's translator implements
a smaller explicit subset and cannot yet run the whole WordPress site.

The extension needs the remaining compatibility contracts and Linux/FPM lifecycle,
credential renewal, expiry/reconnect and concurrency testing before a production
candidate. A complete WordPress replay on a disposable fixture is the next
compatibility milestone. The existing PHP engine remains the behavioral reference.

Build/test commands and implemented/missing operations are in [README.md](README.md).
Raw per-request measurements and diagnostic logs are kept in ignored `.local/`.
