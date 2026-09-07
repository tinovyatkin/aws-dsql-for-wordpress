#!/usr/bin/env bash
set -euo pipefail
repo_root="$(cd "$(dirname "$0")/.." && pwd)"
export PGSSLROOTCERT="${PGSSLROOTCERT:-system}"
exec php -d zend.exception_ignore_args=1 -d error_reporting=8191 -S 127.0.0.1:9417 -t "$repo_root/.local/wordpress"
