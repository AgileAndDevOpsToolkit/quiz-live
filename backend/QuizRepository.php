<?php
declare(strict_types=1);

namespace QuizLive;

final class QuizRepository
{
    public const DEFAULT_ID = 'default';
    public const MAX_QUIZZES = 50;

    public static function directory(): string
    {
        return getenv('QUIZ_DIR') ?: dirname(__DIR__) . '/storage/quizzes';
    }

    public static function seedPath(): string
    {
        return getenv('QUIZ_JSON_PATH') ?: dirname(__DIR__) . '/data/questions.json';
    }

    public static function isValidId(string $id): bool
    {
        return (bool) preg_match('/\A[a-z0-9][a-z0-9-]{0,39}\z/D', $id);
    }

    public static function slugify(string $title): string
    {
        $ascii = function_exists('iconv') ? @iconv('UTF-8', 'ASCII//TRANSLIT', $title) : false;
        $slug = strtolower(trim((string) preg_replace('/[^a-zA-Z0-9]+/', '-', $ascii !== false ? $ascii : ''), '-'));
        $slug = rtrim(substr($slug, 0, 40), '-');
        return self::isValidId($slug) ? $slug : 'quiz-' . bin2hex(random_bytes(3));
    }

    /** Copie le quiz de démonstration uniquement si aucun questionnaire n'existe encore. */
    public static function seed(): bool
    {
        if ((new self())->files() !== []) {
            return false;
        }
        (new self())->save(self::DEFAULT_ID, Quiz::load(self::seedPath()), false);
        return true;
    }

    /** @return list<array{id:string,title:string,questionCount:int,updatedAt:int}> */
    public function list(): array
    {
        $items = [];
        foreach ($this->files() as $id => $path) {
            try {
                $quiz = Quiz::load($path);
            } catch (ApiError $error) {
                error_log('QuizLive: questionnaire ignoré (' . $id . ') : ' . $error->getMessage());
                continue;
            }
            $items[] = [
                'id' => $id,
                'title' => $quiz['title'],
                'questionCount' => count($quiz['questions']),
                'updatedAt' => (int) filemtime($path),
            ];
        }
        usort($items, static fn(array $a, array $b): int => strcasecmp($a['title'], $b['title']) ?: strcmp($a['id'], $b['id']));
        return $items;
    }

    public function get(string $id): array
    {
        $path = $this->path($id);
        if (!is_file($path)) {
            throw new ApiError('Questionnaire introuvable.', 404);
        }
        return Quiz::load($path);
    }

    /** @return array{id:string,title:string,questionCount:int} */
    public function save(string $id, mixed $data, bool $overwrite): array
    {
        $path = $this->path($id);
        try {
            $quiz = Quiz::validate($data);
        } catch (ApiError $error) {
            throw new ApiError($error->getMessage(), 422);
        }
        $json = json_encode($quiz, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
        if (strlen($json) > Quiz::MAX_BYTES) {
            throw new ApiError('Le questionnaire dépasse 1 Mo.', 413);
        }
        $exists = is_file($path);
        if ($exists && !$overwrite) {
            throw new ApiError('Un questionnaire porte déjà cet identifiant.', 409);
        }
        if (!$exists && count($this->files()) >= self::MAX_QUIZZES) {
            throw new ApiError('Nombre maximal de questionnaires atteint (' . self::MAX_QUIZZES . ').', 409);
        }
        $directory = self::directory();
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new \RuntimeException('Impossible de créer le répertoire des questionnaires.');
        }
        // Écriture atomique : un lecteur ne voit jamais un fichier à moitié écrit.
        $tmp = tempnam($directory, '.tmp-');
        if ($tmp === false) {
            throw new \RuntimeException('Impossible de créer un fichier temporaire.');
        }
        if (file_put_contents($tmp, $json) === false || !chmod($tmp, 0660) || !rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException('Impossible d’écrire le questionnaire.');
        }
        return ['id' => $id, 'title' => $quiz['title'], 'questionCount' => count($quiz['questions'])];
    }

    public function delete(string $id): void
    {
        $path = $this->path($id);
        if (!@unlink($path)) {
            throw new ApiError('Questionnaire introuvable.', 404);
        }
    }

    private function path(string $id): string
    {
        if (!self::isValidId($id)) {
            throw new ApiError('Identifiant de questionnaire invalide (minuscules, chiffres et tirets, 40 caractères max).', 422);
        }
        return self::directory() . '/' . $id . '.json';
    }

    /** @return array<string,string> identifiant => chemin */
    private function files(): array
    {
        $files = [];
        foreach (glob(self::directory() . '/*.json') ?: [] as $path) {
            $id = basename($path, '.json');
            if (self::isValidId($id) && is_file($path)) {
                $files[$id] = $path;
            }
        }
        return $files;
    }
}
