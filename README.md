# RAG — Symfony AI

Système de RAG (Retrieval-Augmented Generation) permettant d'interroger en langage naturel une
base de connaissances constituée de documents PDF, Word, CSV et Excel. Voir le design complet dans
[docs/superpowers/specs/2026-07-16-rag-symfony-ai-design.md](docs/superpowers/specs/2026-07-16-rag-symfony-ai-design.md).

## Stack

- Symfony 8.1 + `symfony/ai-bundle` (OpenAI platform, store Postgres/pgvector)
- FrankenPHP (application) + PostgreSQL/pgvector (`docker compose`)
- `symfony/finder`, `symfony/serializer`, `symfony/validator`, `symfony/asset-mapper`
- Interface web en Stimulus + Bootstrap (sans build JS)

## Démarrage

1. Renseigner `OPENAI_API_KEY` dans `.env.local` (déjà fait si vous avez suivi la conversation).
2. Premier démarrage complet (build de l'image, démarrage de la stack, initialisation du store
   vectoriel) :

   ```bash
   make init
   ```

   Ou étape par étape :

   ```bash
   docker compose up -d
   docker compose exec app php bin/console ai:store:setup ai.store.postgres.default
   ```

3. L'application est accessible sur http://rag.localhost/ (ajoutez `127.0.0.1 rag.localhost` à
   votre fichier hosts si votre système ne résout pas `.localhost` automatiquement).

Un `Makefile` regroupe les commandes courantes (`make help` pour la liste complète) : `make up`,
`make down`, `make logs`, `make sh`, `make store-setup`, `make store-drop`, `make cache-clear`,
etc.

## Ingestion de documents

```bash
make ingest DIR=/chemin/vers/un/dossier
# ou
docker compose exec app php bin/console app:rag:ingest /chemin/vers/un/dossier
```

Scanne récursivement le dossier donné (monté dans le conteneur) à la recherche de fichiers
`.pdf`, `.docx`, `.csv` et `.xlsx`, les découpe en chunks et les indexe dans Postgres. Pour les
fichiers CSV/Excel, chaque ligne est convertie en texte `en-tête: valeur, en-tête: valeur, ...`
(par feuille pour Excel). Ré-exécuter la commande sur un dossier déjà ingéré remplace les chunks
existants pour chaque fichier (idempotent).

## Interroger la base

Deux implémentations sont disponibles, l'API et l'interface web utilisant la version **agent** :

- **RAG manuel** (`RagQueryService`) : flux déterministe — 1 recherche vectorielle fixe puis 1 appel
  de génération. Prévisible, 2 appels API par question.
  ```bash
  make ask Q="Votre question ?"
  # ou
  docker compose exec app php bin/console app:rag:ask "Votre question ?"
  ```
- **RAG agent** (`RagAgentQueryService`) : un Agent (`symfony/ai-agent`) décide lui-même s'il doit
  appeler l'outil `search_knowledge_base`, et combien de fois — utilisé par `POST /api/rag/ask` et
  l'interface web.
  ```bash
  make ask-agent Q="Votre question ?"
  # ou
  docker compose exec app php bin/console app:rag:ask-agent "Votre question ?"
  ```
- **API** : `POST /api/rag/ask` avec `{"question": "..."}`, répond `{"answer": "...", "sources": [...]}`
  (version agent).
- **Interface web** : formulaire sur http://rag.localhost/ (version agent, via l'API).

## Serveur MCP

Un serveur MCP (Model Context Protocol) expose le dossier de documents (`var/ebook`) à un client
MCP local (Claude Desktop, Claude Code, etc.), **indépendamment du RAG** — ces tools reflètent ce
qui est sur disque, pas ce qui est indexé en base :

- `list_documents` : liste les fichiers PDF/DOCX/CSV/XLSX présents dans `var/ebook` (nom, taille).
- `read_document` : extrait et retourne le contenu texte d'un fichier (paramètre optionnel
  `max_length`, défaut 20000 caractères, au-delà le contenu est tronqué).

Configurer votre client MCP pour lancer :

```bash
make mcp-server
# ou
docker compose exec -T app php bin/console mcp:server
```

(transport STDIO uniquement ; voir `config/packages/mcp.yaml`).

## Tests

```bash
make test
# ou
docker compose exec -e APP_ENV=test app php bin/phpunit
```

Comprend des tests unitaires (extraction PDF/DOCX), d'intégration (`RagQueryService` avec des
doubles de test `symfony/ai`) et fonctionnels (endpoint API).
