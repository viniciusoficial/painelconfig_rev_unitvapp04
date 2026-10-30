<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/github-data.php';

function fail_api($message, $status = 400)
{
    http_response_code($status);
    echo json_encode(['error' => $message], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$payload = json_decode(file_get_contents('php://input'), true);
if (!is_array($payload)) {
    fail_api('Corpo JSON inválido.');
}
if (PUBLIC_APP_KEY === '' || !hash_equals(PUBLIC_APP_KEY, (string) ($payload['app_key'] ?? ''))) {
    fail_api('Chave do aplicativo inválida.', 401);
}
if (($payload['action'] ?? '') !== 'device_status') {
    fail_api('Ação inválida.');
}

$deviceId = trim((string) ($payload['device_id'] ?? ''));
if ($deviceId === '' || strlen($deviceId) > 180) {
    fail_api('Identificador do aparelho inválido.');
}

try {
    $settings = github_settings_read()['settings'];
    $record = github_device_touch(
        $deviceId,
        (string) ($payload['model'] ?? ''),
        (string) ($payload['android_version'] ?? '')
    );

    $blocked = !empty($record['blocked']);
    $planActive = !empty($settings['plan_active']);
    $message = '';
    if ($blocked) {
        $message = trim((string) ($record['message'] ?? ''));
        if ($message === '') {
            $message = 'Este aparelho foi bloqueado pelo administrador.';
        }
    } elseif (!$planActive) {
        $message = trim((string) ($settings['lock_message'] ?? ''));
        if ($message === '') {
            $message = 'O acesso ao aplicativo está desativado pelo administrador.';
        }
    }

    $link = github_link_read();
    echo json_encode([
        'ok' => true,
        'plan_active' => $planActive,
        'device_blocked' => $blocked,
        'allowed' => $planActive && !$blocked,
        'message' => $message,
        'dialog' => (string) ($settings['dialog_message'] ?? ''),
        'unitv_url' => $link['url'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $error) {
    fail_api('Não foi possível consultar o controle remoto. Verifique o GITHUB_TOKEN e as permissões Contents do repositório. ' . $error->getMessage(), 502);
}
