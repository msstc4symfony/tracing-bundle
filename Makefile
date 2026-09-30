check: ## Check code
	@echo "PHP lint"
	@find ./ -name '*.php' -not -path './vendor/*' | xargs -r php -l
	@echo "PHPStan"
	@vendor/bin/phpstan --memory-limit=512M
	@echo "Psalm"
	@vendor/bin/psalm
	@echo "PHP CS Fixer"
	@vendor/bin/php-cs-fixer check

test: ## Test code
	@echo "PHPUnit"
	@vendor/bin/phpunit

regenerate-baseline: ## Regenerate baseline
	@echo "PHPStan"
	@vendor/bin/phpstan analyse --memory-limit=512M -b phpstan-baseline.neon -vv
	@echo "Psalm"
	@vendor/bin/psalm --set-baseline

fix: ## Fix code
	@echo "PHP CS Fixer"
	@vendor/bin/php-cs-fixer fix

enable-git-hooks: ## Enable git hooks
	@git config core.hooksPath .githooks

## Help
help: ## List of all commands
	@grep -E '(^[a-zA-Z_0-9-]+:.*?##.*$$)|(^##)' Makefile \
	| awk 'BEGIN {FS = ":.*?## "}; {printf "${G}%-24s${NC} %s\n", $$1, $$2}' \
	| sed -e 's/\[32m## /[33m/' && printf "\n";

.DEFAULT_GOAL := help
.PHONY: help
