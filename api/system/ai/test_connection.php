<?php
/**
 * API: Test the saved AI config for a namespace with a tiny live call.
 * POST JSON { ns }
 * Returns: { success, provider, model, duration_ms, reply } or { success:false, error }
 */
session_start(['read_and_close' => true]);
require_once '../../../config.php';
require_once '../../../includes/functions.php';
require_once '../../../includes/rbac.php';
require_once '../../../includes/settings_keys.php';
require_once '../../../includes/ai_settings.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true) ?: [];
$ns = isset($data['ns']) ? trim((string)$data['ns']) : '';
if (!aiSettingsIsValidNs($ns)) {
    echo json_encode(['success' => false, 'error' => 'Unknown AI settings namespace']);
    exit;
}

try {
    $conn = connectToDatabase();

    // Spends money against the provider, so it belongs to whoever administers this
    // namespace's AI settings — a converted module's capability, else is_admin.
    requireAiNamespaceJson($conn, $ns);

    $cfg  = aiSettingsLoad($conn, $ns);
    /* A custom server's key is optional — most local runners have none — so
       only the named providers demand a key before a test can be made. */
    if ($cfg['api_key'] === '' && ($cfg['provider'] ?? '') !== 'custom') {
        echo json_encode(['success' => false, 'error' => 'No API key saved yet — save a key first.']);
        exit;
    }

    $result = aiProviderChat($cfg, [
        'system'     => 'You are a connection test. Reply with the single word: OK',
        'user'       => 'Reply with the single word: OK',
        'max_tokens' => 16,
    ]);

    echo json_encode([
        'success'     => true,
        'provider'    => $result['provider'],
        'model'       => $result['model'],
        'duration_ms' => $result['duration_ms'],
        'reply'       => mb_substr((string)$result['content'], 0, 80),
    ]);
} catch (Exception $e) {
    /* GH #171: a bare "Resource not found (HTTP 404)" left the administrator
       nowhere to go. Say what it most likely means, and - debug tools being
       administrators-only - point an administrator at the tool that shows the
       whole exchange. */
    $out = ['success' => false, 'error' => $e->getMessage()];
    if (isset($cfg)) {
        $hint = aiExplainFailure($cfg, $e->getMessage());
        if ($hint !== '') $out['hint'] = $hint;
    }
    if (sessionIsAdmin()) {
        $out['debug_url'] = BASE_URL . 'system/debug-tools/d014/?ns=' . rawurlencode($ns);
    }
    echo json_encode($out);
}
