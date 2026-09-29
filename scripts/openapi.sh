#!/usr/bin/env bash
#
# Regenerates openapi.json from the attributes and docblocks on ApiController
# (from Slice 4). farmacia's script, cloned: the spec is the contract suite apps
# and M3 read, so it is generated and committed, never hand-edited.
#
# The generator is a *dev* dependency, fetched into tools/ and gitignored there
# (/tools/composer.phar, /tools/openapi/). The app keeps no PHP dependencies and
# no composer autoloader at runtime.
#
# Usage: scripts/openapi.sh     (override with ESTADISTICA_CONTAINER=…)
set -euo pipefail

CONTAINER="${ESTADISTICA_CONTAINER:-estadistica-dev-nextcloud-1}"
APP=/var/www/html/custom_apps/estadistica

# Root, not www-data: this writes tools/ and openapi.json in the repo bind
# mount, which the host owns.
run() { docker exec -w "$APP" -u root "$CONTAINER" "$@"; }

if ! run test -f tools/composer.phar; then
	printf 'Downloading Composer…\n'
	run mkdir -p tools
	run sh -c 'curl -sSL -o tools/composer.phar https://getcomposer.org/composer-stable.phar'
fi

if ! run test -f tools/openapi/vendor/bin/generate-spec; then
	printf 'Installing nextcloud/openapi-extractor…\n'
	run mkdir -p tools/openapi
	run sh -c 'cd tools/openapi && COMPOSER_HOME=/tmp/composer php ../composer.phar \
		require --quiet --no-interaction nextcloud/openapi-extractor'
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
