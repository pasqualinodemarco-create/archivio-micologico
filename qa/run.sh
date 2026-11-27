#!/usr/bin/env bash
# Quality checks run in CI (they need Docker and Packagist).
# 1. PHP syntax on every version the plugin supports; 2. PHPCS: WordPress security + PHP 8.0+ compatibility;
# 3. PHPStan level 5 with WordPress stubs. Reports go to qa/out/.
set -u
cd "$(dirname "$0")/.."
mkdir -p qa/out
status=0

for v in 8.0 8.1 8.2 8.3 8.4; do
	if docker run --rm -v "$PWD":/app -w /app "php:$v-cli" sh -c 'find . -name "*.php" -not -path "./.git/*" -not -path "./qa/*" -print0 | xargs -0 -n1 php -l >/dev/null'; then
		echo "PHP $v: sintassi OK"
	else
		echo "PHP $v: ERRORI DI SINTASSI"; status=1
	fi
done

mkdir -p /tmp/qa
docker run --rm -v /tmp/qa:/qa -w /qa composer:2 sh -c '
	composer init -n --name qa/tools >/dev/null &&
	composer config minimum-stability dev && composer config prefer-stable true &&
	composer config allow-plugins.dealerdirect/phpcodesniffer-composer-installer true &&
	composer require -q --dev --ignore-platform-reqs phpstan/phpstan szepeviktor/phpstan-wordpress wp-coding-standards/wpcs "phpcompatibility/phpcompatibility-wp:^3.0@dev || ^2.1"' || { echo "Strumenti QA non installati"; exit 1; }
sudo chown -R "$(id -u)" /tmp/qa 2>/dev/null || true

php /tmp/qa/vendor/bin/phpcs --standard=qa/phpcs.xml.dist --report-full=qa/out/phpcs.txt --report-summary=qa/out/phpcs-summary.txt --report-source=qa/out/phpcs-sources.txt -q
echo "PHPCS exit $?"
php -d memory_limit=1G /tmp/qa/vendor/bin/phpstan analyse -c qa/phpstan.neon.dist --no-progress --error-format=raw > qa/out/phpstan.txt 2>&1
echo "PHPStan exit $?"
for f in qa/out/phpcs-summary.txt qa/out/phpcs-sources.txt; do echo "== $f"; cat "$f"; done
echo "== PHPStan"; head -200 qa/out/phpstan.txt
exit $status
