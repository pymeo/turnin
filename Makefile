SHELL := /bin/bash

DOCKER_COMPOSE := docker compose
APP_EXEC := $(DOCKER_COMPOSE) exec -T -e XDEBUG_MODE=off app
MIGRATION_VERSION := $(if $(VERSION),DoctrineMigrations\Version$(VERSION),latest)

.DEFAULT_GOAL := help

.PHONY: help setup up down restart shell logs ps \
	migrate migration db-reset \
	test test-unit test-integration test-functional test-architecture test-e2e \
	lint lint-fix static-analysis architecture audit qa \
	assets graph graph-check graph-map graph-viz

help: ## Muestra los comandos disponibles
	@awk 'BEGIN {FS = ":.*## "; printf "Turnin — uso: make <comando>\n\n"} \
		/^## / {printf "\n\033[1m%s\033[0m\n", substr($$0, 4); next} \
		/^[a-zA-Z_-]+:.*## / {printf "  \033[36m%-20s\033[0m %s\n", $$1, $$2}' $(MAKEFILE_LIST)
	@printf '\n'

## Entorno
setup: ## Levanta todo desde cero: imágenes, dependencias, base de datos y assets
	$(DOCKER_COMPOSE) build
	$(DOCKER_COMPOSE) up -d --wait postgres redis
	$(DOCKER_COMPOSE) up -d --wait app
	$(APP_EXEC) composer install --no-interaction
	$(APP_EXEC) php bin/console doctrine:database:create --if-not-exists --no-interaction
	$(APP_EXEC) php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
	$(MAKE) assets
	$(DOCKER_COMPOSE) up -d --wait
	@command -v npm >/dev/null && npm install --no-audit --no-fund || \
		printf '\nAviso: Node no está disponible; make test-e2e y make graph lo necesitan.\n'
	@printf '\n✅ Turnin en http://localhost:$${HTTP_PORT:-8080}\n'

up: ## Arranca los servicios
	$(DOCKER_COMPOSE) up -d --wait

down: ## Para los servicios (conserva los volúmenes)
	$(DOCKER_COMPOSE) down

restart: ## Reinicia los servicios
	$(DOCKER_COMPOSE) restart

shell: ## Abre una shell dentro del contenedor de aplicación
	$(DOCKER_COMPOSE) exec app bash

logs: ## Sigue los logs de todos los servicios
	$(DOCKER_COMPOSE) logs -f

ps: ## Estado de los servicios
	$(DOCKER_COMPOSE) ps

assets: ## Compila Tailwind y el asset map
	$(APP_EXEC) php bin/console tailwind:build --minify
	$(APP_EXEC) php bin/console asset-map:compile

## Base de datos
migrate: ## Aplica migraciones en dev; acepta VERSION=AAAAMMDDHHMMSS
	$(APP_EXEC) php bin/console doctrine:database:create --if-not-exists --no-interaction
	$(APP_EXEC) php bin/console doctrine:migrations:migrate '$(MIGRATION_VERSION)' --no-interaction --allow-no-migration

migration: ## Genera una migración a partir de las diferencias del mapeo
	$(APP_EXEC) php bin/console doctrine:migrations:diff --no-interaction

db-reset: ## Recrea la base de datos de desarrollo aplicando todas las migraciones
	$(APP_EXEC) php bin/console doctrine:database:drop --force --if-exists --no-interaction
	$(MAKE) migrate

## Tests
# Todas las suites que tocan base de datos pasan antes por composer test-reset-db,
# que SIEMPRE construye el esquema con migraciones reales. Ver docs/TESTING.md.
test: up ## Ejecuta toda la suite PHP (unit + architecture + integration + functional)
	$(APP_EXEC) composer test

test-unit: up ## Solo dominio: sin kernel, sin base de datos
	$(APP_EXEC) composer test-unit

test-integration: up ## Adapters reales contra PostgreSQL y Redis
	$(APP_EXEC) composer test-integration

test-functional: up ## Peticiones HTTP reales a través del kernel
	$(APP_EXEC) composer test-functional

test-architecture: up ## Reglas de dependencia entre capas y contextos
	$(APP_EXEC) composer test-architecture

test-e2e: up ## Playwright contra la aplicación levantada
	npx playwright test

## Calidad
lint: up ## Estilo de código (dry-run) y lint de YAML, Twig y contenedor
	$(APP_EXEC) composer cs-check
	$(APP_EXEC) composer lint

lint-fix: up ## Corrige el estilo de código
	$(APP_EXEC) composer cs-fix

static-analysis: up ## PHPStan
	$(APP_EXEC) composer stan

architecture: up ## Deptrac: Domain ← Application ← Infrastructure y límites de contexto
	$(APP_EXEC) composer architecture

audit: up ## Vulnerabilidades conocidas en dependencias
	$(APP_EXEC) composer audit

qa: up ## Puerta de calidad completa: composer validate + lint + PHPStan + deptrac + tests
	$(APP_EXEC) composer validate --strict
	$(MAKE) lint
	$(MAKE) static-analysis
	$(MAKE) architecture
	$(MAKE) test
	@printf '\n✅ make qa OK\n'

## Graft (grafo de contexto para agentes)
graph: ## Reconstruye el grafo del repositorio
	npx graft build

graph-check: ## Falla si el grafo está desactualizado respecto al código (CI)
	npx graft check

graph-map: ## Orientación rápida: clusters, hubs y hotspots
	npx graft map

graph-viz: ## Sirve la visualización interactiva del grafo
	npx graft viz
