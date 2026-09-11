COMPOSE = docker compose
APP     = $(COMPOSE) exec app

.PHONY: help up down build restart logs ps shell mysql-shell \
        install migrate migrate-fresh key-generate \
        test test-filter pint pint-check phpstan check \
        ping-worker fresh

help:
	@grep -E '^[a-zA-Z0-9_-]+:.*?## .*$$' $(MAKEFILE_LIST) | sort | awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-16s\033[0m %s\n", $$1, $$2}'

up: ## Start the full stack in the background
	$(COMPOSE) up -d

down: ## Stop the stack
	$(COMPOSE) down

build: ## Rebuild all images
	$(COMPOSE) build

restart: down up ## Restart the stack

logs: ## Follow logs for all services (make logs s=app for one service)
	$(COMPOSE) logs -f $(s)

ps: ## Show service status
	$(COMPOSE) ps

shell: ## Open a shell in the app container
	$(APP) sh

mysql-shell: ## Open a MySQL shell
	$(COMPOSE) exec mysql sh -c 'mysql -u"$$MYSQL_USER" -p"$$MYSQL_PASSWORD" "$$MYSQL_DATABASE"'

install: ## composer install inside the app container
	$(APP) composer install

migrate: ## Run pending migrations
	$(APP) php artisan migrate

migrate-fresh: ## Drop all tables and re-run migrations
	$(APP) php artisan migrate:fresh

key-generate: ## Generate the app key
	$(APP) php artisan key:generate

test: ## Run the full Pest suite
	$(APP) php artisan test --compact

test-filter: ## Run tests matching a name (make test-filter f=SomeTest)
	$(APP) php artisan test --compact --filter=$(f)

pint: ## Fix code style on changed files
	$(APP) vendor/bin/pint --dirty

pint-check: ## Check code style without fixing
	$(APP) vendor/bin/pint --test

phpstan: ## Run static analysis
	$(APP) vendor/bin/phpstan analyse --memory-limit=512M

check: pint-check phpstan test ## Run style, static analysis, and tests

ping-worker: ## Dispatch a dummy ping job to the torrent worker
	$(APP) php artisan torrent:ping-worker

fresh: down build up migrate ## Rebuild everything and start clean
