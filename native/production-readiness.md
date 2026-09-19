# Native production release gate

The native prototype is not the production engine. Promotion requires the
following equivalence checks against PHP engine 0.10.0 and a verified rollback.

- [ ] DML/select expression, function, aggregate, query-shape and SQL-mode parity.
- [ ] INSERT/IGNORE/upsert/REPLACE, identity, bounded writes and transaction parity.
- [ ] SHOW/INFORMATION_SCHEMA, logical metadata, Site Health and core SQL parity.
- [ ] Text-envelope and temporal-sentinel parity, including indexed-column guards.
- [ ] Credentials-file providers, safe errors, aborted-operation retries,
      transaction ownership, reconnect/expiry and worker shutdown tests.
- [ ] Thin wpdb integration preserving filters, escaping, results and diagnostics.
- [ ] Controlled upgrade/DDL behavior and schema-cache invalidation verified.
- [ ] Reproducible Debian 12 x86_64 PHP 8.5 NTS build with ABI/load checks.
- [ ] Synthetic WordPress and plugin suite passing through the native engine.
- [ ] Protected staging application, admin, Site Health and full-page checks.
- [ ] Production backup/config rollback, atomic promotion, full verification.

Unsupported behavior must fail explicitly; no silent PHP translation fallback.
