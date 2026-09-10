# Turnin

**Tus turnos. Tu tiempo.**

Turnin encuentra automáticamente compañeros compatibles con los que resolver un
cambio de turno. No es un calendario ni un tablón de anuncios: la idea es que
digas «quiero librar el sábado 19» y el sistema busque por ti, en vez de que
persigas a quince personas por WhatsApp.

Empieza por sanidad en España —enfermería, TCAE, celadores, medicina, técnicos,
urgencias— y se extenderá a otros sectores por turnos.

> **Estado: primeras slices.** La base técnica y el catálogo oficial de centros
> sanitarios públicos están completos y probados. Workforce ya tiene categorías,
> unidades locales, asignaciones y pools; Identity dispone de contraseña, Google
> OAuth, sesión y onboarding autenticado. Lo que viene, en orden, está en
> [docs/ROADMAP.md](docs/ROADMAP.md).

## Arrancar

```bash
git clone https://github.com/pymeo/turnin.git
cd turnin
make setup
```

Y ya está en <http://localhost:8080>.

Para probar Google en desarrollo, crea `.env.local` con
`GOOGLE_OAUTH_CLIENT_ID` y `GOOGLE_OAUTH_CLIENT_SECRET`, y registra
`https://dev.turnin.es/auth/google/callback` en Google Cloud. La configuración
completa está en [docs/authentication.md](docs/authentication.md).

`make setup` construye las imágenes, levanta los servicios, instala dependencias,
crea la base de datos, aplica migraciones y compila los assets. Es idempotente.

### Requisitos

* **Docker** y **Docker Compose v2** — no necesitas PHP ni Composer instalados.
* **Node 20+** en el host, solo para Graft y para lanzar Playwright.
* **make**.

### URLs

| | |
| --- | --- |
| Aplicación | <http://localhost:8080> |
| Salud | <http://localhost:8080/health> |
| Profiler | <http://localhost:8080/_profiler/latest> |

## Comandos

`make help` los lista todos con su descripción. Los que más se usan:

```bash
make up / down / restart / logs / shell

make migrate                 # aplica migraciones
make migration               # genera una migración desde el mapeo

make test                    # toda la suite PHP
make test-unit               # solo dominio, rapidísimo
make test-e2e                # Playwright en móvil pequeño, móvil y escritorio

make lint                    # estilo + lint de YAML, Twig y contenedor
make static-analysis         # PHPStan (nivel max)
make architecture            # Deptrac: capas y contextos
make qa                      # todo lo anterior. Si esto falla, no se mergea.

make graph                   # reconstruye el grafo de Graft
make graph-map               # orientación rápida del repositorio
```

## Migraciones

**El esquema de test se construye siempre ejecutando las migraciones reales**,
nunca `doctrine:schema:create`. `make test` recrea la base de test aplicando toda
la historia de migraciones antes de lanzar PHPUnit, y hay un test dedicado
(`MigrationsFromScratchTest`) que comprueba que una base vacía llega al esquema
actual solo con migraciones.

El motivo es simple: producción es el único sitio donde el esquema se construye
así. Si los tests usan otro camino, una migración rota no se descubre hasta que
alguien levanta un entorno nuevo. Detalles en [docs/TESTING.md](docs/TESTING.md).

## Estructura

```
src/<Contexto>/{Domain,Application,Infrastructure}/
```

`Domain` es PHP puro: sin Symfony, sin Doctrine, sin Twig. La regla de
dependencias es `Domain ← Application ← Infrastructure` y la comprueba Deptrac,
no la memoria de quien revisa.

Existen `Platform\System` (salud), `Platform\Web` (landing) y `Workforce`
(catálogo oficial de `Workplace`). Los módulos técnicos de Platform conservan un
nivel adicional. Los contextos se crean cuando se implementan; el mapa completo
está documentado en [docs/CONTEXT_MAP.md](docs/CONTEXT_MAP.md).

```
assets/     JS y el sistema de diseño Tailwind
config/     configuración de Symfony
docker/     Caddy y PHP
docs/       toda la documentación
migrations/ migraciones de Doctrine
public/     front controller, manifest, service worker, iconos
templates/  Twig
tests/      Unit, Architecture, Integration, Functional, E2E
```

## Stack

PHP 8.5 · Symfony 7.4 LTS · PostgreSQL 18 · Redis 8 · Caddy 2.10 · Twig ·
Tailwind v4 · Stimulus · Docker · PHPUnit 13 · PHPStan (max) · Deptrac 4 ·
php-cs-fixer · Playwright · Graft.

## Documentación

| | |
| --- | --- |
| [PRODUCT.md](docs/PRODUCT.md) | Qué construimos y por qué. Monetización. |
| [ARCHITECTURE.md](docs/ARCHITECTURE.md) | Capas, CQRS, persistencia, convenciones. |
| [DOMAIN.md](docs/DOMAIN.md) | Vocabulario, agregados, invariantes, tiempo. |
| [CONTEXT_MAP.md](docs/CONTEXT_MAP.md) | Contextos y cómo se relacionan. |
| [TESTING.md](docs/TESTING.md) | Suites y la regla de las migraciones. |
| [DEVELOPMENT.md](docs/DEVELOPMENT.md) | Día a día. |
| [DEPLOYMENT.md](docs/DEPLOYMENT.md) | Imagen de producción y despliegue. |
| [SECURITY.md](docs/SECURITY.md) | Amenazas, privacidad, caché offline. |
| [authentication.md](docs/authentication.md) | Contraseña, Google OAuth, callbacks y secretos. |
| [GRAPH.md](docs/GRAPH.md) | Graft. |
| [ROADMAP.md](docs/ROADMAP.md) | Qué viene y qué deuda hay. |
| [DECISIONS.md](docs/DECISIONS.md) | Decisiones menores, con su motivo. |
| [adr/](docs/adr/) | Decisiones arquitectónicas. |

Si vas a trabajar aquí con un agente, empieza por [AGENTS.md](AGENTS.md).
