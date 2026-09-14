# RAG — Symfony AI

Système de RAG (Retrieval-Augmented Generation) permettant d'interroger en langage naturel une
base de connaissances constituée de documents PDF, Word, CSV et Excel. Voir le design complet dans
[docs/superpowers/specs/2026-07-16-rag-symfony-ai-design.md](docs/superpowers/specs/2026-07-16-rag-symfony-ai-design.md).

## Stack

- Symfony 8.1 + `symfony/ai-bundle` (OpenAI platform, store Postgres/pgvector)
- FrankenPHP (application) + PostgreSQL/pgvector (`docker compose`)
- Interface web en Stimulus + Bootstrap (sans build JS)

## Démarrage

1. Cloner le dépôt :

   ```bash
   git clone git@github.com:rhidja/symfony-ai-rag.git
   cd symfony-ai-rag
   ```

2. Copier `.env` en `.env.local` puis renseigner `OPENAI_API_KEY` (clé d'API OpenAI, nécessaire
   pour l'ingestion et les requêtes) :

   ```bash
   cp .env .env.local
   ```

3. Premier démarrage complet (build de l'image, démarrage de la stack, initialisation du store
   vectoriel) :

   ```bash
   make init
   ```

   Ou étape par étape :

   ```bash
   docker compose up -d
   docker compose exec app php bin/console ai:store:setup ai.store.postgres.default
   ```

4. L'application est accessible sur http://rag.localhost/ (ajoutez `127.0.0.1 rag.localhost` à
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

Les PDF scannés (pages sans texte intégré) sont pris en charge : pour chaque page où
`smalot/pdfparser` ne trouve aucun texte, `PdfLoader` la rasterise (`pdftoppm`) et lui applique
une OCR Tesseract (`fra+eng`) — les PDF mixtes (texte + pages scannées) sont donc également gérés.
Nécessite les paquets `poppler-utils` et `tesseract-ocr` (+ `tesseract-ocr-fra`,
`tesseract-ocr-eng`), déjà installés dans l'image Docker de l'app.

L'OCR est le poste le plus lent de l'ingestion (rasterisation + reconnaissance par page). Deux
optimisations dans `PdfPageOcrExtractor` limitent son coût :

- **Pool parallèle** : plusieurs pages sont traitées simultanément (4 par défaut) au lieu d'une
  par une, chaque page étant un subprocess `pdftoppm && tesseract` indépendant.
- **Cache par page** (Symfony Cache, pool `cache.app`) : le résultat OCR d'une page est mis en
  cache (clé = chemin + date de modification + taille du fichier + page + résolution + langues).
  Réingérer un fichier déjà traité et inchangé ne relance donc aucun OCR — sur un livre scanné de
  210 pages, une première ingestion à froid (~15-20 min) redescend à quelques secondes une fois
  en cache.

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

## Tests

```bash
make test
# ou
docker compose exec -e APP_ENV=test app php bin/phpunit
```

Comprend des tests unitaires (extraction PDF/DOCX), d'intégration (`RagQueryService` avec des
doubles de test `symfony/ai`) et fonctionnels (endpoint API).
