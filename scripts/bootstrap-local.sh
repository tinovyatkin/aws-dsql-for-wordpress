#!/usr/bin/env bash
# Creates only a tagged synthetic DSQL cluster and a disposable native-PHP WordPress.
set -euo pipefail
umask 077
repo_root="$(cd "$(dirname "$0")/.." && pwd)"
cd "$repo_root"
export AWS_PROFILE="${AWS_PROFILE:-default}"
export AWS_REGION="${AWS_REGION:-eu-central-1}"
export PGSSLROOTCERT="${PGSSLROOTCERT:-system}"
mkdir -p .local/tools .local/wordpress
for tool in aws php wp curl; do command -v "$tool" >/dev/null; done
php -r 'if (!extension_loaded("pdo_pgsql") || !extension_loaded("mbstring")) { fwrite(STDERR,"pdo_pgsql and mbstring are required\n"); exit(1); }'
if [[ ! -s .local/cluster.json ]]; then
  aws dsql create-cluster --region "$AWS_REGION" --no-deletion-protection-enabled \
    --client-token "$(php -r 'echo bin2hex(random_bytes(16));')" \
    --tags Name=aws-dsql-for-wordpress-poc,Purpose=synthetic-wordpress-compatibility,Project=aws-dsql-for-wordpress \
    --output json > .local/cluster.json
fi
cluster_id="$(php -r 'echo json_decode(file_get_contents(".local/cluster.json"),true,512,JSON_THROW_ON_ERROR)["identifier"];')"
aws dsql wait cluster-active --region "$AWS_REGION" --identifier "$cluster_id"
aws dsql get-cluster --region "$AWS_REGION" --identifier "$cluster_id" --output json > .local/cluster-current.json
php -r '$c=json_decode(file_get_contents(".local/cluster-current.json"),true); if (($c["tags"]["Purpose"]??"")!=="synthetic-wordpress-compatibility") exit(1);'
mv .local/cluster-current.json .local/cluster.json
if command -v composer >/dev/null; then
  composer install --no-interaction --no-progress
else
  if [[ ! -f .local/tools/composer.phar ]]; then
    curl -fsSL https://getcomposer.org/download/latest-stable/composer.phar -o .local/tools/composer.phar
    curl -fsSL https://getcomposer.org/download/latest-stable/composer.phar.sha256sum -o .local/tools/composer.sha256
    php -r '$expected=strtok(file_get_contents(".local/tools/composer.sha256")," \t\r\n"); if (!hash_equals($expected,hash_file("sha256",".local/tools/composer.phar"))) exit(1);'
  fi
  php .local/tools/composer.phar install --no-interaction --no-progress
fi
if [[ ! -f .local/wordpress/wp-includes/version.php ]]; then
  wp core download --version="${WP_VERSION:-7.1}" --path="$repo_root/.local/wordpress"
fi
php -d zend.exception_ignore_args=1 scripts/setup-local.php
php -d zend.exception_ignore_args=1 scripts/install-local.php
printf 'Test site ready. Run ./scripts/serve-local.sh and open http://127.0.0.1:9417\n'
