# 3. PostgreSQL con Doctrine y mapeo XML

* **Estado**: aceptada
* **Fecha**: 2026-09-10

## Contexto

Turnin gira alrededor del tiempo: intervalos, solapes, días laborales, husos
horarios. Y necesita garantías fuertes de unicidad bajo concurrencia —dos personas
intentando quedarse con la misma oportunidad es el caso normal, no el excepcional.

Además, `Domain` no puede depender de Doctrine ([ADR 1](0001-architecture.md)), y
la forma habitual de mapear entidades en Symfony son atributos PHP **sobre las
clases de dominio**, que es exactamente esa dependencia.

## Decisión

**PostgreSQL 18** con Doctrine DBAL y ORM, y:

1. **Mapeo en XML**, desde `Infrastructure`, no atributos sobre el dominio.
   `auto_mapping: false`, con una entrada por contexto según aparezcan.
2. **`TIMESTAMPTZ` por defecto**: `datetime_immutable` se remapea a
   `DateTimeTzImmutableType` en
   [`doctrine.yaml`](../../config/packages/doctrine.yaml). Es el único tipo de
   PostgreSQL que guarda un instante sin ambigüedad.
3. **UUID v7** como identificadores, nunca autoincrement.

## Alternativas

**MySQL/MariaDB.** Descartada: tipos de intervalo y rango peores, y `TIMESTAMP`
con un rango que se queda corto. Los cuadrantes se planifican con meses de
antelación.

**Atributos de Doctrine en el dominio.** Es lo cómodo y lo que hace todo el mundo.
Descartada porque convierte la regla «Domain no depende de Doctrine» en un
comentario. El XML es más verboso; a cambio, Deptrac puede prohibirlo de verdad.

**UUID v4.** Descartada frente a v7: v7 es ordenable temporalmente, así que los
índices B-tree no se fragmentan al insertar.

**Enteros autoincrement.** Descartada: filtran volumen y permiten enumeración, y
en un producto sobre datos laborales privados eso importa.

## Consecuencias

* Escribir un agregado nuevo cuesta un fichero XML de más.
* El dominio se puede testear e incluso mover sin arrastrar el ORM.
* Los IDs se generan en la aplicación, así que un agregado tiene identidad antes
  de tocar la base de datos —cómodo para los eventos de dominio.
* PostgreSQL 18 cambió la ruta del cluster; el volumen monta
  `/var/lib/postgresql`, no `/var/lib/postgresql/data`.

## Nota sobre el ORM sin mapeos

Hoy el ORM está instalado y configurado con `mappings: []`, porque no hay
agregados. Podría no instalarse todavía, pero `make migration` usa
`doctrine:migrations:diff`, que necesita metadatos del ORM para comparar. Se queda,
y el primer agregado añade su bloque de mapeo.
