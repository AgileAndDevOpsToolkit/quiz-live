<?php
declare(strict_types=1);

use QuizLive\ApiError;
use QuizLive\Database;
use QuizLive\Quiz;
use QuizLive\QuizRepository;
use QuizLive\QuizService;

require dirname(__DIR__) . '/backend/bootstrap.php';

function testAssert(bool $condition, string $name): void
{
    if (!$condition) throw new RuntimeException("ÉCHEC : $name");
    echo "OK  $name\n";
}
function expectsError(callable $fn, int $http): bool
{
    try { $fn(); } catch (ApiError $e) { return $e->status === $http; }
    return false;
}

if (!extension_loaded('pdo_sqlite')) {
    fwrite(STDERR, "pdo_sqlite absent : installe l'extension ou lance les tests dans Docker.\n");
    exit(2);
}

$file = tempnam(sys_get_temp_dir(), 'quizlive-test-');
if ($file === false) throw new RuntimeException('tempnam impossible');
$quizDir = $file . '-quizzes';
try {
    putenv('QUIZ_DB_PATH=' . $file);
    putenv('QUIZ_DIR=' . $quizDir);
    putenv('APP_ADMIN_KEY=sample-test-admin-key-over-16-chars');
    Database::initialize();
    $db = Database::connect();
    $service = new QuizService($db);
    $adminKey = 'sample-test-admin-key-over-16-chars';
    testAssert(count(Quiz::load(QuizRepository::seedPath())['questions']) === 10, 'Validation du JSON de démonstration');
    testAssert(QuizRepository::seed() === true, 'Le quiz de démonstration est copié dans un dossier vide');
    testAssert(QuizRepository::seed() === false, 'Le seeding ne touche pas un dossier non vide');
    $quizzes = $service->listQuizzes($adminKey);
    testAssert(count($quizzes) === 1 && $quizzes[0]['id'] === 'default' && $quizzes[0]['questionCount'] === 10, 'Liste des questionnaires');
    testAssert(expectsError(fn() => $service->listQuizzes('bad-key'), 403), 'Liste réservée à l’animateur');

    $custom = [
        'title' => 'Quiz équipe été',
        'questions' => [[
            'id' => 'q1', 'text' => 'Question ?', 'answers' => ['a', 'b', 'c', 'd'],
            'correctIndex' => 2, 'durationSeconds' => 10,
        ]],
    ];
    $uploaded = $service->uploadQuiz($adminKey, null, $custom, false);
    testAssert($uploaded['id'] === 'quiz-equipe-ete' && $uploaded['questionCount'] === 1, 'Upload avec identifiant dérivé du titre');
    testAssert(expectsError(fn() => $service->uploadQuiz($adminKey, 'quiz-equipe-ete', $custom, false), 409), 'Écrasement refusé sans overwrite');
    $custom['title'] = 'Quiz équipe été v2';
    testAssert($service->uploadQuiz($adminKey, 'quiz-equipe-ete', $custom, true)['title'] === 'Quiz équipe été v2', 'Écrasement autorisé avec overwrite');
    testAssert(expectsError(fn() => $service->uploadQuiz('bad-key', 'x', $custom, false), 403), 'Upload refusé sans clé admin');
    testAssert(expectsError(fn() => $service->uploadQuiz($adminKey, 'x', ['title' => 'Vide', 'questions' => []], false), 422), 'Quiz invalide refusé');
    foreach (['../evil', 'A/b', '.hidden', str_repeat('a', 41), 'a b'] as $badId) {
        testAssert(expectsError(fn() => $service->uploadQuiz($adminKey, $badId, $custom, true), 422), 'Identifiant refusé : ' . $badId);
    }
    testAssert(glob($quizDir . '/*') !== false && count(glob($quizDir . '/*')) === 2, 'Aucun fichier hors identifiants valides ni résidu temporaire');
    testAssert(expectsError(fn() => $service->create($adminKey, 'inconnu'), 404), 'Questionnaire inconnu refusé');
    testAssert(expectsError(fn() => $service->create('bad-key'), 403), 'La mauvaise clé est refusée');

    $other = $service->create($adminKey, 'quiz-equipe-ete');
    $otherState = $service->state($other['code'], $other['hostToken']);
    testAssert($otherState['session']['questionCount'] === 1 && $otherState['session']['title'] === 'Quiz équipe été v2', 'Session créée avec le questionnaire choisi');
    $service->deleteQuiz($adminKey, 'quiz-equipe-ete');
    testAssert(expectsError(fn() => $service->deleteQuiz($adminKey, 'quiz-equipe-ete'), 404), 'Suppression d’un questionnaire absent');
    testAssert($service->state($other['code'], $other['hostToken'])['role'] === 'host', 'Une session en cours survit à la suppression de son questionnaire');

    $created = $service->create($adminKey);
    $code = $created['code'];
    $host = $created['hostToken'];
    testAssert(strlen($host) === 64, 'Jeton animateur robuste');
    testAssert(expectsError(fn() => $service->hostAction($code, $host, 'start'), 409), 'Impossible de lancer sans joueur');
    $alice = $service->join($code, 'Alice');
    $bob = $service->join($code, 'Bob');
    testAssert(expectsError(fn() => $service->join($code, 'Alice'), 409), 'Pseudo dupliqué rejeté');
    testAssert($service->state($code, $alice['playerToken'])['role'] === 'player', 'Accès participant par jeton');
    testAssert(expectsError(fn() => $service->state($code, str_repeat('a', 64)), 401), 'Faux jeton refusé');
    $service->hostAction($code, $host, 'start');
    testAssert(expectsError(fn() => $service->join($code, 'Charlie'), 409), 'Inscriptions fermées pendant le quiz');
    $state = $service->state($code, $alice['playerToken']);
    testAssert($state['session']['status'] === 'question', 'Première question active');
    testAssert(!array_key_exists('correctIndex', $state['question']), 'Bonne réponse cachée pendant le chrono');
    $service->answer($code, $alice['playerToken'], 0, 1); // Bonne réponse à Q1
    testAssert(expectsError(fn() => $service->answer($code, $alice['playerToken'], 0, 1), 409), 'Double réponse impossible');
    testAssert(expectsError(fn() => $service->answer($code, $host, 0, 1), 403), 'Animateur interdit de répondre');
    $state = $service->state($code, $alice['playerToken']);
    testAssert($state['me']['hasAnswered'] && $state['me']['score'] === 0, 'Points cachés avant révélation');
    testAssert(!isset($state['me']['earnedPoints']) && !isset($state['reveal']), 'Pas de fuite de correction');
    $service->answer($code, $bob['playerToken'], 0, 2); // Mauvaise réponse
    $service->hostAction($code, $host, 'reveal');
    $state = $service->state($code, $alice['playerToken']);
    testAssert($state['reveal']['correctIndex'] === 1, 'Bonne réponse révélée');
    testAssert($state['me']['earnedPoints'] >= 500 && $state['me']['earnedPoints'] <= 1000, 'Barème 500 à 1000');
    testAssert($state['leaderboard'][0]['name'] === 'Alice', 'Classement des joueurs');
    $service->hostAction($code, $host, 'next');
    $state = $service->state($code, $bob['playerToken']);
    testAssert($state['session']['questionIndex'] === 1, 'Passage à la question suivante');
    testAssert(expectsError(fn() => $service->answer($code, $bob['playerToken'], 0, 0), 409), 'Ancienne question rejetée');
    $db->prepare('UPDATE sessions SET deadline_at_ms=? WHERE code=?')->execute([1, $code]);
    testAssert(expectsError(fn() => $service->answer($code, $bob['playerToken'], 1, 0), 409), 'Réponse hors délai rejetée côté serveur');
    $service->hostAction($code, $host, 'finish');
    testAssert($service->state($code, $host)['session']['status'] === 'finished', 'Podium final disponible');
    testAssert(expectsError(fn() => $service->hostAction($code, $host, 'next'), 409), 'Partie terminée immuable');
    $db = null;
    echo "\nTous les tests d'intégration sont passés.\n";
} finally {
    foreach (glob($quizDir . '/{,.}*.json', GLOB_BRACE) ?: [] as $f) @unlink($f);
    @rmdir($quizDir);
    @unlink($file);
    @unlink($file . '-wal');
    @unlink($file . '-shm');
}
