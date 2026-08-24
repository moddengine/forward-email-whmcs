#!/usr/bin/env bash
set -euo pipefail

module_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
output_dir=${1:-"$module_dir/dist"}
mkdir -p -- "$output_dir"
output_dir=$(cd -- "$output_dir" && pwd)
# This is PHP code, not a shell expression.
# shellcheck disable=SC2016
version=$(php -r '$m=json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR); echo $m["version"];' "$module_dir/whmcs.json")
release_tmp=$(mktemp -d "${TMPDIR:-/tmp}/forward-email-release.XXXXXX")
trap 'rm -rf -- "$release_tmp"' EXIT

install_dir="$release_tmp/forward_email"
mkdir -p -- "$install_dir"
cp -a -- "$module_dir/forward_email.php" "$module_dir/hooks.php" "$module_dir/templates" "$module_dir/whmcs.json" "$(dirname "$module_dir")/LICENSE" "$install_dir/"
(cd -- "$release_tmp" && zip -qr "forward-email-whmcs-$version.zip" forward_email)
mv -f -- "$release_tmp/forward-email-whmcs-$version.zip" "$output_dir/forward-email-whmcs-$version.zip"
echo "$output_dir/forward-email-whmcs-$version.zip"
