<?php
declare(strict_types=1);

use QuizLive\Database;
use QuizLive\QuizService;

require dirname(__DIR__) . '/backend/bootstrap.php';

$days = filter_var($argv[1] ?? 30, FILTER_VALIDATE_INT);
if ($days === false || $days < 1) {
    fwrite(STDERR, "Utilisation : php bin/purge-sessions.php [jours, >= 1]\n");
    exit(1);
}
$db = Database::connect();
$cutoff = QuizService::nowMs() - $days * 86400000;
$stmt = $db->prepare('DELETE FROM sessions WHERE updated_at_ms < ?');
$stmt->execute([$cutoff]);
echo $stmt->rowCount() . " sessions supprimées.\n";
