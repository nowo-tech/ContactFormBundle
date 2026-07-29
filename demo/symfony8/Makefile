# Makefile for Symfony 8.0 Demo (Contact Form Bundle)
# Run from this directory: make up, make install, etc.

SHELL := /bin/bash

# Prefer Compose V2; absolute docker path avoids shadowing by local docker/ when PATH has "." (REQ-MAKE-010).
DOCKER_BIN := $(shell command -v docker 2>/dev/null)
ifeq ($(DOCKER_BIN),)
COMPOSE ?= docker-compose
else
COMPOSE ?= $(shell $(DOCKER_BIN) compose version >/dev/null 2>&1 && echo "$(DOCKER_BIN) compose" || echo "docker-compose")
endif

.PHONY: help up down build install shell logs test test-coverage cache-clear link-bundle update-bundle ensure-up restart

help:
	@echo "Symfony 8.0 Demo - Contact Form Bundle"
	@echo ""
	@echo "  make up             Start containers"
	@echo "  make down           Stop containers"
	@echo "  make restart        Restart containers (depends on ensure-up)"
	@echo "  make build          Build images (no cache)"
	@echo "  make install        Install dependencies (composer install in container)"
	@echo "  make shell          Open shell in PHP container"
	@echo "  make logs           Show container logs"
	@echo "  make test           Run demo smoke checks (lint:yaml + about)"
	@echo "  make test-coverage  Alias of test (demos have no PHPUnit/coverage suite)"
	@echo "  make cache-clear    Clear Symfony cache"
	@echo "  make link-bundle    Use bundle from /var/contact-form-bundle (see demo/README.md)"
	@echo "  make update-bundle  Update nowo-tech/contact-form-bundle from path repo and clear cache"
	@echo ""

up:
	@rm -f .env.dev
	@if [ ! -f .env ]; then cp .env.example .env 2>/dev/null || true; fi
	@echo "Installing dependencies (one-off container)..."
	@$(COMPOSE) run --rm -T --entrypoint composer -e COMPOSER_MEMORY_LIMIT=-1 php install --no-interaction --no-scripts
	@echo "Warming cache and preparing database..."
	@$(COMPOSE) run --rm -T --entrypoint php php -d memory_limit=256M bin/console cache:clear
	@$(COMPOSE) run --rm -T --entrypoint php php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
	@$(COMPOSE) run --rm -T --entrypoint php php bin/console assets:install public --no-interaction
	@$(COMPOSE) run --rm -T --entrypoint php php bin/console app:seed-contact-demo --no-interaction
	@echo "Starting server..."
	$(COMPOSE) up -d
	@echo "Waiting for app to respond..."
	@PORT=$$(grep -E '^PORT=' .env 2>/dev/null | cut -d= -f2); PORT=$${PORT:-8021}; \
	for i in 1 2 3 4 5 6 7 8 9 10 11 12 13 14 15; do \
		code=$$(curl -s -o /dev/null -w "%{http_code}" "http://127.0.0.1:$$PORT/" 2>/dev/null || echo "000"); \
		echo "$$code" | grep -qE '^[23]' && { echo "Demo started at: http://localhost:$$PORT"; exit 0; }; \
		echo "  waiting... $$i/15 (HTTP $$code)"; sleep 2; \
	done; \
	echo "App did not respond in time. Check: $(COMPOSE) logs php"; exit 1

down:
	$(COMPOSE) down

restart: ensure-up
	$(COMPOSE) restart

build:
	$(COMPOSE) build --no-cache

ensure-up:
	@if ! $(COMPOSE) exec -T php true 2>/dev/null; then \
		echo "Starting container..."; \
		$(COMPOSE) up -d; \
		sleep 3; \
	fi

install: ensure-up
	$(COMPOSE) exec -T -e COMPOSER_MEMORY_LIMIT=-1 php composer install --no-interaction

shell: ensure-up
	$(COMPOSE) exec php sh

logs:
	$(COMPOSE) logs -f

test: ensure-up
	@echo "Running demo smoke checks (no PHPUnit suite in this demo)..."
	$(COMPOSE) exec -T php php bin/console lint:yaml config --parse-tags --env=dev
	$(COMPOSE) exec -T php php bin/console about --env=dev --no-ansi >/dev/null
	@echo "Demo smoke checks passed."

test-coverage: test
	@echo "Demo has no coverage target (smoke checks only)."

cache-clear: ensure-up
	$(COMPOSE) exec php php bin/console cache:clear

link-bundle: ensure-up
	$(COMPOSE) exec php sh -c "rm -rf vendor/nowo-tech/contact-form-bundle && ln -s /var/contact-form-bundle vendor/nowo-tech/contact-form-bundle && php bin/console cache:clear"
	@echo "Bundle linked to mounted source. Reload the page in the browser."

update-bundle: ensure-up
	$(COMPOSE) exec php composer update nowo-tech/contact-form-bundle --no-interaction
	$(COMPOSE) exec php php bin/console cache:clear --env=dev
	@echo "Bundle updated. Reload the page in the browser."


# REQ-MAKE-008: update-deps (REQ-MAKE-008)
COMPOSE := $(COMPOSE)
SERVICE_PHP := php
BUNDLE_ROOT := $(abspath $(dir $(lastword $(MAKEFILE_LIST)))/../..)
# Optional: monorepo helper absent on standalone GitHub Actions checkout (REQ-MAKE-009).
-include $(BUNDLE_ROOT)/../.scripts/Makefile.demo-update-deps.mk
