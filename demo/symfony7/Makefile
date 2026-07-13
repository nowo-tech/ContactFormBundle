# Makefile for Symfony 7.0 Demo (Contact Form Bundle)
# Run from this directory: make up, make install, etc.

.PHONY: help up down build install shell logs test test-coverage cache-clear link-bundle update-bundle ensure-up restart

help:
	@echo "Symfony 7.0 Demo - Contact Form Bundle"
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
	@if [ ! -f .env ]; then cp .env.example .env 2>/dev/null || true; fi
	@echo "Installing dependencies (one-off container)..."
	@docker-compose run --rm -T --entrypoint composer -e COMPOSER_MEMORY_LIMIT=-1 php install --no-interaction --no-scripts
	@echo "Warming cache and preparing database..."
	@docker-compose run --rm -T --entrypoint php php -d memory_limit=256M bin/console cache:clear
	@docker-compose run --rm -T --entrypoint php php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
	@docker-compose run --rm -T --entrypoint php php bin/console assets:install public --no-interaction
	@docker-compose run --rm -T --entrypoint php php bin/console app:seed-contact-demo --no-interaction
	@echo "Starting server..."
	docker-compose up -d
	@echo "Waiting for app to respond..."
	@PORT=$$(grep -E '^PORT=' .env 2>/dev/null | cut -d= -f2); PORT=$${PORT:-8020}; \
	for i in 1 2 3 4 5 6 7 8 9 10 11 12 13 14 15; do \
		curl -s -o /dev/null -w "%{http_code}" "http://127.0.0.1:$$PORT/" 2>/dev/null | grep -qE '^[23]' && echo "Demo started at: http://localhost:$$PORT"; exit 0; \
		echo "  waiting... $$i/15"; sleep 2; \
	done; \
	echo "App did not respond in time. Check: docker-compose logs php"; exit 1

down:
	docker-compose down

restart: ensure-up
	docker-compose restart

build:
	docker-compose build --no-cache

ensure-up:
	@if ! docker-compose exec -T php true 2>/dev/null; then \
		echo "Starting container..."; \
		docker-compose up -d; \
		sleep 3; \
	fi

install: ensure-up
	docker-compose exec -T -e COMPOSER_MEMORY_LIMIT=-1 php composer install --no-interaction

shell: ensure-up
	docker-compose exec php sh

logs:
	docker-compose logs -f

test: ensure-up
	@echo "Running demo smoke checks (no PHPUnit suite in this demo)..."
	docker-compose exec -T php php bin/console lint:yaml config --parse-tags --env=dev
	docker-compose exec -T php php bin/console about --env=dev --no-ansi >/dev/null
	@echo "Demo smoke checks passed."

test-coverage: test
	@echo "Demo has no coverage target (smoke checks only)."

cache-clear: ensure-up
	docker-compose exec php php bin/console cache:clear

link-bundle: ensure-up
	docker-compose exec php sh -c "rm -rf vendor/nowo-tech/contact-form-bundle && ln -s /var/contact-form-bundle vendor/nowo-tech/contact-form-bundle && php bin/console cache:clear"
	@echo "Bundle linked to mounted source. Reload the page in the browser."

update-bundle: ensure-up
	docker-compose exec php composer update nowo-tech/contact-form-bundle --no-interaction
	docker-compose exec php php bin/console cache:clear --env=dev
	@echo "Bundle updated. Reload the page in the browser."


# REQ-MAKE-008: update-deps (REQ-MAKE-008)
COMPOSE := docker-compose
SERVICE_PHP := php
BUNDLE_ROOT := $(abspath $(dir $(lastword $(MAKEFILE_LIST)))/../..)
include $(BUNDLE_ROOT)/../.scripts/Makefile.demo-update-deps.mk
