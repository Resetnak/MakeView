# Makeview development tasks.
#
# Composer and PHPUnit run inside Docker via bin/dev: the host PHP is missing
# the dom/xml/mbstring extensions they require.
.DEFAULT_GOAL := help
.PHONY: help install test coverage up down logs build shell

help: ## Show this help
	@grep -hE '^[a-zA-Z_-]+:.*?## ' $(MAKEFILE_LIST) \
		| awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-10s\033[0m %s\n", $$1, $$2}'

install: ## Install Composer dependencies (dev included)
	./bin/dev composer install

test: ## Run the PHPUnit suite
	./bin/dev vendor/bin/phpunit

coverage: ## Run the suite with a coverage summary
	./bin/dev vendor/bin/phpunit --coverage-text

up: ## Start the dashboard on http://localhost:9898
	docker compose up -d --build

down: ## Stop the dashboard
	docker compose down

logs: ## Follow the dashboard logs
	docker compose logs -f

build: ## Build the production image
	docker compose build

shell: ## Open a shell in the development container
	./bin/dev sh
