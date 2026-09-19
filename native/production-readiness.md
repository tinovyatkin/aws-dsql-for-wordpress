# Native production release gate

Release 0.11.0 passed these checks against PHP engine 0.10.0 and the synthetic
MySQL result fixtures on 19 September 2026. Operational evidence remains with
the deploying site; this repository contains the reusable test contracts.

- [x] DML/select expression, function, aggregate, query-shape and SQL-mode parity.
- [x] INSERT/IGNORE/upsert/REPLACE, identity, bounded writes and transaction parity.
- [x] SHOW/INFORMATION_SCHEMA, logical metadata, Site Health and core SQL parity.
- [x] Text-envelope and temporal-sentinel parity, including indexed-column guards.
- [x] Credentials-file providers, safe errors, aborted-operation retries,
      transaction ownership, reconnect/expiry and worker shutdown tests.
- [x] Thin wpdb integration preserving filters, escaping, results and diagnostics.
- [x] Controlled upgrade/DDL behavior and schema-cache invalidation verified.
- [x] Reproducible Debian 12 x86_64 PHP 8.5 NTS build with ABI/load checks.
- [x] Synthetic WordPress and plugin suite passing through the native engine.
- [x] Protected staging application, admin, Site Health and full-page checks.
- [x] Production backup/config rollback, atomic promotion, full verification.

Unsupported behavior must fail explicitly; no silent PHP translation fallback.

The controlled migration/recovery runner intentionally remains PHP. Ordinary
native queries never fall back to that engine after an error. Host PHP ABI,
plugins and schema changes require revalidation before another deployment.
