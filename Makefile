# Raccourcis pour la stack Docker (voir README.md)
DOCKER_COMP = docker compose
PHP_CONT    = $(DOCKER_COMP) exec php
PHP         = $(PHP_CONT) php
COMPOSER    = $(PHP_CONT) composer
SYMFONY     = $(PHP) bin/console

.DEFAULT_GOAL := help
.PHONY: help build up start down logs sh composer sf cc db-migrate db-diff test test-db front-install front-build front-logs npm ranger ranger-apply backup

help: ## Liste des commandes
	@grep -E '(^[a-zA-Z0-9_-]+:.*?##.*$$)|(^##)' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}{printf "\033[32m%-16s\033[0m %s\n", $$1, $$2}' | sed -e 's/\[32m##/[33m/'

## —— Docker ———————————————————————————————————————————
build: ## Construit les images
	@$(DOCKER_COMP) build --pull

up: ## Démarre la stack (attend qu'elle soit saine)
	@$(DOCKER_COMP) up --detach --wait

start: build up ## build + up

down: ## Arrête la stack
	@$(DOCKER_COMP) down --remove-orphans

logs: ## Logs en continu
	@$(DOCKER_COMP) logs --tail=50 --follow

sh: ## Shell dans le conteneur PHP
	@$(PHP_CONT) bash

## —— Symfony / Composer ———————————————————————————————
composer: ## Composer, ex : make composer c='require foo/bar'
	@$(COMPOSER) $(c)

sf: ## Console Symfony, ex : make sf c='debug:router'
	@$(SYMFONY) $(c)

cc: ## Vide le cache
	@$(SYMFONY) cache:clear

## —— Base de données ——————————————————————————————————
db-migrate: ## Joue les migrations
	@$(SYMFONY) doctrine:migrations:migrate --no-interaction

db-diff: ## Génère une migration depuis les entités
	@$(SYMFONY) doctrine:migrations:diff --no-interaction

test-db: ## (Re)crée la base de test
	@$(SYMFONY) doctrine:database:drop --env=test --force --if-exists
	@$(SYMFONY) doctrine:database:create --env=test
	@$(SYMFONY) doctrine:migrations:migrate --env=test --no-interaction

## —— Front (Vue + Vite, conteneur node) ——————————————
front-install: ## npm install
	@$(DOCKER_COMP) exec node npm install --no-fund --no-audit

front-build: ## Build de production dans public/build
	@$(DOCKER_COMP) run --rm --no-deps node npx vite build

front-logs: ## Logs du serveur Vite (dev)
	@$(DOCKER_COMP) logs --tail=50 --follow node

npm: ## npm, ex : make npm c='install foo'
	@$(DOCKER_COMP) exec node npm $(c)

## —— Qualité ——————————————————————————————————————————
test: ## Lance PHPUnit, ex : make test c='--filter Csv'
	@$(PHP_CONT) env APP_ENV=test php bin/phpunit $(c)

## —— Données ——————————————————————————————————————————
ranger: ## Simulation du rangement des fichiers de data/inbox/
	@python3 -I tools/ranger_releves.py

ranger-apply: ## Rangement réel des fichiers de data/inbox/
	@python3 -I tools/ranger_releves.py --apply

backup: ## Sauvegarde de la base dans data/backups/
	@mkdir -p data/backups
	@$(DOCKER_COMP) exec -T database sh -c 'pg_dump -U "$$POSTGRES_USER" "$$POSTGRES_DB"' | gzip > data/backups/banque-$$(date +%Y%m%d-%H%M%S).sql.gz
	@ls -1t data/backups | head -1
