<?php
declare(strict_types=1);

use QuizLive\ApiError;
use QuizLive\Database;
use QuizLive\QuizService;

require dirname(__DIR__) . '/backend/bootstrap.php';

ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private, max-age=0');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

function reply(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit;
}

function body(): array
{
    $size = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($size > 8192) {
        throw new ApiError('Requête trop volumineuse.', 413);
    }
    if (!str_starts_with($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')) {
        throw new ApiError('Le corps de la requête doit être un JSON.', 415);
    }
    $raw = file_get_contents('php://input', false, null, 0, 8193);
    if ($raw === false || strlen($raw) > 8192) {
        throw new ApiError('Requête trop volumineuse.', 413);
    }
    try {
        $data = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
    } catch (\JsonException) {
        throw new ApiError('JSON de requête invalide.');
    }
    if (!is_array($data) || array_is_list($data) && $data !== []) {
        throw new ApiError('Objet JSON attendu.');
    }
    return $data;
}

function field(array $data, string $key): string
{
    if (!is_string($data[$key] ?? null)) {
        throw new ApiError('Champ ' . $key . ' manquant ou invalide.');
    }
    return $data[$key];
}

function token(): string
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if ($header === '' && function_exists('getallheaders')) {
        foreach (getallheaders() as $key => $value) {
            if (strcasecmp($key, 'Authorization') === 0) { $header = $value; break; }
        }
    }
    if (!preg_match('/\ABearer ([^\s]+)\z/iD', $header, $matches)) {
        throw new ApiError('Authentification requise.', 401);
    }
    return $matches[1];
}

try {
    $action = $_GET['action'] ?? '';
    if (!is_string($action)) {
        throw new ApiError('Action invalide.');
    }
    $method = $_SERVER['REQUEST_METHOD'] ?? '';
    if ($method === 'GET' && $action === 'health') {
        reply(['ok' => true]);
    }
    $service = new QuizService(Database::connect());
    if ($method === 'GET' && $action === 'info') {
        reply($service->publicInfo());
    }
    if ($method === 'GET' && $action === 'state') {
        reply($service->state(field($_GET, 'code'), token()));
    }
    if ($method !== 'POST') {
        throw new ApiError('Route ou méthode inconnue.', 404);
    }
    $data = body();
    switch ($action) {
        case 'create':
            reply($service->create(token()), 201);
        case 'join':
            reply($service->join(field($data, 'code'), field($data, 'name')), 201);
        case 'answer':
            if (!is_int($data['questionIndex'] ?? null) || !is_int($data['answerIndex'] ?? null)) {
                throw new ApiError('Indices de question et de réponse requis.');
            }
            reply($service->answer(field($data, 'code'), token(), $data['questionIndex'], $data['answerIndex']));
        case 'host':
            reply($service->hostAction(field($data, 'code'), token(), field($data, 'command')));
        default:
            throw new ApiError('Route inconnue.', 404);
    }
} catch (ApiError $error) {
    reply(['error' => $error->getMessage()], $error->status);
} catch (\Throwable $error) {
    error_log('QuizLive: ' . $error::class . ': ' . $error->getMessage());
    reply(['error' => 'Erreur interne du serveur. Consulte les logs PHP.'], 500);
}
