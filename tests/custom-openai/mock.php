<?php
/* 🔴 Served ONLY by the built-in server that tests/custom-openai/run.php starts
   with php -S. Under Apache, nginx or php-fpm this is an unauthenticated
   endpoint that writes a file to disk, so it refuses there. */
if (PHP_SAPI !== 'cli-server') { http_response_code(404); exit; }

/**
 * A stand-in for any OpenAI-compatible server: a local runner (Ollama, LM
 * Studio, vLLM) or a hosted API such as Mistral.
 *
 * 🔴 WHY THIS EXISTS, and what it can and cannot prove — see the header of
 * run.php. The request body and response are the OpenAI ones this codebase has
 * shipped for months; what is NEW for a custom server is exactly where the
 * request lands, whether an Authorization header is sent at all, and the model
 * travelling through untouched. This records those so they can be asserted.
 *
 * Behaviour is switched by the model name in the body, so a test can ask for a
 * failure without a second file:
 *   needs-max-completion → the 400 a newer model returns for max_tokens
 */

$raw     = file_get_contents('php://input');
$body    = json_decode($raw, true) ?: [];
$uri     = $_SERVER['REQUEST_URI'] ?? '';
$headers = function_exists('getallheaders') ? getallheaders() : [];

// Normalise header names — PHP servers differ on case.
$h = [];
foreach ($headers as $k => $v) { $h[strtolower($k)] = $v; }

// Record everything the client sent, for the assertions in run.php.
// ⚠️ Beside the mock, NOT in sys_get_temp_dir(): the web server and the CLI
// have different temp directories on Windows (see tests/azure-openai/mock.php).
$logFile = __DIR__ . '/last-request.json';
$log = [
    'uri'                   => $uri,
    'has_authorization'     => isset($h['authorization']),
    'authorization'         => $h['authorization'] ?? null,
    'body_has_model'        => array_key_exists('model', $body),
    'body_keys'             => array_keys($body),
    'model'                 => $body['model'] ?? null,
    'max_tokens'            => $body['max_tokens'] ?? null,
    'max_completion_tokens' => $body['max_completion_tokens'] ?? null,
    'has_temperature'       => array_key_exists('temperature', $body),
    'messages'              => $body['messages'] ?? [],
];

// Append, so a retry is visible as a SECOND entry rather than overwriting the first.
$all = [];
if (is_file($logFile)) {
    $all = json_decode((string)file_get_contents($logFile), true) ?: [];
}
$all[] = $log;
file_put_contents($logFile, json_encode($all));

header('Content-Type: application/json');

// The 400 a newer OpenAI-compatible model returns when it is handed max_tokens.
// Wording taken from the provider's own message, because our retry is triggered
// by recognising it — a paraphrase here would make the test prove nothing.
if (($body['model'] ?? '') === 'needs-max-completion' && array_key_exists('max_tokens', $body)) {
    http_response_code(400);
    echo json_encode(['error' => [
        'message' => "Unsupported parameter: 'max_tokens' is not supported with this model. Use 'max_completion_tokens' instead.",
    ]]);
    exit;
}

echo json_encode([
    'id'      => 'chatcmpl-mock',
    'object'  => 'chat.completion',
    'model'   => $body['model'] ?? '',
    'choices' => [[
        'index'         => 0,
        'message'       => ['role' => 'assistant', 'content' => 'OK'],
        'finish_reason' => 'stop',
    ]],
    'usage'   => ['prompt_tokens' => 11, 'completion_tokens' => 1, 'total_tokens' => 12],
]);
