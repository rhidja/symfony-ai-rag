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

5. [Adminer](https://www.adminer.org/) (interface web pour inspecter la base Postgres) est
   disponible sur http://localhost:8080/ — serveur pré-rempli à `database`, utilisateur/mot de
   passe/base définis par `POSTGRES_USER`/`POSTGRES_PASSWORD`/`POSTGRES_DB` (par défaut
   `app`/`!ChangeMe!`/`app`).

Un `Makefile` regroupe toutes les commandes courantes du projet (stack, ingestion, interrogation,
tests...) — lancez `make help` pour la liste complète et à jour.

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
- **Interface web** : interface de chat sur http://rag.localhost/ (version agent, via l'API).

### Historique des conversations

Chaque question posée via `POST /api/rag/ask` est enregistrée en base (table `chat_message`,
Doctrine) et associée à une session anonyme identifiée par cookie (session Symfony) — pas de
compte utilisateur. L'historique est donc conservé pour un même visiteur/navigateur d'une visite à
l'autre.

- `GET /api/rag/history` : renvoie l'historique de la session courante
  (`[{"question", "answer", "sources", "createdAt"}, ...]`), utilisé par l'interface de chat pour
  réafficher la conversation au chargement de la page.
- `DELETE /api/rag/history` : efface l'historique de la session courante (bouton "Nouvelle
  conversation" de l'interface).

Chaque question reste traitée indépendamment par le RAG (pas de mémoire conversationnelle
ré-injectée dans le prompt) : l'historique sert à consulter les échanges passés, pas à répondre à
des questions de suivi implicites.

La table est créée/mise à jour via `make schema-update` (inclus dans `make init`).

## Quiz

Interface de chat sur http://rag.localhost/quiz permettant de s'entraîner sur un document déjà
indexé, plutôt que de seulement l'interroger. Voir le design complet dans
[2026-09-14-rag-quiz-mode-design.md](docs/superpowers/specs/2026-09-14-rag-quiz-mode-design.md).

- **Démarrage** : choisir un document dans la liste déroulante (les documents déjà ingérés), puis
  "Démarrer le quiz" — l'IA génère `app.rag.quiz_question_count` questions (5 par défaut) à choix
  multiples à partir d'un échantillon de chunks du document (`QuizGenerator`, sortie structurée
  `symfony/ai-platform`, un seul appel au modèle de chat).
- **Réponse** : une question à la fois (mono ou multi-choix selon ce que l'IA a déterminé),
  correction et explication sourcée immédiates avant de passer à la suivante. Les bonnes réponses ne
  sont jamais envoyées au client avant d'avoir répondu à la question — l'état visible côté
  navigateur ne contient que l'identifiant de la tentative en cours et le retour de la dernière
  réponse.
- **Récapitulatif et historique** : score final à la fin du quiz ; les tentatives (en cours ou
  terminées) de la session apparaissent dans une liste d'historique, comme pour le chat.
- **Interface** : un unique composant Symfony UX Live Component (`QuizComponent`) gère tout le cycle
  côté serveur (sélection → question → feedback → récap), sans JavaScript applicatif à écrire.
- Son prompt système de génération vit dans
  [config/prompts/quiz_generation_system_prompt.md](config/prompts/quiz_generation_system_prompt.md).

## Feuille de route — application d'apprentissage

Au-delà de l'interrogation de la base, le projet évolue vers une application d'**apprentissage** :
s'entraîner sur le contenu indexé, pas seulement l'interroger. Le périmètre étant large, il est
découpé en phases livrées séparément, chacune avec sa propre spec dans
[docs/superpowers/specs/](docs/superpowers/specs/) :

1. **Quiz sur un document entier** ✅ livré — QCM généré par l'IA à partir d'un document choisi,
   correction immédiate et sourcée, historique des scores par session. Voir
   [2026-09-14-rag-quiz-mode-design.md](docs/superpowers/specs/2026-09-14-rag-quiz-mode-design.md)
   et son [plan d'implémentation](docs/superpowers/plans/2026-09-14-rag-quiz-mode-plan.md).
2. **Sélection par chapitre/section** — cibler le quiz sur une partie précise d'un document ;
   nécessite d'extraire une structure (titres/chapitres) à l'ingestion, pas encore fait aujourd'hui.
3. **Exercices en texte libre** — question ouverte, réponse tapée, correction/feedback par l'IA.
4. **Flashcards** — cartes recto/verso générées à partir des documents, auto-évaluation.
5. **Upload d'une solution manuscrite** — photo d'une réponse écrite à la main, passée à l'OCR
   (réutilise `PdfPageOcrExtractor`/Tesseract), puis corrigée comme un exercice en texte libre.
6. **PWA** — manifest, service worker, installabilité ; transverse à toute l'application (chat +
   quiz + futurs modes), traité indépendamment des modes d'entraînement eux-mêmes.

Chaque phase est conçue et validée avant d'être implémentée ; cette liste reflète l'intention
discutée, pas un engagement de calendrier.

## Tests

```bash
make test
```

Comprend des tests unitaires (extraction PDF/DOCX/CSV/XLSX, OCR, entités), d'intégration
(`RagQueryService`, `QuizGenerator`, `QuizAttemptService` avec des doubles de test `symfony/ai`) et
fonctionnels (endpoints `/api/rag/ask`, `/api/rag/history`, et le `QuizComponent` via
`InteractsWithLiveComponents`).
