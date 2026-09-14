# Mode Quiz — Design

Date : 2026-09-14

## Contexte

Le projet est une application de RAG (Retrieval-Augmented Generation) permettant d'interroger en
langage naturel une base de connaissances (PDF, Word, CSV, Excel) — voir
[2026-07-16-rag-symfony-ai-design.md](2026-07-16-rag-symfony-ai-design.md) pour le design d'origine
et ses évolutions. L'application évolue vers une application d'**apprentissage** : au-delà de poser
des questions, l'utilisateur doit pouvoir s'entraîner sur le contenu indexé de différentes manières
(quiz, flashcards, exercices en texte libre, upload de solution manuscrite).

Ce périmètre étant trop large pour une seule spec, il est découpé en phases livrées séparément,
chacune avec son propre cycle spec → plan → implémentation :

1. **Quiz sur un document entier** (objet de ce document)
2. Sélection par chapitre/section (nécessite d'extraire une structure des documents à l'ingestion)
3. Exercices en texte libre avec correction par l'IA
4. Flashcards
5. Upload d'une solution manuscrite (photo → OCR → correction)
6. PWA (manifest, service worker, installabilité) — transverse à toute l'application, hors périmètre
   du quiz

## Objectif

Permettre à l'utilisateur de choisir un document déjà indexé et de s'entraîner dessus via un
questionnaire à choix multiples (QCM) généré par l'IA à partir du contenu réel du document, avec
correction immédiate, explication sourcée, et historique des tentatives passées.

## Vue d'ensemble de l'architecture

Le quiz réutilise le pipeline RAG existant plutôt que d'en créer un nouveau :

1. L'utilisateur choisit un document parmi ceux déjà indexés (table configurée via
   `app.rag.store_table`, `document_chunks` par défaut — gérée par `symfony/ai-postgres-store`).
2. Un échantillon de chunks de ce document est prélevé via une requête SQL directe sur cette table
   (pas de nouvel embedding nécessaire — le contenu est déjà vectorisé et stocké).
3. Un unique appel au modèle de chat (`gpt-4o-mini`, comme `RagQueryService`) génère les N questions
   en une fois, avec une **sortie structurée** : `symfony/ai-platform` convertit la réponse du modèle
   directement en objets PHP validés via un schéma, sans parsing JSON manuel fragile.
4. Les questions et leurs bonnes réponses sont stockées côté serveur (`QuizAttempt`) dès la
   génération. Le client ne reçoit jamais les bonnes réponses avant d'avoir répondu à chaque
   question.
5. Chaque réponse est évaluée côté serveur, avec feedback immédiat (correct/incorrect + explication
   + extrait source) avant de passer à la question suivante.
6. Une fois le quiz terminé, le score final est calculé et l'historique de la session s'enrichit.

### Composants principaux

- `symfony/ux-live-component` (+ `symfony/ux-twig-component`, sa dépendance) : nouveau, pour le
  frontend du quiz (voir section Frontend).
- Réutilisation de `ai.platform.openai` (le modèle de chat déjà configuré) et de la connexion DBAL
  existante (`doctrine.dbal.default_connection`, déjà utilisée par le store Postgres/pgvector).
- Aucun nouvel embedding, aucune nouvelle dépendance d'IA : le quiz s'appuie sur le contenu déjà
  ingéré et vectorisé par le pipeline existant.

### Amélioration ciblée incluse dans ce périmètre

`ChatSessionResolver` (introduit avec l'historique de chat) est renommé en `VisitorSessionResolver`
et déplacé de `Service/Rag/Chat/` vers `Service/Rag/Session/` : le quiz a besoin de la même notion
de session anonyme par visiteur que le chat. Garder deux résolveurs de session distincts (un pour le
chat, un pour le quiz) casserait la cohérence "une session = un visiteur" sur laquelle repose
l'historique unifié de l'application d'apprentissage. Le comportement ne change pas, seuls le nom de
la classe et son emplacement changent ; `ChatHistoryService` continue d'utiliser la clé de session
existante (aucune migration de données nécessaire, la valeur stockée en session reste la même).

## Modèle de données

Nouvelle entité Doctrine `QuizAttempt` (`src/Entity/Rag/QuizAttempt.php`), même approche que
`ChatMessage` : des colonnes JSON pour les structures imbriquées plutôt qu'un modèle relationnel
complet (pas de table `quiz_question` séparée — les questions d'une tentative ne sont jamais
interrogées indépendamment de leur tentative).

| Colonne | Type | Description |
| --- | --- | --- |
| `id` | string (uuid) | Identifiant de la tentative |
| `session_id` | string | Session anonyme (via `VisitorSessionResolver`) |
| `document_source` | string | Chemin/source du document choisi |
| `questions` | json | Liste des questions générées (voir structure ci-dessous) |
| `answers` | json | Réponses données au fur et à mesure (voir structure ci-dessous) |
| `score` | int, nullable | Nombre de questions correctes, rempli à la fin |
| `total_questions` | int | Nombre de questions du quiz |
| `created_at` | datetime | Date de démarrage |
| `completed_at` | datetime, nullable | Date de fin (toutes les questions répondues) |

Structure d'une question dans `questions` (JSON) :

```json
{
  "prompt": "Quelle est la configuration électronique du néon ?",
  "options": ["1s² 2s² 2p⁶", "1s² 2s²", "1s² 2s² 2p⁵", "1s² 2s² 2p⁶ 3s¹"],
  "correctIndices": [0],
  "multiple": false,
  "explanation": "Le néon (Z=10) a la configuration 1s² 2s² 2p⁶, une couche de valence complète.",
  "source": "/app/var/ebook/Chimie générale.pdf"
}
```

`multiple: true` autorise plusieurs `correctIndices` (question à cocher plusieurs réponses).

Structure d'une entrée dans `answers` (JSON, un élément ajouté par question répondue, dans l'ordre) :

