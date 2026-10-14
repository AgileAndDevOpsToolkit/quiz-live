<?php
declare(strict_types=1);

namespace QuizLive;

use PDO;

final class Database
{
    public static function path(): string
    {
        return getenv('QUIZ_DB_PATH') ?: dirname(__DIR__) . '/storage/quiz.sqlite';
    }

    public static function connect(): PDO
    {
        $path = self::path();
        if (!is_file($path)) {
            throw new ApiError('La base de données n’est pas initialisée. Consulte README.md.', 503);
        }
        return self::open($path);
    }

    public static function initialize(): void
    {
        $path = self::path();
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new \RuntimeException('Impossible de créer le répertoire de stockage.');
        }
        $db = self::open($path);
        $db->exec('PRAGMA journal_mode = WAL');
        $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS sessions (
    code TEXT PRIMARY KEY,
    title TEXT NOT NULL,
    quiz_json TEXT NOT NULL,
    host_token_hash TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'lobby' CHECK(status IN ('lobby','question','reveal','finished')),
    question_index INTEGER NOT NULL DEFAULT -1,
    started_at_ms INTEGER,
    deadline_at_ms INTEGER,
    created_at_ms INTEGER NOT NULL,
    updated_at_ms INTEGER NOT NULL
);
CREATE TABLE IF NOT EXISTS participants (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    session_code TEXT NOT NULL REFERENCES sessions(code) ON DELETE CASCADE,
    name TEXT NOT NULL,
    name_normalized TEXT NOT NULL,
    token_hash TEXT NOT NULL UNIQUE,
    score INTEGER NOT NULL DEFAULT 0,
    correct_answers INTEGER NOT NULL DEFAULT 0,
    joined_at_ms INTEGER NOT NULL,
    UNIQUE(session_code, name_normalized)
);
CREATE INDEX IF NOT EXISTS idx_participants_ranking
    ON participants(session_code, score DESC, correct_answers DESC);
CREATE TABLE IF NOT EXISTS answers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    session_code TEXT NOT NULL REFERENCES sessions(code) ON DELETE CASCADE,
    participant_id INTEGER NOT NULL REFERENCES participants(id) ON DELETE CASCADE,
    question_index INTEGER NOT NULL,
    answer_index INTEGER NOT NULL CHECK(answer_index BETWEEN 0 AND 3),
    is_correct INTEGER NOT NULL CHECK(is_correct IN (0,1)),
    points INTEGER NOT NULL CHECK(points >= 0),
    answered_at_ms INTEGER NOT NULL,
    UNIQUE(session_code, participant_id, question_index)
);
CREATE INDEX IF NOT EXISTS idx_answers_question
    ON answers(session_code, question_index);
SQL);
    }

    private static function open(string $path): PDO
    {
        if (!extension_loaded('pdo_sqlite')) {
            throw new ApiError('L’extension PHP pdo_sqlite est nécessaire.', 503);
        }
        $db = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $db->exec('PRAGMA busy_timeout = 5000');
        $db->exec('PRAGMA foreign_keys = ON');
        return $db;
    }
}
