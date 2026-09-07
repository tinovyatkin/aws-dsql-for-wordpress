# SQL failure monitoring

The DSQL drop-in emits one `wordpress_dsql_error` JSON event to PHP's configured
error log for every caught connection or query failure. This includes translator
rejections before SQL reaches AWS and failures hidden from visitors by
`$wpdb->suppress_errors()`. No successful query is logged by this feature.

Events contain UTC time, SQLSTATE when provided by PDO, operation, a normalized
query fingerprint, stage (connect/metadata/translate/execute/decode), a relative
code location, frontend/admin/REST/cron/CLI context, elapsed milliseconds and the
number of OCC retries before the final failure. The source prefers a plugin or
theme call site over WordPress core when the bounded stack contains one.
`WITH` is reported as WITH, not classified as a read or write.

The event contains no SQL text, query parameters, exception message/DETAIL,
request URL, user identity, credentials, absolute paths, or stack arguments.
Fingerprints group queries after removing literals/comments and folding case;
quoted identifiers are normalized too, so these are diagnostic grouping keys,
not proof that two queries are equivalent. Locate the code via `source` and
reproduce the query using synthetic values to investigate a failure.

`$wpdb->dsql_errors` holds the latest 100 safe events;
`$wpdb->dsql_error_count` counts all failures in the process/request. Every event
is sent to PHP logging even after the in-memory history fills. The adapter does
not use WordPress's raw SQL/error HTML renderer, including when `show_errors()`
is enabled. Standard `$wpdb->last_error` and `$wpdb->last_query` remain in memory
for WordPress compatibility; other plugins can still expose those. Keep
`SAVEQUERIES` off and `zend.exception_ignore_args` on in production.

## Collect and alert

Use the PHP-FPM/CLI error log outside the document root, with restricted access
and rotation. Check the FPM pool and CLI configurations separately: WordPress
debug logging can override PHP's `error_log`. Do not expose a wp-content debug
log publicly. The logger itself does not install a collector, retain logs,
create an AWS alarm, or guarantee delivery if the PHP log destination fails.

When shipping these lines to CloudWatch Logs, this Logs Insights query groups
failures without needing raw SQL:

```text
fields @timestamp, event, stage, sqlstate, operation, fingerprint, source
| filter event = "wordpress_dsql_error"
| stats count(*) as failures, latest(@timestamp) as last_seen
  by stage, sqlstate, operation, fingerprint, source
| sort failures desc
```

For collectors that retain PHP's timestamp prefix as plaintext, extract the
JSON first with `parse @message /(?<payload>\{.*\})/` and
`fields jsonParse(payload) as diagnostic`, then use `diagnostic.event` etc.
Create a count metric/alarm for these events once collection is deployed;
test the complete path with a deliberate harmless failed SELECT before cutover.
Failures of INSERT/UPDATE/DELETE/REPLACE, connection failures, and COMMIT deserve
immediate investigation; a failed COMMIT can have an uncertain outcome and
must not be blindly retried. Monitor WITH/OTHER too, since they may write.

AWS also supplies [CloudWatch metrics](https://docs.aws.amazon.com/aurora-dsql/latest/userguide/cloudwatch-monitoring.html)
for query timeouts and OCC conflicts, plus automatically enabled
[Database Insights](https://docs.aws.amazon.com/aurora-dsql/latest/userguide/dsql-db-insights.html)
for sampled query load and wait events. Those complement this logger; sampled
performance data is not a complete failed-query ledger. Recovered OCC conflicts
do not emit a final-failure event here. Application error monitoring does not
detect successful queries with different MySQL/DSQL semantics, PHP failures
outside the adapter, or failures in independent Node.js writers. Those writers
need their own error capture.

## Verification

`PGSSLROOTCERT=system php -d zend.exception_ignore_args=1 tests/full-demo/diagnostics.php`
uses the isolated synthetic DSQL runtime. It tests a missing-table SELECT,
rejected indexed NUL write, hidden-error capture, safe output, literal grouping,
malformed strings, successful recovery, and bounded retention. Its temporary
private test log is deleted afterward. It does not contact production.
