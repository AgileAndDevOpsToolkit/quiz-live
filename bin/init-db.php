<?php
declare(strict_types=1);

require dirname(__DIR__) . '/backend/bootstrap.php';
\QuizLive\Quiz::fromFile();
\QuizLive\Database::initialize();
echo "Base SQLite initialisée et quiz JSON validé.\n";
