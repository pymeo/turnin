# 1. DDD por contextos con Clean Architecture

* **Estado**: aceptada
* **Fecha**: 2026-09-10

## Contexto

Turnin empieza vacío pero tiene un dominio complejo por delante: compatibilidad
entre trabajadores, matching sobre calendarios, cambios encadenados, deudas de
turnos. La estructura por defecto de Symfony (`src/Entity`, `src/Repository`,
`src/Service`, `src/Controller`) funciona hasta las ~20 clases y a partir de ahí
obliga a abrir cuatro directorios para entender una funcionalidad.

El riesgo opuesto es igual de real: montar DDD «completo» sobre un proyecto sin
dominio produce cientos de ficheros vacíos y una capa de indirección que nadie
puede justificar.

## Decisión

Estructura por **producto / contexto / capa**:

```
src/<Producto>/<Contexto>/{Domain,Application,Infrastructure}/
```

con la regla `Domain ← Application ← Infrastructure`, comprobada por Deptrac.

`Domain` es PHP puro: puede usar el lenguaje y las interfaces PSR, nada más.

Y una restricción sobre cuánto de esto se construye: **un contexto se crea cuando
se implementa**. Hoy existen dos, `Platform\System` y `Platform\Web`, porque hoy
hay dos cosas. El mapa completo está documentado, no creado.

## Alternativas

**Estructura Symfony plana.** Menos ceremonia al principio. Descartada porque el
coste aparece justo cuando el proyecto ya es grande y moverlo es caro.

**Contextos con las tres capas creadas por adelantado.** Descartada: directorios
vacíos no son diseño. Nadie sabe cuáles están vivos y la primera funcionalidad
acaba en el sitio equivocado porque «ya había una carpeta».

**Un contexto por concepto**, como en el planteamiento inicial (Organization,
Workplace, SwapPool, Membership separados). Descartada tras analizarlos: son
agregados del mismo contexto, con invariantes cruzadas. El razonamiento completo
está en [CONTEXT_MAP.md](../CONTEXT_MAP.md#por-qué-esta-agrupación-y-no-la-lista-larga).

## Consecuencias

* Una funcionalidad se lee, y se borra, en un solo sitio.
* La lógica de negocio se testea sin contenedor ni base de datos.
* Hay que mantener dos configuraciones de Deptrac, porque los dos ejes (capas y
  contextos) se estorban en un solo fichero.
* Los mapeos de Doctrine van en XML, no en atributos: es el precio de que
  `Domain → Doctrine` sea una prohibición real (ver [ADR 3](0003-postgresql.md)).
