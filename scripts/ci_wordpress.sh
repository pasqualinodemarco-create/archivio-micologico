#!/usr/bin/env bash
set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
work_root="$(mktemp -d "${RUNNER_TEMP:-${TMPDIR:-/tmp}}/am-ci-XXXXXX")"
php_bin="${AM_TEST_PHP:-$(command -v php)}"
wp_root="$work_root/wordpress"
export AM_WP_ROOT="$wp_root"
export AM_TEST_PHP="$php_bin"
export AM_TEST_DB_BASE="$work_root/baseline.sqlite"
export AM_TEST_DB="$AM_TEST_DB_BASE"
export AM_TEST_RESULTS="$work_root/results.json"
if [[ -n "${GITHUB_ENV:-}" ]]; then
  printf 'AM_TEST_RESULTS=%s\n' "$AM_TEST_RESULTS" >> "$GITHUB_ENV"
fi

download() {
  local url="$1" name="$2" digest="$3"
  if [[ -n "${AM_CI_DOWNLOAD_CACHE:-}" && -f "$AM_CI_DOWNLOAD_CACHE/$name" ]]; then
    cp "$AM_CI_DOWNLOAD_CACHE/$name" "$work_root/$name"
  else
    curl --fail --location --retry 3 --max-time 180 "$url" --output "$work_root/$name"
  fi
  printf '%s  %s\n' "$digest" "$work_root/$name" | sha256sum --check --status
}

download 'https://wordpress.org/wordpress-7.1.4.tar.gz' 'wordpress-7.1.4.tar.gz' '881e53a4681ff2cd911651b11cccdede5ae477ddf0765af5d6a1a8b219a1d835'
download 'https://downloads.wordpress.org/plugin/sqlite-database-integration.3.1.3.zip' 'sqlite-database-integration.3.1.3.zip' 'f6ce35e8419d0b9326221a907627d905e25c2a5ba2afc86492811f14c851efe0'
download 'https://github.com/wp-cli/wp-cli/releases/download/v2.12.0/wp-cli-2.12.0.phar' 'wp-cli-2.12.0.phar' 'ce34ddd838f7351d6759068d09793f26755463b4a4610a5a5c0a97b68220d85c'

tar -xzf "$work_root/wordpress-7.1.4.tar.gz" -C "$work_root"
unzip -q "$work_root/sqlite-database-integration.3.1.3.zip" -d "$wp_root/wp-content/plugins"
cp "$wp_root/wp-content/plugins/sqlite-database-integration/db.copy" "$wp_root/wp-content/db.php"
ln -s "$repo_root" "$wp_root/wp-content/plugins/archivio-micologico"
wp() { "$php_bin" "$work_root/wp-cli-2.12.0.phar" --allow-root --path="$wp_root" "$@"; }
wp config create --dbname=am_ci --dbuser=unused --dbpass=unused --skip-check --extra-php <<'PHP'
define('WP_ENVIRONMENT_TYPE', 'local');
define('DB_PATH', getenv('AM_TEST_DB'));
define('DISABLE_WP_CRON', true);
define('WP_DEBUG', true);
define('WP_DEBUG_DISPLAY', false);
define('WP_DEBUG_LOG', true);
PHP
wp core install --url=http://am-ci.test --title='AM disposable CI tests' --admin_user=test_admin --admin_password='disposable-ci-only' --admin_email=ci@example.test --skip-email
wp plugin activate archivio-micologico
python3 "$repo_root/tests/run_integration.py"
if [[ -n "${GITHUB_STEP_SUMMARY:-}" ]]; then
  python3 - "$AM_TEST_RESULTS" >> "$GITHUB_STEP_SUMMARY" <<'PY'
import json,sys
results=json.load(open(sys.argv[1]))
print('## Test Archivio Micologico\n')
print(f"**{sum(r['passed'] for r in results)}/{len(results)} scenari superati** su WordPress 7.1.4 / PHP 8.3.")
print('\nLo ZIP installabile è disponibile nell’Artifact `archivio-micologico-installabile` se tutti i controlli passano.')
PY
fi
