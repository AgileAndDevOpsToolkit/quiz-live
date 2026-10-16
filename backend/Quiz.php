<?php
declare(strict_types=1);

namespace QuizLive;

use JsonException;

final class Quiz
{
    public const MAX_BYTES = 1024 * 1024;

    public static function load(string $path): array
    {
        if (!is_file($path) || filesize($path) > self::MAX_BYTES) {
            throw new ApiError('Fichier de questions absent ou trop volumineux.', 503);
        }
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new ApiError('Impossible de lire le fichier de questions.', 503);
        }
        return self::decode($raw);
    }

    public static function decode(string $raw): array
    {
        try {
            $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ApiError('Le fichier de questions n’est pas un JSON valide : ' . $exception->getMessage(), 503);
        }
        return self::validate($data);
    }

    public static function validate(mixed $data): array
    {
        if (!is_array($data) || !is_string($data['title'] ?? null)
            || trim($data['title']) === '' || !is_array($data['questions'] ?? null)
            || !array_is_list($data['questions']) || count($data['questions']) < 1
            || count($data['questions']) > 100) {
            throw new ApiError('Le quiz doit avoir un titre et de 1 à 100 questions.', 503);
        }
        $data['title'] = trim($data['title']);
        $ids = [];
        foreach ($data['questions'] as $index => &$q) {
            if (!is_array($q) || !is_string($q['id'] ?? null)
                || !preg_match('/\A[a-zA-Z0-9_-]{1,40}\z/D', $q['id'])
                || isset($ids[$q['id']]) || !is_string($q['text'] ?? null)
                || trim($q['text']) === '' || !is_array($q['answers'] ?? null)
                || !array_is_list($q['answers']) || count($q['answers']) !== 4
                || !is_int($q['correctIndex'] ?? null)
                || $q['correctIndex'] < 0 || $q['correctIndex'] > 3
                || !is_int($q['durationSeconds'] ?? null)
                || $q['durationSeconds'] < 5 || $q['durationSeconds'] > 120) {
                throw new ApiError('Question ' . ($index + 1) . ' invalide (4 réponses, correctIndex 0–3, durée 5–120 s).', 503);
            }
            $ids[$q['id']] = true;
            $q['text'] = trim($q['text']);
            foreach ($q['answers'] as &$a) {
                if (!is_string($a) || trim($a) === '') {
                    throw new ApiError('Chaque réponse de la question ' . ($index + 1) . ' doit être un texte non vide.', 503);
                }
                $a = trim($a);
            }
            unset($a);
            if (isset($q['explanation']) && !is_string($q['explanation'])) {
                throw new ApiError('Explication invalide dans la question ' . ($index + 1), 503);
            }
            $q['explanation'] = trim($q['explanation'] ?? '');
        }
        unset($q);
        return $data;
    }
}
