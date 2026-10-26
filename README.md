# QuizLive — quiz interactif pour ateliers

**QuizLive** est un mini-Kahoot auto-hébergé, pour une salle d'environ **50 participants** (limite configurée à 120). Un animateur crée une session, affiche un QR code, puis déroule un quiz de QCM. Les participants répondent depuis leur navigateur sur smartphone, et découvrent le classement final.

**Stack :** PHP 8.2+ · SQLite (PDO) · Vue.js 3 · QRCode.js · HTML/CSS responsive. **Aucun compte, aucune PWA, aucun WebSocket, aucune base de données serveur** et aucune étape `npm install` ne sont requis.

## 1. Démarrage rapide avec Docker

Prérequis : Docker et Docker Compose.

```bash
cp .env.example .env
# Remplacer APP_ADMIN_KEY dans .env par une clé aléatoire (>= 16 caractères) :
openssl rand -hex 24
# Copier cette valeur dans .env

docker compose up -d --build
```

Ouvre **http://localhost:8080** sur l'ordinateur de l'animateur. La clé de `.env` permet de créer une session, puis le site génère un **jeton secret distinct** pour piloter celle-ci.

> **Important pour les téléphones :** `localhost` ne fonctionne que sur la machine elle-même. Publie l'application sur un nom de domaine HTTPS (recommandé), ou ouvre `http://IP_LOCALE_DU_SERVEUR:8080` si le Wi-Fi autorise l'accès entre appareils. Le QR code utilise automatiquement l'URL réellement ouverte dans le navigateur de l'animateur.

Pour arrêter : `docker compose down` (les données restent dans le volume `quizlive_data`). **Ne fais pas** `down -v` si tu souhaites conserver les sessions.

### Reverse proxy Caddy (exemple)

Avec un domaine public pointant vers le serveur, configure un reverse proxy vers le port publié :

```caddyfile
quiz.agiletoolkit.fr {
    reverse_proxy 127.0.0.1:8080
}
```

Si Caddy est lui-même en conteneur Docker, **`127.0.0.1` désigne alors le conteneur Caddy** : utilise un réseau Docker partagé et le nom du service `quizlive:80`, ou une adresse de l'hôte accessible depuis Caddy. Adapte le nom de domaine et la connectivité à ton infrastructure.

## 2. Installation sans Docker (Apache ou PHP intégré)

Prérequis : PHP 8.2+, extension `pdo_sqlite`, Apache avec `DocumentRoot` pointant **uniquement** sur le dossier `public/`, ou le serveur PHP intégré pour un test local.

```bash
export APP_ADMIN_KEY="$(openssl rand -hex 24)"
php bin/init-db.php
php -S 0.0.0.0:8080 -t public
```

Le dossier `storage/` doit être accessible en écriture par PHP/Apache. Avec Apache, passe la variable `APP_ADMIN_KEY` à l'environnement du processus serveur (configuration de service ou du VirtualHost), **pas** dans un fichier servi par le site. Les questionnaires JSON et le fichier SQLite restent **en dehors** du `DocumentRoot`.

Variables d'environnement disponibles :

| Variable | Usage | Valeur par défaut |
|---|---|---|
| `APP_ADMIN_KEY` | Clé secrète de création (16 caractères minimum) | Obligatoire |
| `QUIZ_DB_PATH` | Chemin du fichier SQLite persistant | `storage/quiz.sqlite` |
| `QUIZ_DIR` | Dossier des questionnaires (un fichier `<id>.json` chacun) | `storage/quizzes` |
| `QUIZ_JSON_PATH` | JSON de démonstration copié dans `QUIZ_DIR` s'il est vide | `data/questions.json` |

Sur Docker, l'initialisation SQLite est automatique au démarrage.

## 3. Déroulement d'une partie

1. **Animateur :** saisit la clé de configuration et clique sur « Créer une session ».
2. **Accueil des joueurs :** l'application affiche un QR code, un lien et un code à six caractères. Chaque participant entre son pseudo sur son téléphone. Aucun compte et aucune installation nécessaires.
3. **Départ :** l'animateur clique sur « Démarrer le quiz ». Les inscriptions sont alors fermées pour éviter d'ajouter des joueurs en cours de partie.
4. **Question :** tout le monde voit les quatre propositions simultanément (synchronisation toutes les secondes). Chaque joueur peut répondre **une seule fois** avant l'échéance.
5. **Correction :** l'animateur clique sur « Révéler la réponse ». Les smartphones affichent la bonne réponse et les points gagnés.
6. **Question suivante :** l'animateur clique sur « Question suivante ». Après la dernière correction, le bouton affiche le podium.
7. **Fin :** top 3 et classement complet, avec les scores calculés côté serveur.

**Règle de score :** mauvaise réponse ou absence de réponse = **0 point**. Bonne réponse = **500 à 1 000 points** selon le temps restant, mesuré sur le **serveur** :

