PHPSTAN_CONFIG ?= phpstan.dist.neon

check: ## Check code
	find ./ -name '*.php' -not -path './vendor/*' | xargs -r php -l
	vendor/bin/phpstan analyse --memory-limit=512M -c $(PHPSTAN_CONFIG)
	vendor/bin/php-cs-fixer check
	composer validate --strict --no-check-publish
	composer audit
	vendor/bin/rector process -n
	vendor/bin/deptrac analyse --config-file=deptrac.yaml --no-progress

test: ## Test code
	vendor/bin/phpunit

test-with-coverage: ## Test code with coverage
	vendor/bin/phpunit --coverage-html coverage

infection: ## Run mutation testing
	XDEBUG_MODE=coverage vendor/bin/infection --threads=$(shell nproc) --no-interaction

regenerate-baseline: ## Regenerate baseline
	vendor/bin/phpstan analyse --memory-limit=512M -b phpstan-baseline.neon

fix: ## Fix code
	vendor/bin/php-cs-fixer fix
	vendor/bin/rector process

## Help
help: ## List of all commands
	@grep -E '(^[a-zA-Z_0-9-]+:.*?##.*$$)|(^##)' Makefile \
	| awk 'BEGIN {FS = ":.*?## "}; {printf "${G}%-24s${NC} %s\n", $$1, $$2}' \
	| sed -e 's/\[32m## /[33m/' && printf "\n";

.DEFAULT_GOAL := help
.PHONY: help
