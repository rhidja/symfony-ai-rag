# RAG — Symfony AI

Système de RAG (Retrieval-Augmented Generation) permettant d'interroger en langage naturel une
base de connaissances constituée de documents PDF, Word, CSV et Excel. Voir le design complet dans
[docs/superpowers/specs/2026-07-16-rag-symfony-ai-design.md](docs/superpowers/specs/2026-07-16-rag-symfony-ai-design.md).

Toutes les commandes ci-dessous passent par le `Makefile` à la racine du projet — c'est l'interface
courante pour piloter le projet ; `make help` en affiche la liste complète à tout moment.

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
   vectoriel, ingestion des documents présents dans `var/ebook`) :

   ```bash
   make init
   ```

4. L'application est accessible sur http://rag.localhost/ (ajoutez `127.0.0.1 rag.localhost` à
   votre fichier hosts si votre système ne résout pas `.localhost` automatiquement).

## Commandes disponibles

| Commande | Description |
| --- | --- |
| `make help` | Affiche cette aide |
| `make init` | Premier démarrage complet (build + up + store-setup + ingestion) |
| `make up` | Démarre la stack (détaché) |
| `make down` | Arrête et supprime les conteneurs |
| `make start` | Alias de `up` |
| `make stop` | Arrête les conteneurs sans les supprimer |
| `make restart` | Redémarre la stack |
| `make build` | (Re)construit les images |
| `make logs` | Suit les logs de la stack |
| `make sh` | Ouvre un shell dans le conteneur app |
| `make install` | Installe les dépendances Composer |
| `make store-setup` | Initialise le store vectoriel Postgres/pgvector |
| `make store-drop` | Supprime le store vectoriel |
| `make ingest DIR=/chemin` | Ingère un dossier de documents |
| `make ask Q="..."` | Interroge le RAG manuel |
| `make ask-agent Q="..."` | Interroge le RAG agent |
| `make test` | Lance la suite de tests PHPUnit |
| `make cache-clear` | Vide le cache Symfony |

Après toute modification du Dockerfile (ex. nouvelles dépendances système), reconstruire l'image
avant de continuer :

```bash
make build && make up
```

## Ingestion de documents

```bash
make ingest DIR=/chemin/vers/un/dossier
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
  ```
- **RAG agent** (`RagAgentQueryService`) : un Agent (`symfony/ai-agent`) décide lui-même s'il doit
  appeler l'outil `search_knowledge_base`, et combien de fois — utilisé par `POST /api/rag/ask` et
  l'interface web. Son prompt système vit dans
  [config/prompts/rag_agent_system_prompt.md](config/prompts/rag_agent_system_prompt.md).
  ```bash
  make ask-agent Q="Votre question ?"
  ```
- **API** : `POST /api/rag/ask` avec `{"question": "..."}`, répond `{"answer": "...", "sources": [...]}`
  (version agent).
- **Interface web** : formulaire sur http://rag.localhost/ (version agent, via l'API).

## Tests

```bash
make test
```

Comprend des tests unitaires (extraction PDF/DOCX/CSV/XLSX, OCR), d'intégration (`RagQueryService`
avec des doubles de test `symfony/ai`) et fonctionnels (endpoint API).
