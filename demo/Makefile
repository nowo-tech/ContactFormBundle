# Makefile for Contact Form Bundle Demo
# Each demo has its own docker-compose.yml and can be run independently

DEMOS := symfony7 symfony8

.PHONY: help clean test-all test-coverage-all verify-all release-check release-verify
.PHONY: $(foreach demo,$(DEMOS),up-$(demo) down-$(demo) restart-$(demo) build-$(demo) install-$(demo) update-bundle-$(demo) shell-$(demo) logs-$(demo) test-$(demo) test-coverage-$(demo) verify-$(demo))
.PHONY: up down restart build install update-bundle test shell logs test-coverage verify

help:
	@echo "Contact Form Bundle - Demo Commands"
	@echo ""
	@echo "Usage: make <target>"
	@echo ""
	@for demo in $(DEMOS); do \
		version=$$(echo $$demo | sed 's/symfony/Symfony /' | sed 's/7/7.4/' | sed 's/8/8.1/'); \
		echo "$$version Demo:"; \
		echo "  up-$$demo        Start $$version demo containers"; \
		echo "  down-$$demo      Stop $$version demo containers"; \
		echo "  restart-$$demo   Restart $$version demo containers"; \
		echo "  build-$$demo     Rebuild $$version image (no cache)"; \
		echo "  install-$$demo   Install dependencies for $$version demo"; \
		echo "  update-bundle-$$demo  Update bundle from path repo and clear cache"; \
		echo "  shell-$$demo     Open shell in $$version PHP container"; \
		echo "  logs-$$demo      Show $$version container logs"; \
		echo "  test-$$demo      Run tests for $$version demo"; \
		echo "  test-coverage-$$demo  Run tests with coverage for $$version demo"; \
		echo ""; \
	done
	@echo "  release-check    Pre-release: release-verify (HTTP smoke for each demo)"
	@echo "  release-verify   For each demo: up → healthcheck HTTP 2xx/3xx → down"
	@echo ""
	@echo "Generic (use DEMO=<name>): make up DEMO=symfony7, make update-bundle DEMO=symfony8, etc."
	@echo ""
	@echo "Access: Symfony 7.4 http://localhost:8020  |  Symfony 8.1 http://localhost:8021"
	@echo ""

define up-target-template
up-$(1):
	@$(MAKE) -C $(1) up
endef

define down-target-template
down-$(1):
	@$(MAKE) -C $(1) down
endef

define restart-target-template
restart-$(1):
	@$(MAKE) -C $(1) restart
endef

define build-target-template
build-$(1):
	@$(MAKE) -C $(1) build
endef

define install-target-template
install-$(1):
	@$(MAKE) -C $(1) install
endef

define update-bundle-target-template
update-bundle-$(1):
	@$(MAKE) -C $(1) update-bundle
endef

define shell-target-template
shell-$(1):
	@$(MAKE) -C $(1) shell
endef

define logs-target-template
logs-$(1):
	@cd $(1) && docker-compose logs -f
endef

define test-target-template
test-$(1):
	@$(MAKE) -C $(1) test
endef

define test-coverage-target-template
test-coverage-$(1):
	@$(MAKE) -C $(1) test-coverage
endef

define verify-target-template
verify-$(1):
	@$(MAKE) -C $(1) ensure-up
	@port=$$(grep "^PORT=" $(1)/.env 2>/dev/null | cut -d= -f2 | tr -d '\r'); \
	[ -z "$$port" ] && port=$$( [ "$(1)" = "symfony8" ] && echo "8021" || echo "8020" ); \
	code=000; \
	for i in 1 2 3 4 5 6 7 8 9 10 11 12 13 14 15; do \
		code=$$(curl -s -o /dev/null -w "%{http_code}" "http://127.0.0.1:$$port/" 2>/dev/null || echo "000"); \
		echo "$$code" | grep -qE '^[23]' && break; \
		sleep 2; \
	done; \
	if echo "$$code" | grep -qE '^[23]'; then echo "✅ $(1) OK (HTTP $$code)"; else echo "❌ $(1) HTTP $$code"; exit 1; fi
endef

$(foreach demo,$(DEMOS),$(eval $(call up-target-template,$(demo))))
$(foreach demo,$(DEMOS),$(eval $(call down-target-template,$(demo))))
$(foreach demo,$(DEMOS),$(eval $(call restart-target-template,$(demo))))
$(foreach demo,$(DEMOS),$(eval $(call build-target-template,$(demo))))
$(foreach demo,$(DEMOS),$(eval $(call install-target-template,$(demo))))
$(foreach demo,$(DEMOS),$(eval $(call update-bundle-target-template,$(demo))))
$(foreach demo,$(DEMOS),$(eval $(call shell-target-template,$(demo))))
$(foreach demo,$(DEMOS),$(eval $(call logs-target-template,$(demo))))
$(foreach demo,$(DEMOS),$(eval $(call test-target-template,$(demo))))
$(foreach demo,$(DEMOS),$(eval $(call test-coverage-target-template,$(demo))))
$(foreach demo,$(DEMOS),$(eval $(call verify-target-template,$(demo))))

