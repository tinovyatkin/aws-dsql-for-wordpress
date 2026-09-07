#!/usr/bin/env bash
# Removes the specific tagged synthetic cluster recorded by bootstrap-local.sh.
set -euo pipefail
repo_root="$(cd "$(dirname "$0")/.." && pwd)"
cd "$repo_root"
if [[ "${1:-}" != --delete-synthetic-cluster ]]; then
  echo 'Pass --delete-synthetic-cluster to delete the recorded disposable test database.' >&2
  exit 1
fi
cluster_id="$(php -r 'echo json_decode(file_get_contents(".local/cluster.json"),true)["identifier"];')"
profile="$(php -r 'echo json_decode(file_get_contents(".local/settings.json"),true)["profile"];')"
region="$(php -r 'echo json_decode(file_get_contents(".local/settings.json"),true)["region"];')"
purpose="$(aws dsql get-cluster --profile "$profile" --region "$region" --identifier "$cluster_id" --query 'tags.Purpose' --output text)"
[[ "$purpose" == synthetic-wordpress-compatibility ]] || { echo 'Refusing to delete an unrecognized cluster.' >&2; exit 1; }
aws dsql delete-cluster --profile "$profile" --region "$region" --identifier "$cluster_id"
# Keep the record for audit; a subsequent new bootstrap should use a fresh .local directory.
