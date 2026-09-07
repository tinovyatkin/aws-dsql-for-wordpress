#!/usr/bin/env bash
# Explicitly create a fresh, classified migration destination. No data is restored.
set -euo pipefail
umask 077
classification="${1:-}"; output="${2:-}"
if [[ "$classification" != synthetic && "$classification" != production ]] || [[ -z "$output" ]]; then
  echo 'Usage: AWS_PROFILE=profile AWS_REGION=eu-central-1 ./scripts/create-migration-target.sh synthetic|production /private/new-target.json' >&2
  exit 2
fi
[[ ! -e "$output" ]] || { echo 'Target configuration already exists.' >&2; exit 1; }
export AWS_REGION="${AWS_REGION:-eu-central-1}"
export AWS_PROFILE="${AWS_PROFILE:-default}"
if [[ "$classification" == production ]]; then purpose=wordpress-dsql-production-migration; protection=--deletion-protection-enabled; else purpose=synthetic-wordpress-migration; protection=--no-deletion-protection-enabled; fi
partial="$(mktemp "${output}.XXXXXX")"
trap 'rm -f "$partial"' EXIT
aws dsql create-cluster --region "$AWS_REGION" "$protection" --tags "Purpose=$purpose,Project=aws-dsql-for-wordpress,Name=wordpress-dsql-restore-$(date -u +%Y%m%dT%H%M%SZ)" --output json > "$partial"
cluster_id="$(php -r 'echo json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR)["identifier"];' "$partial")"
# Retain the cluster ID immediately so an interrupted wait cannot orphan it.
php -r '$r=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR); echo json_encode(["cluster_id"=>$r["identifier"],"endpoint"=>$r["identifier"].".dsql.".getenv("AWS_REGION").".on.aws","region"=>getenv("AWS_REGION"),"profile"=>getenv("AWS_PROFILE"),"classification"=>$argv[2]],JSON_PRETTY_PRINT),"\n";' "$partial" "$classification" > "$output"
chmod 0600 "$output"
aws dsql wait cluster-active --region "$AWS_REGION" --identifier "$cluster_id"
echo "Migration target ready; configuration saved to $output"