`points = 500 + arrondi(500 × temps_restant / durée_totale)`

Exemple : 10 s restantes sur 20 s → **750 points**.

Le chrono continue côté serveur même si un téléphone est ralenti. Un faible délai réseau peut affecter légèrement le score ; pour un jeu d'atelier, ce compromis est généralement acceptable.

## 4. Gérer les questionnaires

Les questionnaires sont des fichiers JSON dans `QUIZ_DIR` (`/var/www/storage/quizzes` sous Docker, donc **dans le volume** : ils survivent aux mises à jour de l'image). Au premier démarrage, si le dossier est vide, `data/questions.json` y est copié sous le nom `default.json`.

Depuis l'accueil, saisis la clé d'administration : tu peux alors **choisir le questionnaire** de la session, **importer** un fichier JSON (l'identifiant est dérivé du nom du fichier ; un questionnaire existant n'est remplacé qu'après confirmation) ou **supprimer** un questionnaire. Tu peux aussi déposer directement des fichiers `<id>.json` dans le dossier (`id` : minuscules, chiffres et `-`, 40 caractères max ; 50 questionnaires maximum).

Une nouvelle session prend **une copie figée** du questionnaire choisi : ajouter, modifier ou supprimer un questionnaire n'altère donc pas les parties déjà lancées. Si tu supprimes tous les questionnaires, le quiz de démonstration est recopié au prochain démarrage.

API (clé d'administration en `Authorization: Bearer`) : `GET api.php?action=quizzes`, `POST quiz-upload` (`{id?, quiz, overwrite?}`), `POST quiz-delete` (`{id}`), `POST create` (`{quizId}`).

Exemple :

```json
{
  "title": "Mon quiz",
  "questions": [
    {
      "id": "q1",
      "text": "À quoi sert une rétrospective ?",
      "answers": [
        "Planifier la prochaine version",
        "Améliorer le fonctionnement de l'équipe",
        "Fixer le budget annuel",
        "Attribuer les congés"
      ],
      "correctIndex": 1,
      "durationSeconds": 20,
      "explanation": "Une rétrospective sert à définir des pistes d'amélioration concrètes."
    }
  ]
}
```

- `correctIndex` va de **0 à 3** (0 = première proposition).
- Exactement **quatre** réponses non vides par question.
- `durationSeconds` compris entre **5 et 120 secondes**.
- `id` unique, avec lettres ASCII, chiffres, `_` et `-`.
- De 1 à 100 questions par quiz, fichier JSON de 1 Mo maximum.
- `explanation` est facultatif : elle s'affiche uniquement à la correction.

Les modifications sont prises en compte à la prochaine session, sans reconstruire l'image.

## 5. Architecture et sécurité

```text
quiz-live/
├── public/                 ← DocumentRoot ; seuls ces fichiers sont accessibles sur le Web
│   ├── index.html          ← Interface Vue 3, responsive
│   ├── api.php             ← API HTTP JSON
│   └── assets/
│       ├── app.js          ← Client Vue, polling et QR code
│       └── styles.css
├── backend/
│   ├── Database.php        ← SQLite, schéma et paramètres de connexion
│   ├── Quiz.php            ← Validation du JSON
│   ├── QuizRepository.php  ← Questionnaires stockés dans QUIZ_DIR
│   └── QuizService.php     ← Sessions, autorisations, scoring, podium
├── data/questions.json    ← Quiz de démonstration (copié dans le volume au 1er démarrage)
├── storage/                ← DB SQLite et quizzes/, hors Web
├── bin/                    ← Initialisation et maintenance
├── tests/                  ← Tests métier
├── docker/                 ← Config Apache + entrée Docker
├── Dockerfile
└── compose.yaml
```

**Protection mise en place :**

- Clé `APP_ADMIN_KEY` requise pour créer une session ; jamais renvoyée aux clients.
- Jeton animateur et jeton joueur distincts, aléatoires, 256 bits ; **seuls leurs hachages SHA-256** sont enregistrés en base.
- QR code = **lien d'invitation**, **pas** jeton d'administration. Les participants ont ensuite un jeton propre à leur appareil (stocké dans le `localStorage` du navigateur).
- Les bonnes réponses sont conservées côté serveur et ne sont publiées **qu'après révélation**. Le score de la question active est aussi masqué avant la correction.
- SQLite en mode **WAL** ; transactions `BEGIN IMMEDIATE` pour sérialiser les réponses concurrentes ; contrainte SQL d'unicité par joueur et question.
- La date limite est contrôlée côté serveur, ainsi que l'état de la session et l'indice de question : une réponse tardive ou doublonnée est refusée.
- Requêtes SQL paramétrées, validation des pseudo/paramètres, affichage des textes via Vue (échappés automatiquement).
- API sans cache, accès réservé aux jetons pour consulter l'état de la session.

**Limitations assumées du prototype :**

- Il n'y a ni système de comptes, ni fonction de récupération des jetons, ni modération des pseudos, ni contrôle anti-triche avancé (un joueur motivé peut se créer plusieurs pseudos avec plusieurs navigateurs). Le code de participation n'est **pas une barrière de sécurité forte** : une personne qui connaît ce code peut rejoindre tant que la salle est ouverte.
- Les jetons restent dans `localStorage` : un joueur peut reprendre sa session sur **le même navigateur**. Si le stockage est effacé, son ancienne identité n'est pas récupérable. L'animateur doit conserver son navigateur et son stockage local.
- Aucune limite de fréquence par IP n'est intégrée. Pour un déploiement ouvert sur Internet, ajoute des limites au niveau de Caddy/Apache si nécessaire, et évite de diffuser publiquement le code de participation avant l'atelier.
- Sans WebSocket, le nouvel écran arrive typiquement **en moins d'une seconde**, pas à la milliseconde près. La notation est basée sur l'heure du serveur, pas sur celle du smartphone.
- **Deux scripts tiers sont chargés depuis le CDN jsDelivr** : Vue 3.5.18 et QRCode.js 1.0.0. Un accès à ce CDN est donc nécessaire sur les appareils des participants, sauf si tu héberges une copie des deux fichiers localement (voir ci-dessous). Ces bibliothèques ne sont pas livrées dans ce ZIP.

### Fonctionnement sans accès au CDN

Pour organiser un atelier sur un réseau local isolé, télécharge **une fois** les deux scripts sur un ordinateur ayant accès à Internet, puis remplace les deux URL CDN de `public/index.html` par les chemins locaux :

```bash
mkdir -p public/assets/vendor
curl -fL 'https://cdn.jsdelivr.net/npm/vue@3.5.18/dist/vue.global.prod.js' -o public/assets/vendor/vue.global.prod.js
curl -fL 'https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js' -o public/assets/vendor/qrcode.min.js
```

Dans `public/index.html`, remplace les attributs `src` des deux balises `<script>` par `assets/vendor/vue.global.prod.js` et `assets/vendor/qrcode.min.js`. Après cette opération, le quiz peut fonctionner sur un réseau local sans Internet, si les téléphones accèdent à ton serveur via IP ou DNS interne. Si tu utilises Docker, reconstruis l'image après ces modifications.

## 6. Tester

Dans le conteneur (où `pdo_sqlite` est installé) :

```bash
docker compose exec quizlive php tests/integration.php
```

Ou en installation manuelle avec `pdo_sqlite` actif :

```bash
php tests/integration.php
```

Le test vérifie : clé admin, connexion des joueurs, refus des pseudos dupliqués, protection des jetons, impossibilité de doubler une réponse, masquage du score avant révélation, bonnes réponses, classement, chrono serveur, progression et fin de partie.

Un test de **50 réponses simultanées** est fourni. Il crée une nouvelle session sur l'instance indiquée (ne pas lancer contre des données de production sans le vouloir) :

```bash
QUIZ_BASE_URL=http://127.0.0.1:8080 APP_ADMIN_KEY="$(grep '^APP_ADMIN_KEY=' .env | cut -d= -f2-)" python3 tests/stress_50.py
```

Ce test valide les écritures concurrentes et le classement. Il ne remplace pas une **répétition réelle avec les smartphones** sur ton matériel/réseau. Le polling représente environ 50 lectures d'état par seconde, et les écritures sont transactionnelles.

## 7. Maintenance

Purger les sessions inactives depuis plus de 30 jours :

```bash
docker compose exec quizlive php bin/purge-sessions.php 30
```

Sauvegarde le volume Docker `quizlive_data` si tu veux conserver les résultats. Le fichier SQLite peut contenir des pseudos et des réponses : traite-le comme une donnée personnelle à protéger, avec une politique de conservation adaptée.

## 8. Licence / bibliothèques tierces

Le code applicatif de ce projet est fourni pour être modifié et auto-hébergé. Vue.js et QRCode.js sont des projets tiers distincts, chargés depuis leur CDN, avec leurs propres licences.

## Déploiement Kubernetes / RKE2 (Ingress NGINX)

L'image sert l'application à la racine `/` et aussi sous `/quiz-live/` (Alias Apache). L'Ingress doit transmettre le chemin tel quel : **ne pas utiliser** `nginx.ingress.kubernetes.io/rewrite-target: /`, qui renvoie `index.html` pour tous les fichiers (erreur « MIME type text/html »).

```yaml
- path: /quiz-live/
  pathType: Prefix
  backend:
    service:
      name: quiz-live-service
      port:
        number: 80
```

Accéder à l'application via `/quiz-live/` (avec le slash final).
