# Local dev/test for estadistica — farmacia's harness, cloned (docs/adr/0001).
#
# Two harnesses, on purpose:
#   `make test`       — units in a bare PHP container, no Nextcloud. Fast, the default.
#   `make instance-*` — a throwaway Nextcloud 34 + PostgreSQL 18 (compose.dev.yaml)
#                       for what the bare container cannot run: the migrations, the
#                       REM job, the read-only executor, occ. NOT the gestion stack.

IMG := estadistica-test
APS ?= $(CURDIR)/../aps-common
DOCKER := docker run --rm -v "$(CURDIR)":/app -v "$(APS)":/aps-common -w /app $(IMG)
COMPOSE := docker compose -f compose.dev.yaml
OCC := $(COMPOSE) exec --user www-data nextcloud php occ

.PHONY: test build-test-image deps test-integration integration instance-up instance-occ instance-down instance-clean openapi smoke clean aps-sync aps-drift

build-test-image:
	docker build -q -f Dockerfile.test -t $(IMG) . >/dev/null

# Vendor lands through the container, so no host PHP is needed.
deps: build-test-image
	$(DOCKER) composer install --no-interaction --no-progress

test: build-test-image
	$(DOCKER) sh -c "composer install --no-interaction --no-progress -q && vendor/bin/phpunit --configuration phpunit.xml --testdox"

# Against the throwaway instance's real PostgreSQL. Needs `make deps` and `make instance-up` first.
test-integration:
	$(COMPOSE) exec --user www-data -w /var/www/html/custom_apps/estadistica nextcloud \
		php vendor/bin/phpunit --configuration phpunit.integration.xml --testdox

# farmacia's short name, on its own line: repo-docs reads a Makefile target only
# where a line starts with it, and `test-integration integration:` hid both.
integration: test-integration

# The enable is the point: it runs the migrations exactly as a clinic's occ app:enable does.
instance-up:
	$(COMPOSE) up -d --wait
	$(OCC) app:enable estadistica
	# Restart AFTER enabling: the web workers cache the route collection and the
	# enabled-app state in APCu for an hour, and occ (CLI SAPI) cannot invalidate
	# it — without this every estadistica route answers 404 / OCS 998 on a fresh
	# instance (farmacia d4b7e43, CI 2026-09-27). `up --wait` blocks on the
	# healthcheck again.
	$(COMPOSE) restart nextcloud
	$(COMPOSE) up -d --wait
	@echo "Instance ready at http://localhost:8092 (admin / admin)"

# Run any occ command:  make instance-occ CMD="app:list"
instance-occ:
	$(OCC) $(CMD)

instance-down:
	$(COMPOSE) down

# Wipe the instance including its database — next instance-up installs from scratch.
instance-clean:
	$(COMPOSE) down -v

# Regenerate openapi.json from ApiController's attributes and docblocks (from Slice 4).
openapi:
	scripts/openapi.sh

# Copy the aps-common package into its committed homes: vendor/aps/common/src
# (the subtree the tarball ships), the OC\Hooks\Emitter test stub, and the js
# half under src/aps/. The sibling checkout must exist beside this repo.
aps-sync:
	@test -d "$(APS)/src" || { echo "aps-common not found at $(APS) — clone the sibling first"; exit 1; }
	rm -rf vendor/aps/common/src src/aps
	mkdir -p vendor/aps/common tests/stubs/OC/Hooks src/aps
	git -C "$(APS)" archive HEAD src | tar -x -C vendor/aps/common
	cp "$(APS)/stub/OC/Hooks/Emitter.php" tests/stubs/OC/Hooks/Emitter.php
	git -C "$(APS)" archive HEAD js | tar -x -C src/aps --strip-components=1
	rm -f src/aps/*.test.js

# vendor/aps/common/src and src/aps are committed, so they can drift from the
# package the way js/ can drift from src/. `git status --porcelain` (not
# diff --exit-code) so a rename that deletes one file and adds another is seen.
aps-drift: aps-sync
	@drift="$$(git status --porcelain -- vendor/aps/common/src src/aps tests/stubs/OC/Hooks/Emitter.php)"; \
	if [ -n "$$drift" ]; then \
		printf '%s\n' "$$drift"; \
		echo "::error::aps-common artifacts are behind the package. Run 'make aps-sync' and commit the result."; \
		exit 1; \
	fi

# The wiring no unit test can see, against the throwaway instance.
smoke:
	scripts/smoke.sh

clean:
	docker rmi $(IMG) 2>/dev/null || true
