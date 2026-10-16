<?php
declare(strict_types=1);

require dirname(__DIR__) . '/backend/bootstrap.php';
$seeded = \QuizLive\QuizRepository::seed();
\QuizLive\Database::initialize();
echo "Base SQLite initialisée.", $seeded ? " Quiz de démonstration copié dans le dossier des questionnaires." : "", "\n";
