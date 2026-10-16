<?php
/* 🔴 NEVER OVER THE WEB. This suite writes a log file beside the mock and
   starts a local server; under a web server this file is reachable and must
   refuse. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * The custom (OpenAI-compatible) provider: local runners and self-hosted APIs.
 *
 *   php tests/custom-openai/run.php
 *
 * 🔴 READ THIS BEFORE TRUSTING A GREEN RUN. This suite proves that FreeITSM
 * SENDS what an OpenAI-compatible server asks for, against a local stand-in
 * (mock.php). It does NOT prove a real Ollama, LM Studio, vLLM or Mistral
 * accepts the request, because nobody contributing here necessarily has one
 * running. The request body and response shape are the OpenAI ones this
 * codebase has shipped for months; what is NEW is exactly three things — the
 * address the request lands at, the OPTIONAL key (no Authorization header at
 * all when there is none), and the model travelling through untouched — and
 * all three are things we emit, so all three are assertable here.
 *
 * No database is needed: nothing here reads or writes system_settings.
 */

chdir(dirname(__DIR__, 2));
require_once 'config.php';
require_once 'includes/ai_provider.php';
require_once 'includes/ai_settings.php';

/* ---- The mock server, started by this test -------------------------------
 * Same approach as tests/azure-openai/run.php: the suite starts its own
 * php -S on a free port serving only this directory, so the real cURL path
 * is exercised rather than a stubbed one, and nothing outside this process
 * can reach it. */
$devNull = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';

function freePort(): int {
    $sock = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if (!$sock) { fwrite(STDERR, "could not find a free port: $errstr\n"); exit(1); }
    $name = stream_socket_get_name($sock, false);
    fclose($sock);
    return (int)substr($name, strrpos($name, ':') + 1);
}

/* bypass_shell IS NOT OPTIONAL ON WINDOWS — see the note in
   tests/azure-openai/run.php: proc_terminate must kill the server itself, not
   a cmd.exe wrapper around it. */
$port = freePort();
$mockProc = proc_open(
    escapeshellarg(PHP_BINARY) . ' -S 127.0.0.1:' . $port . ' -t ' . escapeshellarg(__DIR__),
    [0 => ['file', $devNull, 'r'], 1 => ['file', $devNull, 'w'], 2 => ['file', $devNull, 'w']],
    $pipes,
    null,
    null,
    ['bypass_shell' => true]
);
if (!is_resource($mockProc)) { fwrite(STDERR, "could not start the mock server\n"); exit(1); }

register_shutdown_function(function () use (&$mockProc) {
    if (is_resource($mockProc)) proc_terminate($mockProc);
});

$ready = false;
for ($i = 0; $i < 100 && !$ready; $i++) {
    $c = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.2);
    if ($c) { $ready = true; fclose($c); } else { usleep(100000); }
}
if (!$ready) { fwrite(STDERR, "the mock server never came up on port $port\n"); exit(1); }

$MOCK = "http://127.0.0.1:$port/mock.php";
$LOG  = __DIR__ . '/last-request.json';   // see the note in mock.php

$pass = 0; $fail = 0;
function ok(string $what, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ok    $what\n"; }
    else       { $fail++; echo "  FAIL  $what" . ($detail ? "  ($detail)" : '') . "\n"; }
}
function section(string $t): void { echo "\n$t\n" . str_repeat('-', strlen($t)) . "\n"; }
function sent(): array {
    global $LOG;
    return is_file($LOG) ? (json_decode((string)file_get_contents($LOG), true) ?: []) : [];
}
function reset_log(): void { global $LOG; @unlink($LOG); }

// ── 1. The URL we build ─────────────────────────────────────────────────────
section('1. The URL — one address, however it was pasted');

ok('a plain base URL gains exactly one /chat/completions',
    aiCustomChatUrl(['custom_base_url' => 'http://localhost:11434/v1'])
        === 'http://localhost:11434/v1/chat/completions');
foreach ([
    'http://localhost:11434/v1/'                  => 'trailing slash',
    'http://localhost:11434/v1/chat/completions'  => 'a whole pasted endpoint',
    'http://localhost:11434/v1/chat/completions/' => 'a pasted endpoint, trailing slash',
] as $base => $label) {
    $u = aiCustomChatUrl(['custom_base_url' => $base]);
    ok("normalises an address pasted with a $label",
        $u === 'http://localhost:11434/v1/chat/completions', $u);
}

// ── 2. Configuration errors name the RIGHT field ────────────────────────────
section('2. A missing field is named as the field the administrator can see');

$err = '';
try { aiProviderChat(['provider' => 'custom', 'api_key' => '', 'model' => 'llama3.1'], []); }
catch (RuntimeException $e) { $err = $e->getMessage(); }
ok('no base URL says "base URL", not "API key" or "model"',
    stripos($err, 'base URL') !== false, $err);

$err = '';
try { aiProviderChat(['provider' => 'custom', 'api_key' => '', 'custom_base_url' => 'http://x/v1'], []); }
catch (RuntimeException $e) { $err = $e->getMessage(); }
ok('a custom server still needs a model', stripos($err, 'model') !== false, $err);

// CONTROL — the named providers still demand a key.
$err = '';
try { aiProviderChat(['provider' => 'openai', 'api_key' => '', 'model' => 'gpt-4o'], []); }
catch (RuntimeException $e) { $err = $e->getMessage(); }
ok('CONTROL — openai with no key still says "No API key configured"',
    $err === 'No API key configured.', $err);

// ── 3. What actually goes down the wire ─────────────────────────────────────
section('3. 🔴 The bytes we send (against the local stand-in)');

