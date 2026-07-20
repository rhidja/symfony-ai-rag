.DEFAULT_GOAL := help
COMPOSE = docker compose
EXEC    = $(COMPOSE) exec app
CONSOLE = $(EXEC) php bin/console

.PHONY: help init up down start stop restart build logs sh \
        install store-setup store-drop ingest ask ask-agent \
        test cache-clear mcp-server

help: ## Affiche cette aide
	@grep -E '^[a-zA-Z0-9_-]+:.*?## .*$$' $(MAKEFILE_LIST) | sort | awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-15s\033[0m %s\n", $$1, $$2}'

init: build up store-setup ## Premier démarrage complet (build + up + store-setup)
	@echo "Projet prêt sur http://rag.localhost/"

up: ## Démarre la stack (détaché)
	$(COMPOSE) up -d

down: ## Arrête et supprime les conteneurs
	$(COMPOSE) down

start: up ## Alias de up

stop: ## Arrête les conteneurs sans les supprimer
	$(COMPOSE) stop

restart: stop up ## Redémarre la stack

build: ## (Re)construit les images
	$(COMPOSE) build

logs: ## Suit les logs de la stack
	$(COMPOSE) logs -f

sh: ## Ouvre un shell dans le conteneur app
	$(EXEC) sh

install: ## Installe les dépendances Composer
	$(EXEC) composer install

store-setup: ## Initialise le store vectoriel Postgres/pgvector
	$(CONSOLE) ai:store:setup ai.store.postgres.default

store-drop: ## Supprime le store vectoriel
	$(CONSOLE) ai:store:drop ai.store.postgres.default

ingest: ## Ingère un dossier de documents (make ingest DIR=/chemin)
	$(CONSOLE) app:rag:ingest $(DIR)

ask: ## Interroge le RAG manuel (make ask Q="Votre question ?")
	$(CONSOLE) app:rag:ask "$(Q)"

ask-agent: ## Interroge le RAG agent (make ask-agent Q="Votre question ?")
	$(CONSOLE) app:rag:ask-agent "$(Q)"

test: ## Lance la suite de tests PHPUnit
	$(COMPOSE) exec -e APP_ENV=test app php bin/phpunit

cache-clear: ## Vide le cache Symfony
	$(CONSOLE) cache:clear

mcp-server: ## Lance le serveur MCP (STDIO)
	$(COMPOSE) exec -T app php bin/console mcp:server
