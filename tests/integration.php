<?php
declare(strict_types=1);

use QuizLive\ApiError;
use QuizLive\Database;
use QuizLive\Quiz;
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
try {
    putenv('QUIZ_DB_PATH=' . $file);
    putenv('APP_ADMIN_KEY=sample-test-admin-key-over-16-chars');
    Database::initialize();
    $db = Database::connect();
    $service = new QuizService($db);
    testAssert(count(Quiz::fromFile()['questions']) === 10, 'Validation du JSON de démonstration');
    testAssert($service->publicInfo()['questionCount'] === 10, 'Informations publiques');
    testAssert(expectsError(fn() => $service->create('bad-key'), 403), 'La mauvaise clé est refusée');
    $created = $service->create('sample-test-admin-key-over-16-chars');
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
    @unlink($file);
    @unlink($file . '-wal');
    @unlink($file . '-shm');
}
