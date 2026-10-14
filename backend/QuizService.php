<?php
declare(strict_types=1);

namespace QuizLive;

use PDO;
use PDOException;

final class QuizService
{
    private const CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    private const MAX_PARTICIPANTS = 120;
    private bool $writing = false;

    public function __construct(private readonly PDO $db)
    {
    }

    public static function nowMs(): int
    {
        return (int) floor(microtime(true) * 1000);
    }

    public function publicInfo(): array
    {
        $quiz = Quiz::fromFile();
        return ['title' => $quiz['title'], 'questionCount' => count($quiz['questions'])];
    }

    public function create(string $adminKey): array
    {
        $expected = getenv('APP_ADMIN_KEY');
        if (!$expected || strlen($expected) < 16) {
            throw new ApiError('Configure APP_ADMIN_KEY avec une clé d’au moins 16 caractères.', 503);
        }
        if (!hash_equals($expected, $adminKey)) {
            throw new ApiError('Clé animateur incorrecte.', 403);
        }

        $quiz = Quiz::fromFile();
        $snapshot = json_encode($quiz, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $token = bin2hex(random_bytes(32));
        $now = self::nowMs();
        $stmt = $this->db->prepare(
            'INSERT INTO sessions (code,title,quiz_json,host_token_hash,created_at_ms,updated_at_ms) VALUES (?,?,?,?,?,?)'
        );
        for ($attempt = 0; $attempt < 6; $attempt++) {
            $code = $this->newCode();
            try {
                $stmt->execute([$code, $quiz['title'], $snapshot, hash('sha256', $token), $now, $now]);
                return ['code' => $code, 'hostToken' => $token];
            } catch (PDOException $exception) {
                // Collision improbable de code : ne relancer que si le code existe vraiment.
                if (!$this->getSession($code, false)) {
                    throw $exception;
                }
            }
        }
        throw new ApiError('Impossible de créer une session. Réessaie.', 503);
    }

    public function join(string $code, string $name): array
    {
        $code = self::validateCode($code);
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
        if (!preg_match('//u', $name) || preg_match('/[\p{C}]/u', $name)
            || preg_match_all('/./us', $name) > 24 || $name === '') {
            throw new ApiError('Choisis un pseudo de 1 à 24 caractères, sans caractères de contrôle.');
        }
        $normalized = function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
        $token = bin2hex(random_bytes(32));
        $this->beginWrite();
        try {
            $session = $this->requireSession($code);
            if ($session['status'] !== 'lobby') {
                throw new ApiError('La session a déjà commencé : les inscriptions sont closes.', 409);
            }
            $stmt = $this->db->prepare('SELECT COUNT(*) FROM participants WHERE session_code=?');
            $stmt->execute([$code]);
            if ((int)$stmt->fetchColumn() >= self::MAX_PARTICIPANTS) {
                throw new ApiError('Cette session a atteint sa capacité maximale.', 409);
            }
            $stmt = $this->db->prepare('SELECT 1 FROM participants WHERE session_code=? AND name_normalized=?');
            $stmt->execute([$code, $normalized]);
            if ($stmt->fetchColumn()) {
                throw new ApiError('Ce pseudo est déjà utilisé dans cette session.', 409);
            }
            $stmt = $this->db->prepare(
                'INSERT INTO participants(session_code,name,name_normalized,token_hash,joined_at_ms) VALUES (?,?,?,?,?)'
            );
            $stmt->execute([$code, $name, $normalized, hash('sha256', $token), self::nowMs()]);
            $this->commitWrite();
            return ['code' => $code, 'playerToken' => $token, 'name' => $name];
        } catch (\Throwable $error) {
            $this->rollback();
            throw $error;
        }
    }

    public function state(string $code, string $token): array
    {
        $code = self::validateCode($code);
        $session = $this->requireSession($code);
        $identity = $this->identity($session, $token);
        $quiz = Quiz::decode($session['quiz_json']);
        $index = (int)$session['question_index'];
        $status = $session['status'];
        $now = self::nowMs();

        $stmt = $this->db->prepare('SELECT COUNT(*) FROM participants WHERE session_code=?');
        $stmt->execute([$code]);
        $count = (int)$stmt->fetchColumn();
        $answeredCount = 0;
        if ($index >= 0) {
            $stmt = $this->db->prepare('SELECT COUNT(*) FROM answers WHERE session_code=? AND question_index=?');
            $stmt->execute([$code, $index]);
            $answeredCount = (int)$stmt->fetchColumn();
        }
        $result = [
            'serverNowMs' => $now,
            'role' => $identity['role'],
            'session' => [
                'code' => $code,
                'title' => $session['title'],
                'status' => $status,
                'questionIndex' => $index,
                'questionCount' => count($quiz['questions']),
                'participantsCount' => $count,
                'answeredCount' => $answeredCount,
            ],
        ];
        if ($identity['role'] === 'host' && $status === 'lobby') {
            $stmt = $this->db->prepare('SELECT name FROM participants WHERE session_code=? ORDER BY joined_at_ms,id');
            $stmt->execute([$code]);
            $result['participants'] = array_column($stmt->fetchAll(), 'name');
        }
        if ($index >= 0 && ($status === 'question' || $status === 'reveal')) {
            $q = $quiz['questions'][$index];
            $result['question'] = [
                'id' => $q['id'],
                'index' => $index,
                'text' => $q['text'],
                'answers' => $q['answers'],
                'durationSeconds' => $q['durationSeconds'],
                'startedAtMs' => (int)$session['started_at_ms'],
                'deadlineAtMs' => (int)$session['deadline_at_ms'],
            ];
            if ($status === 'reveal') {
                $stmt = $this->db->prepare(
                    'SELECT answer_index,COUNT(*) as n FROM answers WHERE session_code=? AND question_index=? GROUP BY answer_index'
                );
                $stmt->execute([$code, $index]);
                $counts = [0, 0, 0, 0];
                foreach ($stmt->fetchAll() as $row) {
                    $counts[(int)$row['answer_index']] = (int)$row['n'];
                }
                $result['reveal'] = [
                    'correctIndex' => $q['correctIndex'],
                    'explanation' => $q['explanation'],
                    'answerCounts' => $counts,
                ];
            }
        }
        if ($status === 'reveal' || $status === 'finished') {
            $result['leaderboard'] = $this->leaderboard($code);
        }
        if ($identity['role'] === 'player') {
            $player = $identity['participant'];
            $response = null;
            if ($index >= 0) {
                $stmt = $this->db->prepare(
                    'SELECT answer_index,is_correct,points FROM answers WHERE participant_id=? AND session_code=? AND question_index=?'
                );
                $stmt->execute([(int)$player['id'], $code, $index]);
                $response = $stmt->fetch() ?: null;
            }
            $visibleScore = (int)$player['score'];
            if ($status === 'question' && $response) {
                // Ne pas révéler indirectement la bonne réponse via le score pendant le chrono.
                $visibleScore -= (int)$response['points'];
            }
            $result['me'] = [
                'name' => $player['name'],
                'score' => $visibleScore,
                'hasAnswered' => $response !== null,
                'answerIndex' => $response ? (int)$response['answer_index'] : null,
            ];
            if ($status === 'reveal' && $response) {
                $result['me']['isCorrect'] = (bool)$response['is_correct'];
                $result['me']['earnedPoints'] = (int)$response['points'];
            }
            if ($status === 'finished') {
                foreach ($result['leaderboard'] as $leader) {
                    if ($leader['id'] === (int)$player['id']) {
                        $result['me']['rank'] = $leader['rank'];
                        break;
                    }
                }
            }
        }
        return $result;
    }

    public function answer(string $code, string $token, int $index, int $answerIndex): array
    {
        $code = self::validateCode($code);
        if ($answerIndex < 0 || $answerIndex > 3 || $index < 0) {
            throw new ApiError('Réponse ou numéro de question invalide.');
        }
        $this->beginWrite();
        try {
            $session = $this->requireSession($code);
            $identity = $this->identity($session, $token);
            if ($identity['role'] !== 'player') {
                throw new ApiError('Seul un participant peut répondre.', 403);
            }
            $now = self::nowMs();
            if ($session['status'] !== 'question' || (int)$session['question_index'] !== $index
                || $now > (int)$session['deadline_at_ms']) {
                throw new ApiError('Cette question est terminée ou n’est plus active.', 409);
            }
            $playerId = (int)$identity['participant']['id'];
            $stmt = $this->db->prepare(
                'SELECT 1 FROM answers WHERE session_code=? AND participant_id=? AND question_index=?'
            );
            $stmt->execute([$code, $playerId, $index]);
            if ($stmt->fetchColumn()) {
                throw new ApiError('Une réponse a déjà été enregistrée pour cette question.', 409);
            }
            $quiz = Quiz::decode($session['quiz_json']);
            $q = $quiz['questions'][$index];
            $correct = $answerIndex === $q['correctIndex'];
            $durationMs = (int)$q['durationSeconds'] * 1000;
            $remainingMs = max(0, min($durationMs, (int)$session['deadline_at_ms'] - $now));
            $points = $correct ? 500 + (int)round(500 * $remainingMs / $durationMs) : 0;
            $stmt = $this->db->prepare(
                'INSERT INTO answers(session_code,participant_id,question_index,answer_index,is_correct,points,answered_at_ms) VALUES (?,?,?,?,?,?,?)'
            );
            $stmt->execute([$code, $playerId, $index, $answerIndex, (int)$correct, $points, $now]);
            $stmt = $this->db->prepare('UPDATE participants SET score=score+?, correct_answers=correct_answers+? WHERE id=?');
            $stmt->execute([$points, (int)$correct, $playerId]);
            $this->commitWrite();
            // Jamais retourner la justesse ni les points avant la révélation.
            return ['accepted' => true];
        } catch (\Throwable $error) {
            $this->rollback();
            throw $error;
        }
    }

    public function hostAction(string $code, string $token, string $action): array
    {
        $code = self::validateCode($code);
        if (!in_array($action, ['start', 'reveal', 'next', 'finish'], true)) {
            throw new ApiError('Action animateur inconnue.');
        }
        $this->beginWrite();
        try {
            $session = $this->requireSession($code);
            $identity = $this->identity($session, $token);
            if ($identity['role'] !== 'host') {
                throw new ApiError('Accès animateur requis.', 403);
            }
            $quiz = Quiz::decode($session['quiz_json']);
            $now = self::nowMs();
            $status = $session['status'];
            $index = (int)$session['question_index'];
            $startAt = $session['started_at_ms'];
            $deadlineAt = $session['deadline_at_ms'];
            if ($action === 'start' && $status === 'lobby') {
                $stmt = $this->db->prepare('SELECT COUNT(*) FROM participants WHERE session_code=?');
                $stmt->execute([$code]);
                if ((int)$stmt->fetchColumn() === 0) {
                    throw new ApiError('Attends au moins un participant avant de démarrer.', 409);
                }
                $status = 'question';
                $index = 0;
                $startAt = $now;
                $deadlineAt = $now + (int)$quiz['questions'][$index]['durationSeconds'] * 1000;
            } elseif ($action === 'reveal' && $status === 'question') {
                $status = 'reveal';
            } elseif ($action === 'next' && $status === 'reveal') {
                if ($index + 1 >= count($quiz['questions'])) {
                    $status = 'finished';
                } else {
                    $index++;
                    $status = 'question';
                    $startAt = $now;
                    $deadlineAt = $now + (int)$quiz['questions'][$index]['durationSeconds'] * 1000;
                }
            } elseif ($action === 'finish' && $status !== 'finished') {
                $status = 'finished';
            } else {
                throw new ApiError('Action impossible dans l’état actuel de la session.', 409);
            }
            $stmt = $this->db->prepare(
                'UPDATE sessions SET status=?,question_index=?,started_at_ms=?,deadline_at_ms=?,updated_at_ms=? WHERE code=?'
            );
            $stmt->execute([$status, $index, $startAt, $deadlineAt, $now, $code]);
            $this->commitWrite();
            return ['ok' => true, 'status' => $status];
        } catch (\Throwable $error) {
            $this->rollback();
            throw $error;
        }
    }

    private function leaderboard(string $code): array
    {
        $stmt = $this->db->prepare(
            'SELECT id,name,score,correct_answers FROM participants WHERE session_code=? ORDER BY score DESC, correct_answers DESC,joined_at_ms ASC,id ASC'
        );
        $stmt->execute([$code]);
        $leaders = [];
        foreach ($stmt->fetchAll() as $i => $row) {
            $leaders[] = [
                'id' => (int)$row['id'],
                'rank' => $i + 1,
                'name' => $row['name'],
                'score' => (int)$row['score'],
                'correctAnswers' => (int)$row['correct_answers'],
            ];
        }
        return $leaders;
    }

    private function identity(array $session, string $token): array
    {
        if (!preg_match('/\A[a-f0-9]{64}\z/D', $token)) {
            throw new ApiError('Accès non autorisé. Rejoins la session.', 401);
        }
        $hash = hash('sha256', $token);
        if (hash_equals($session['host_token_hash'], $hash)) {
            return ['role' => 'host'];
        }
        $stmt = $this->db->prepare('SELECT * FROM participants WHERE session_code=? AND token_hash=?');
        $stmt->execute([$session['code'], $hash]);
        $player = $stmt->fetch();
        if (!$player) {
            throw new ApiError('Identifiant de participant inconnu.', 401);
        }
        return ['role' => 'player', 'participant' => $player];
    }

    private function getSession(string $code, bool $throw = true): array|false
    {
        $stmt = $this->db->prepare('SELECT * FROM sessions WHERE code=?');
        $stmt->execute([$code]);
        $row = $stmt->fetch();
        if (!$row && $throw) {
            throw new ApiError('Session introuvable. Vérifie le code.', 404);
        }
        return $row;
    }

    private function requireSession(string $code): array
    {
        return $this->getSession($code);
    }

    private function beginWrite(): void
    {
        $this->db->exec('BEGIN IMMEDIATE');
        $this->writing = true;
    }

    private function commitWrite(): void
    {
        $this->db->exec('COMMIT');
        $this->writing = false;
    }

    private function rollback(): void
    {
        if ($this->writing) {
            try {
                $this->db->exec('ROLLBACK');
            } finally {
                $this->writing = false;
            }
        }
    }

    private function newCode(): string
    {
        $code = '';
        for ($i = 0; $i < 6; $i++) {
            $code .= self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)];
        }
        return $code;
    }

    private static function validateCode(string $code): string
    {
        $code = strtoupper(trim($code));
        if (!preg_match('/\A[A-HJ-NP-Z2-9]{6}\z/D', $code)) {
            throw new ApiError('Code de session invalide.');
        }
        return $code;
    }
}
