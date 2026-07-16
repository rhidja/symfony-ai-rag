# RAG — Symfony AI

Système de RAG (Retrieval-Augmented Generation) permettant d'interroger en langage naturel une
base de connaissances constituée de documents PDF et Word. Voir le design complet dans
[docs/superpowers/specs/2026-07-16-rag-symfony-ai-design.md](docs/superpowers/specs/2026-07-16-rag-symfony-ai-design.md).

## Stack

- Symfony 8.1 + `symfony/ai-bundle` (OpenAI platform, store Postgres/pgvector)
- FrankenPHP (application) + PostgreSQL/pgvector (`docker compose`)
- `symfony/finder`, `symfony/serializer`, `symfony/validator`, `symfony/asset-mapper`
- Interface web en Stimulus + Bootstrap (sans build JS)

## Démarrage

1. Renseigner `OPENAI_API_KEY` dans `.env.local` (déjà fait si vous avez suivi la conversation).
2. Démarrer la stack :

   ```bash
   docker compose up -d
   ```

3. Initialiser le store vectoriel (à faire une seule fois, ou après un `ai:store:drop`) :

   ```bash
   docker compose exec app php bin/console ai:store:setup ai.store.postgres.default
   ```

4. L'application est accessible sur http://rag.localhost/ (ajoutez `127.0.0.1 rag.localhost` à
   votre fichier hosts si votre système ne résout pas `.localhost` automatiquement).

## Ingestion de documents

```bash
docker compose exec app php bin/console app:rag:ingest /chemin/vers/un/dossier
```

Scanne récursivement le dossier donné (monté dans le conteneur) à la recherche de fichiers
`.pdf` et `.docx`, les découpe en chunks et les indexe dans Postgres. Ré-exécuter la commande sur
un dossier déjà ingéré remplace les chunks existants pour chaque fichier (idempotent).

## Interroger la base

- **CLI** :
  ```bash
  docker compose exec app php bin/console app:rag:ask "Votre question ?"
  ```
- **API** : `POST /api/rag/ask` avec `{"question": "..."}`, répond `{"answer": "...", "sources": [...]}`.
- **Interface web** : formulaire sur http://rag.localhost/.

## Serveur MCP

Un serveur MCP (Model Context Protocol) expose deux tools pour un client MCP local (Claude Desktop,
Claude Code, etc.) :

- `list_indexed_documents` : liste les documents indexés avec le nombre de chunks pour chacun.
- `ask_knowledge_base` : pose une question au RAG et retourne la réponse + les sources.

Configurer votre client MCP pour lancer :

```bash
docker compose exec -T app php bin/console mcp:server
```

(transport STDIO uniquement ; voir `config/packages/mcp.yaml`).

## Tests

```bash
docker compose exec -e APP_ENV=test app php bin/phpunit
```

Comprend des tests unitaires (extraction PDF/DOCX), d'intégration (`RagQueryService` avec des
doubles de test `symfony/ai`) et fonctionnels (endpoint API).
