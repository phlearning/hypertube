COMPOSE = docker compose
APP     = $(COMPOSE) exec app
PHP    = php
HELP_SCRIPT = scripts/make-help.php
RM_SCRIPT = scripts/rm-dir.php

.PHONY: help up down build restart logs ps shell mysql-shell \
	install migrate migrate-fresh key-generate \
	test test-filter pint pint-check phpstan check \
	ping-worker fresh clean fclean user-admin user-validate

help:
	@$(PHP) $(HELP_SCRIPT) $(MAKEFILE_LIST)

up: ## Start the full stack in the background
	$(COMPOSE) up --build -d

down: ## Stop the stack
	$(COMPOSE) down

clean: ## Stop the stack and remove generated frontend artifacts
	$(PHP) $(RM_SCRIPT) public/build
	$(COMPOSE) down --remove-orphans

fclean: ## Remove containers, volumes, images, and generated frontend artifacts
	$(PHP) $(RM_SCRIPT) public/build
	$(COMPOSE) down --remove-orphans --volumes --rmi all

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

user-admin: ## Promote a user to admin (make user-admin username=john)
	$(APP) php artisan user:make-admin $(username)

user-validate: ## Mark a user as verified (make user-validate username=john)
	$(APP) php artisan user:verify $(username)

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
