# CLAUDE.md

Las instrucciones de este repositorio están en **[AGENTS.md](AGENTS.md)**. Léelo
entero antes de trabajar aquí; no se duplica su contenido en este fichero para
que no puedan divergir.

Lo mínimo imprescindible, por si solo lees esto:

* **Consulta Graft antes de leer ficheros.** `npx graft ask "…" --source`,
  `npx graft grep "<símbolo>"`, `npx graft callers <símbolo> --depth 2`.
* **`Domain ← Application ← Infrastructure`.** `Domain` es PHP puro: sin Symfony,
  sin Doctrine. Lo comprueba `make architecture`.
* **El esquema de test se construye con migraciones reales.** Nunca
  `doctrine:schema:create`.
* **Ejecuta `make qa` antes de dar nada por terminado.** Si falla, no está hecho.
* **Actualiza `docs/` cuando cambies arquitectura, dominio o producto.**
* **Todo corre en Docker.** No instales PHP en el host.

Contexto del producto: [README.md](README.md) y
[docs/PRODUCT.md](docs/PRODUCT.md).
