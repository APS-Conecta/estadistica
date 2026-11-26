#!/usr/bin/env bash
#
# Regenerates openapi.json from the attributes and docblocks on ApiController
# (from Slice 4). farmacia's script, cloned: the spec is the contract suite apps
# and M3 read, so it is generated and committed, never hand-edited.
#
# The generator is a *dev* dependency, fetched into tools/ and gitignored there
# (/tools/composer.phar, /tools/openapi/), both at a pinned version: CI's smoke
# runs this script. The app keeps no PHP dependencies and no composer autoloader
# at runtime.
#
# Usage: scripts/openapi.sh     (override with ESTADISTICA_CONTAINER=…)
set -euo pipefail

CONTAINER="${ESTADISTICA_CONTAINER:-estadistica-dev-nextcloud-1}"
APP=/var/www/html/custom_apps/estadistica

# Root, not www-data: this writes tools/ and openapi.json in the repo bind
# mount, which the host owns.
run() { docker exec -w "$APP" -u root "$CONTAINER" "$@"; }

COMPOSER_VERSION=2.11.3
# What getcomposer.org publishes at /download/2.11.3/composer.phar.sha256sum.
COMPOSER_SHA256=04f8b003da4e3a2459056591fbfebd02b4dc3e5adeb158616b90cf55a65113ec
EXTRACTOR_VERSION=v1.9.1

if ! run test -f tools/composer.phar; then
	printf 'Downloading Composer %s…\n' "$COMPOSER_VERSION"
	run mkdir -p tools
	run curl -sSL -o tools/composer.phar "https://getcomposer.org/download/$COMPOSER_VERSION/composer.phar"
fi

# On every run, a composer.phar already in tools/ included: nothing runs one
# that is not the pinned build.
if ! run sh -c "echo '$COMPOSER_SHA256  tools/composer.phar' | sha256sum -c --status"; then
	printf 'tools/composer.phar is not Composer %s (sha256 mismatch): delete it and run again\n' \
		"$COMPOSER_VERSION" >&2
	exit 1
fi

if ! run test -f tools/openapi/vendor/bin/generate-spec; then
	printf 'Installing nextcloud/openapi-extractor…\n'
	run mkdir -p tools/openapi
	run sh -c "cd tools/openapi && COMPOSER_HOME=/tmp/composer php ../composer.phar \
		require --quiet --no-interaction nextcloud/openapi-extractor:$EXTRACTOR_VERSION"
fi

printf 'Generating openapi.json…\n'
run sh -c 'php tools/openapi/vendor/bin/generate-spec . openapi.json'

# The extractor reads a version out of composer.json, and this app deliberately
# has none (epidemiologia's lesson): the version comes from info.xml, so
# regenerating re-applies it. MetadataTest fails if the two ever disagree.
run sh -c 'php -r "
	\$version = (string)simplexml_load_file(\"appinfo/info.xml\")->version;
	\$spec = json_decode(file_get_contents(\"openapi.json\"), true);
	\$spec[\"info\"][\"version\"] = \$version;
	file_put_contents(\"openapi.json\", json_encode(\$spec, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) . PHP_EOL);
"'

printf '\n'
run sh -c 'php -r "\$s=json_decode(file_get_contents(\"openapi.json\"),true);
	printf(\"  %s v%s — %d routes\\n\", \$s[\"info\"][\"title\"], \$s[\"info\"][\"version\"], count(\$s[\"paths\"]));
	foreach (array_keys(\$s[\"paths\"]) as \$p) { printf(\"    %s\\n\", \$p); }"'