test-all:
	@$(foreach demo,$(DEMOS),$(MAKE) test-$(demo);)

test-coverage-all:
	@$(foreach demo,$(DEMOS),$(MAKE) test-coverage-$(demo);)

verify-all:
	@$(foreach demo,$(DEMOS),$(MAKE) verify-$(demo);)
	@echo "✅ All demos verified."

up:
	@[ -n "$(DEMO)" ] || { echo "Usage: make up DEMO=symfony7|symfony8"; exit 1; }
	@$(MAKE) up-$(DEMO)

down:
	@[ -n "$(DEMO)" ] || { echo "Usage: make down DEMO=symfony7|symfony8"; exit 1; }
	@$(MAKE) down-$(DEMO)

restart:
	@[ -n "$(DEMO)" ] || { echo "Usage: make restart DEMO=symfony7|symfony8"; exit 1; }
	@$(MAKE) restart-$(DEMO)

build:
	@[ -n "$(DEMO)" ] || { echo "Usage: make build DEMO=symfony7|symfony8"; exit 1; }
	@$(MAKE) build-$(DEMO)

install:
	@[ -n "$(DEMO)" ] || { echo "Usage: make install DEMO=symfony7|symfony8"; exit 1; }
	@$(MAKE) install-$(DEMO)

update-bundle:
	@[ -n "$(DEMO)" ] || { echo "Usage: make update-bundle DEMO=symfony7|symfony8"; exit 1; }
	@$(MAKE) update-bundle-$(DEMO)

test:
	@[ -n "$(DEMO)" ] || { echo "Usage: make test DEMO=symfony7|symfony8"; exit 1; }
	@$(MAKE) test-$(DEMO)

test-coverage:
	@[ -n "$(DEMO)" ] || { echo "Usage: make test-coverage DEMO=symfony7|symfony8"; exit 1; }
	@$(MAKE) test-coverage-$(DEMO)

shell:
	@[ -n "$(DEMO)" ] || { echo "Usage: make shell DEMO=symfony7|symfony8"; exit 1; }
	@$(MAKE) shell-$(DEMO)

logs:
	@[ -n "$(DEMO)" ] || { echo "Usage: make logs DEMO=symfony7|symfony8"; exit 1; }
	@$(MAKE) logs-$(DEMO)

verify:
	@[ -n "$(DEMO)" ] || { echo "Usage: make verify DEMO=symfony7|symfony8"; exit 1; }
	@$(MAKE) verify-$(DEMO)

release-verify:
	@for demo in $(DEMOS); do \
		port=$$(grep '^PORT=' $$demo/.env.example 2>/dev/null | cut -d= -f2 | tr -d '\r'); \
		[ -z "$$port" ] && port=$$(case $$demo in symfony7) echo 8020;; symfony8) echo 8021;; *) echo 8020;; esac); \
		echo "Release verify: $$demo (port $$port)..."; \
		$(MAKE) up-$$demo; \
		$(MAKE) update-bundle-$$demo; \
		code=000; \
		for i in 1 2 3 4 5 6 7 8 9 10 11 12 13 14 15; do \
			code=$$(curl -s -o /dev/null -w "%{http_code}" "http://127.0.0.1:$$port/" 2>/dev/null || echo "000"); \
			echo "$$code" | grep -qE '^[23]' && break; \
			echo "  release-verify: waiting for $$demo... $$i/15 (HTTP $$code)"; \
			sleep 2; \
		done; \
		$(MAKE) down-$$demo 2>/dev/null || true; \
		if ! echo "$$code" | grep -qE '^[23]'; then echo "$$demo healthcheck failed (HTTP $$code)"; exit 1; fi; \
		echo "OK $$demo (HTTP $$code)"; \
	done
	@echo "All demos passed release-verify."

release-check: release-verify
	@echo "All demos passed release-check."

clean:
	@rm -rf $(foreach demo,$(DEMOS),$(demo)/vendor $(demo)/var $(demo)/.phpunit.cache)
	@echo "✅ Cleaned vendor and cache from all demos."

BUNDLE_ROOT := $(abspath $(dir $(lastword $(MAKEFILE_LIST)))/..)
include $(BUNDLE_ROOT)/../.scripts/Makefile.demo-aggregate-update-deps.mk