// Keyless — the case that matters for a local runner.
reset_log();
$result = aiProviderChat([
    'provider' => 'custom', 'api_key' => '',
    'custom_base_url' => $MOCK . '?p=', 'model' => 'llama3.1',
    'verify_ssl' => false,
], ['system' => 'sys', 'user' => 'hello', 'max_tokens' => 16]);
$log = sent();
ok('the keyless call succeeded', ($result['content'] ?? '') === 'OK');
ok('🔴 NO Authorization header at all when there is no key', empty($log[0]['has_authorization']));
ok('the model travels through untouched', ($log[0]['model'] ?? '') === 'llama3.1');
ok('the messages are the ordinary system + user pair',
    count($log[0]['messages'] ?? []) === 2 && $log[0]['messages'][1]['content'] === 'hello');
ok('the provider is reported as custom', ($result['provider'] ?? '') === 'custom');

// Keyed — a hosted API (such as Mistral) goes through the same field.
reset_log();
$result = aiProviderChat([
    'provider' => 'custom', 'api_key' => 'custom-test-key-123',
    'custom_base_url' => $MOCK . '?p=', 'model' => 'mistral-small-latest',
    'verify_ssl' => false,
], ['system' => 'sys', 'user' => 'hello', 'max_tokens' => 16]);
$log = sent();
ok('the keyed call succeeded', ($result['content'] ?? '') === 'OK');
ok('🔴 a key, when there is one, goes as Authorization: Bearer',
    ($log[0]['authorization'] ?? '') === 'Bearer custom-test-key-123');

// The pasted-endpoint mercy must not double the path.
reset_log();
$result = aiProviderChat([
    'provider' => 'custom', 'api_key' => '',
    'custom_base_url' => $MOCK . '?p=/chat/completions', 'model' => 'llama3.1',
    'verify_ssl' => false,
], ['system' => 'sys', 'user' => 'hello', 'max_tokens' => 16]);
$log = sent();
ok('a pasted full endpoint is not doubled up',
    ($result['content'] ?? '') === 'OK' && substr_count((string)($log[0]['uri'] ?? ''), '/chat/completions') === 1,
    (string)($log[0]['uri'] ?? ''));

// CONTROL — the OpenAI path is UNCHANGED by the custom branch.
reset_log();
$result = aiProviderChat([
    'provider' => 'openai', 'api_key' => 'sk-control', 'model' => 'gpt-4o',
    'verify_ssl' => false, 'base_url' => $MOCK . '?p=',
], ['system' => 'sys', 'user' => 'hello', 'max_tokens' => 16]);
$log = sent();
ok('CONTROL — openai still sends Authorization: Bearer', !empty($log[0]['has_authorization']));
ok('CONTROL — openai still sends a model field', !empty($log[0]['body_has_model']));
ok('CONTROL — the call still succeeds', ($result['content'] ?? '') === 'OK');

// ── 4. The max_tokens / max_completion_tokens retry ─────────────────────────
section('4. Newer models: max_tokens → max_completion_tokens');

reset_log();
$result = aiProviderChat([
    'provider' => 'custom', 'api_key' => '',
    'custom_base_url' => $MOCK . '?p=', 'model' => 'needs-max-completion',
    'verify_ssl' => false,
], ['system' => 'sys', 'user' => 'hello', 'max_tokens' => 16]);
$log = sent();
ok('the call still succeeds', ($result['content'] ?? '') === 'OK');
ok('it took exactly TWO attempts, not a loop', count($log) === 2, 'attempts: ' . count($log));
ok('🔴 the retry swapped in max_completion_tokens',
    ($log[1]['max_completion_tokens'] ?? null) === 16 && ($log[1]['max_tokens'] ?? null) === null);

// ── 5. Tool calling ──────────────────────────────────────────────────────────
section('5. Tool calling reaches the custom server keylessly too');

reset_log();
$result = aiProviderChatTools([
    'provider' => 'custom', 'api_key' => '',
    'custom_base_url' => $MOCK . '?p=', 'model' => 'llama3.1',
    'verify_ssl' => false,
], ['system' => 'sys', 'user' => 'hello', 'max_tokens' => 16], [], function () { return ''; });
$log = sent();
ok('tool calling succeeds against the custom base', ($result['content'] ?? '') === 'OK');
ok('🔴 still NO Authorization header', empty($log[0]['has_authorization']));
ok('the model is sent', ($log[0]['model'] ?? '') === 'llama3.1');

// ── 6. Wiring, and the reading for a 404 ─────────────────────────────────────
section('6. Wiring, and the explanation a 404 gets');

ok('custom is an accepted provider', in_array('custom', AI_PROVIDER_VALID, true));
$keys = aiSettingsKeys('knowledge_ai');
ok('a settings key exists for custom_base_url',
    isset($keys['custom_base_url']) && $keys['custom_base_url'] === 'knowledge_ai_custom_base_url');

$why = aiExplainFailure(
    ['provider' => 'custom', 'model' => 'llama3.1', 'custom_base_url' => 'http://localhost:11434'],
    'Resource not found (HTTP 404)');
ok('a 404 names the base URL', strpos($why, 'localhost:11434') !== false, $why);
ok('…and the model', strpos($why, 'llama3.1') !== false, $why);
ok('…and /v1, the prefix most OpenAI-compatible servers serve under', stripos($why, '/v1') !== false, $why);

reset_log();
echo "\n" . str_repeat('=', 62) . "\n";
echo "$pass passed, $fail failed\n";
echo "⚠️  Green here means WE SEND THE RIGHT REQUEST. It does not mean a real\n";
echo "    Ollama, LM Studio, vLLM or Mistral accepts it — that needs a real server.\n";
exit($fail > 0 ? 1 : 0);