```json
{ "questionIndex": 0, "selectedIndices": [0], "correct": true }
```

Un index `idx_quiz_attempt_session` sur `(session_id, created_at)`, comme pour `chat_message`.

## Génération des questions

Nouveau service `QuizGenerator` (`src/Service/Rag/Quiz/QuizGenerator.php`) :

1. **Échantillonnage** : requête SQL sur la table du store filtrée par `metadata->>'source'`,
   `ORDER BY random() LIMIT :sampleSize` avec `sampleSize = questionCount × 3` (borné à un minimum
   de 6, pour garder de la matière variée même quand `questionCount` est petit) — laisse au modèle
   de quoi générer des questions distinctes sans lui imposer un chunk par question.
2. **Génération** : un seul appel au modèle de chat avec :
   - un system prompt dédié (nouveau fichier `config/prompts/quiz_generation_system_prompt.md`,
     même principe que celui de l'agent RAG) demandant de générer exactement N questions à choix
     multiples fondées **exclusivement** sur les extraits fournis, de varier les questions faciles/
     difficiles, d'indiquer clairement `multiple` quand plusieurs réponses sont correctes, et de
     citer le chemin de document fourni comme `source`.
   - les chunks échantillonnés en contexte.
   - une sortie structurée (`symfony/ai-platform`) décrivant le schéma attendu (liste de questions
     avec les champs ci-dessus) : le modèle renvoie directement des objets PHP validés.
3. **Nombre de questions** : paramètre applicatif `app.rag.quiz_question_count` (défaut 5), comme
   `app.rag.top_k` aujourd'hui — pas un choix utilisateur à chaque lancement.
4. **Échec de génération** (sortie structurée invalide après retries de la bibliothèque, timeout,
   document trop court pour produire N questions distinctes) : `QuizGenerator` lève une exception
   dédiée, remontée par le `QuizComponent` sous forme de message d'erreur avec possibilité de
   relancer.

## Frontend (Symfony UX Live Components)

Nouvelle route `/quiz`, avec une petite barre de navigation ajoutée à `base.html.twig` pour basculer
entre "Chat" et "Quiz" (base pour accueillir les futurs modes flashcards/exercices). Le chat existant
n'est pas modifié dans son fonctionnement.

Un unique `QuizComponent` (Live Component, `src/Twig/Components/Rag/QuizComponent.php` +
`templates/components/Rag/QuizComponent.html.twig`) gère tout le cycle, avec un état interne
(propriétés `LiveProp`) représentant : le document choisi, l'ID de la tentative en cours, l'index de
la question affichée, le score courant.

1. **Sélection** : liste déroulante des documents indexés (une requête `SELECT DISTINCT` sur la
   table du store, exposée par une méthode du composant — pas d'endpoint HTTP séparé nécessaire
   grâce à Live Components).
2. **Démarrage** (`LiveAction`) : appelle `QuizGenerator`, persiste un `QuizAttempt`, affiche la
   question 1.
3. **Réponse** (`LiveAction`) : cases à cocher (checkbox si `multiple`, radio sinon) → soumission →
   le composant compare aux `correctIndices` stockés côté serveur, enregistre la réponse dans
   `QuizAttempt.answers`, affiche correct/incorrect + explication + source, bouton "Suivant".
4. **Fin** : après la dernière question, écran récapitulatif (score / total), `completed_at` et
   `score` renseignés sur `QuizAttempt`.
5. **Historique** : section listant les tentatives passées de la session (document, score/total,
   date), chargée depuis `QuizAttemptRepository::findBySession()`.

## Gestion des erreurs

- **Document sans assez de contenu indexé** : le composant affiche un message clair avant même
  d'appeler le modèle (ex. moins de `sampleSize` chunks disponibles pour ce document).
- **Échec de génération** (voir ci-dessus) : message d'erreur, bouton pour relancer une génération.
- **Réponse invalide côté client** (aucune option cochée) : validation avant soumission, comme un
  formulaire Symfony classique.

## Tests

- **Unitaire** `QuizGeneratorTest` : échantillonnage des chunks et génération, avec un double de
  test `symfony/ai` pour la sortie structurée (même esprit que `RagQueryServiceTest` avec ses
  doubles de plateforme/store).
- **Fonctionnel** : cycle complet démarrage → réponse (mono et multi-réponses) → score final, via le
  test de Live Component fourni par `symfony/ux-live-component` (`InteractsWithLiveComponents`).
- **Fonctionnel** : historique du quiz (une tentative terminée apparaît dans
  `QuizAttemptRepository::findBySession()`), sur le modèle de `RagHistoryControllerTest`.

## Hors scope (rappel des phases suivantes)

- Sélection par chapitre/section (nécessite d'extraire une structure des documents à l'ingestion).
- Exercices en texte libre avec correction par l'IA.
- Flashcards.
- Upload d'une solution manuscrite (photo → OCR → correction).
- PWA (manifest, service worker, installabilité).
- Nombre de questions choisi par l'utilisateur à chaque lancement (reste un paramètre applicatif
  fixe dans ce périmètre).
