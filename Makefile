# Contact Form Bundle - Development
.PHONY: help up down down-dev build shell install test test-coverage coverage-check cs-check cs-fix qa clean ensure-up rector rector-dry phpstan release-check release-check-demos composer-sync update validate validate-translations check-no-cursor-coauthor strip-cursor-coauthor-from-history

COMPOSE_FILE ?= docker-compose.yml
COMPOSE     ?= docker compose -f $(COMPOSE_FILE)
SERVICE_PHP ?= php

help:
	@echo "Contact Form Bundle - Development Commands"
	@echo ""
	@echo "  up              Start Docker container"
	@echo "  down            Stop Docker container"
	@echo "  down-dev        Stop dev container (alias for down)"
	@echo "  test            Run PHPUnit tests"
	@echo "  test-coverage   Run tests with code coverage"
	@echo "  coverage-check  Run tests and enforce 99% line coverage"
	@echo "  cs-check / cs-fix  Code style"
	@echo "  rector / rector-dry  Rector"
	@echo "  phpstan         Static analysis"
	@echo "  qa              cs-check + test"
	@echo "  release-check   Pre-release checks"
	@echo ""
	@echo "Demo: make -C demo/symfony8"

build:
	$(COMPOSE) build --no-cache

up:
	$(COMPOSE) build
	$(COMPOSE) up -d
	@sleep 3
	$(COMPOSE) exec -T $(SERVICE_PHP) composer install --no-interaction
	@echo "Container ready."

down:
	$(COMPOSE) down

down-dev: down
	@echo "Dev container stopped."

ensure-up:
	@if ! $(COMPOSE) exec -T $(SERVICE_PHP) true 2>/dev/null; then \
		$(COMPOSE) up -d; sleep 3; \
		$(COMPOSE) exec -T $(SERVICE_PHP) composer install --no-interaction; \
	fi

shell:
	$(COMPOSE) exec $(SERVICE_PHP) sh

install: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer install

test: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer install --no-interaction
	$(COMPOSE) exec $(SERVICE_PHP) composer test

test-coverage: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer install --no-interaction
	$(COMPOSE) exec $(SERVICE_PHP) composer test-coverage | tee coverage-php.txt
	sh .scripts/php-coverage-percent.sh coverage-php.txt

coverage-check: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer coverage-check

cs-check: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer cs-check

cs-fix: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer cs-fix

rector: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer rector

rector-dry: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer rector-dry

phpstan: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer phpstan

validate-translations: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) php bin/console lint:yaml src/Resources/translations 2>/dev/null || \
		$(COMPOSE) exec -T $(SERVICE_PHP) sh -c 'for f in src/Resources/translations/*.yaml; do php -r "yaml_parse_file(\"$$f\");" || exit 1; done'

qa: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer qa

composer-sync: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer validate --strict
	$(COMPOSE) exec -T $(SERVICE_PHP) composer update --no-install

release-check: check-no-cursor-coauthor ensure-up composer-sync cs-fix cs-check rector-dry phpstan coverage-check release-check-demos

release-check-demos:
	@$(MAKE) -C demo release-check

clean:
	rm -rf vendor .phpunit.cache coverage .php-cs-fixer.cache

update: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer update --no-interaction

validate: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer validate --strict

BUNDLE_ROOT := $(abspath $(dir $(lastword $(MAKEFILE_LIST))))
include $(BUNDLE_ROOT)/../.scripts/Makefile.update-deps.mk
check-no-cursor-coauthor:
	@chmod +x .scripts/check-no-cursor-coauthor.sh
	@./.scripts/check-no-cursor-coauthor.sh HEAD
setup-hooks:
	@chmod +x .githooks/pre-commit 2>/dev/null || true
	@chmod +x .githooks/commit-msg 2>/dev/null || true
	@git config core.hooksPath .githooks
	@echo "✅ Git hooks installed (.githooks — includes commit-msg for REQ-GIT-001)."

strip-cursor-coauthor-from-history:
	@chmod +x .scripts/strip-cursor-coauthor-from-history.sh
	@./.scripts/strip-cursor-coauthor-from-history.sh master
