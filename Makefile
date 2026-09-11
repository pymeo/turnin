SHELL := /bin/bash

DOCKER := docker
DOCKER_COMPOSE := $(DOCKER) compose
APP_EXEC := $(DOCKER_COMPOSE) exec -T -e XDEBUG_MODE=off app
MIGRATION_VERSION := $(if $(VERSION),DoctrineMigrations\Version$(VERSION),latest)

# Playwright runs in its own container; keep the tag in step with the
# @playwright/test version in package.json or the browsers will not match.
PLAYWRIGHT_IMAGE := mcr.microsoft.com/playwright:v1.63.0-noble
HOST_UID := $(shell id -u)
HOST_GID := $(shell id -g)

# Read the published port from .env so messages and the E2E base URL agree with
# what compose actually binds.
HTTP_PORT := $(shell awk -F= '$$1 == "HTTP_PORT" { print $$2 }' .env 2>/dev/null)
HTTP_PORT := $(if $(HTTP_PORT),$(HTTP_PORT),8080)

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
	@printf '\n✅ Turnin en http://localhost:$(HTTP_PORT)\n'

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
	$(MAKE) assets
	@# Playwright corre en su imagen oficial, no en el host: trae los navegadores
	@# y todas sus librerías de sistema, de modo que local y CI ejecutan lo mismo.
	@#
	@# --network host, y no la red de compose, por un motivo concreto: los service
	@# workers solo existen en un contexto seguro. http://web no lo es y el
	@# navegador ni siquiera expone navigator.serviceWorker; http://127.0.0.1 sí,
	@# porque localhost cuenta como seguro. Sin esto no se puede probar la PWA.
	$(DOCKER) run --rm --init --ipc=host \
		--network host \
		--user $(HOST_UID):$(HOST_GID) \
		-e HOME=/tmp \
		-e E2E_BASE_URL=http://127.0.0.1:$(HTTP_PORT) \
		-e CI=$(CI) \
		-v "$(CURDIR):/work" -w /work \
		$(PLAYWRIGHT_IMAGE) npx playwright test $(E2E_ARGS)

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

qa: up ## Puerta de calidad completa: assets + composer validate + lint + PHPStan + deptrac + tests
	@# Los assets van primero y no por comodidad: `public/assets/` es salida
	@# compilada y Symfony la sirve mientras exista, así que un cambio en
	@# app.css o en una plantilla puede tener toda la suite en verde y la
	@# pantalla sin actualizar. Recompilar aquí cuesta segundos y cierra ese
	@# hueco. Ver docs/DEVELOPMENT.md § Problemas conocidos.
	$(MAKE) assets
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
