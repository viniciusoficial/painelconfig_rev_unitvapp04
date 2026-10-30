<?php
require_once __DIR__ . '/config.php';

function github_data_request($method, $path, $body = null)
{
    if (GITHUB_TOKEN === '') {
        throw new RuntimeException('GITHUB_TOKEN não foi configurado no ambiente do servidor.');
    }

    $segments = array_map('rawurlencode', explode('/', trim($path, '/')));
    $url = 'https://api.github.com/repos/' . rawurlencode(GITHUB_OWNER) . '/' . rawurlencode(GITHUB_REPOSITORY)
        . '/contents/' . implode('/', $segments);
    if ($method === 'GET') {
        $url .= '?ref=' . rawurlencode(GITHUB_BRANCH);
    }

    $headers = [
        'Accept: application/vnd.github+json',
        'Authorization: Bearer ' . GITHUB_TOKEN,
        'X-GitHub-Api-Version: 2022-11-28',
        'User-Agent: ConfigBox-Admin',
    ];
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
    }

    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 45,
    ]);
    if ($body !== null) {
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    $response = curl_exec($curl);
    $error = curl_error($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    if ($response === false || $error !== '') {
        throw new RuntimeException('Falha de conexão com o GitHub: ' . $error);
    }

    $json = json_decode($response, true);
    if ($status < 200 || $status >= 300) {
        $message = is_array($json) ? (string) ($json['message'] ?? '') : '';
        if ($status === 404) {
            throw new RuntimeException('GitHub HTTP 404: arquivo ou caminho não encontrado.');
        }
        throw new RuntimeException('GitHub HTTP ' . $status . ($message !== '' ? ': ' . $message : '.'));
    }
    if (!is_array($json)) {
        throw new RuntimeException('Resposta inválida da API do GitHub.');
    }
    return $json;
}

function github_file_read($path)
{
    $file = github_data_request('GET', $path);
    $encoded = str_replace(["\n", "\r"], '', (string) ($file['content'] ?? ''));
    $raw = base64_decode($encoded, true);
    $value = $raw === false ? null : json_decode($raw, true);
    return ['value' => $value, 'sha' => (string) ($file['sha'] ?? '')];
}

function github_devices_read()
{
    try {
        $file = github_file_read(GITHUB_DATA_FOLDER . '/' . GITHUB_DEVICES_FILE);
        return ['items' => is_array($file['value']) ? $file['value'] : [], 'sha' => $file['sha']];
    } catch (Throwable $error) {
        if (strpos($error->getMessage(), 'HTTP 404') !== false) {
            return ['items' => [], 'sha' => null];
        }
        throw $error;
    }
}

function github_device_hash(array $device)
{
    $hash = strtolower(trim((string) ($device['device_hash'] ?? '')));
    if (preg_match('/^[a-f0-9]{64}$/', $hash)) {
        return $hash;
    }
    $legacyId = trim((string) ($device['device_id'] ?? ''));
    return $legacyId === '' ? '' : hash('sha256', $legacyId);
}

function github_devices_write(array $items, $sha = null)
{
    $safeItems = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $hash = github_device_hash($item);
        if ($hash === '') {
            continue;
        }
        unset($item['device_id'], $item['imei']);
        $item['device_hash'] = $hash;
        $safeItems[] = $item;
    }

    $body = [
        'message' => 'Atualizar cadastro e controle de aparelhos',
        'content' => base64_encode(json_encode(array_values($safeItems), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
        'branch' => GITHUB_BRANCH,
    ];
    if ($sha) {
        $body['sha'] = $sha;
    }
    return github_data_request('PUT', GITHUB_DATA_FOLDER . '/' . GITHUB_DEVICES_FILE, $body);
}

function github_device_touch($deviceId, $model, $androidVersion)
{
    $deviceHash = hash('sha256', trim((string) $deviceId));
    for ($attempt = 0; $attempt < 3; $attempt++) {
        $data = github_devices_read();
        $items = $data['items'];
        $recordIndex = null;
        foreach ($items as $index => $item) {
            if (is_array($item) && hash_equals(github_device_hash($item), $deviceHash)) {
                $recordIndex = $index;
                break;
            }
        }

        $record = $recordIndex === null
            ? ['device_hash' => $deviceHash, 'blocked' => false, 'message' => '']
            : $items[$recordIndex];
        $record['device_hash'] = $deviceHash;
        unset($record['device_id'], $record['imei']);
        $record['model'] = trim((string) $model);
        $record['android_version'] = trim((string) $androidVersion);
        $record['last_seen'] = date('c');
        if ($recordIndex === null) {
            $items[] = $record;
        } else {
            $items[$recordIndex] = $record;
        }

        try {
            github_devices_write($items, $data['sha']);
            return $record;
        } catch (Throwable $error) {
            if (strpos($error->getMessage(), 'HTTP 409') === false || $attempt === 2) {
                throw $error;
            }
            // Releia para preservar alterações de bloqueio feitas simultaneamente pelo painel.
        }
    }
    throw new RuntimeException('Não foi possível atualizar o aparelho no GitHub.');
}

function github_settings_read()
{
    $defaults = ['plan_active' => true, 'lock_message' => '', 'dialog_message' => ''];
    try {
        $file = github_file_read(GITHUB_DATA_FOLDER . '/settings.json');
        $value = is_array($file['value']) ? $file['value'] : [];
        return [
            'settings' => [
                'plan_active' => !array_key_exists('plan_active', $value) || (bool) $value['plan_active'],
                'lock_message' => trim((string) ($value['lock_message'] ?? '')),
                'dialog_message' => trim((string) ($value['dialog_message'] ?? '')),
            ],
            'sha' => $file['sha'],
        ];
    } catch (Throwable $error) {
        if (strpos($error->getMessage(), 'HTTP 404') !== false) {
            return ['settings' => $defaults, 'sha' => null];
        }
        throw $error;
    }
}

function github_settings_write(array $settings, $sha = null)
{
    $clean = [
        'plan_active' => !empty($settings['plan_active']),
        'lock_message' => trim((string) ($settings['lock_message'] ?? '')),
        'dialog_message' => trim((string) ($settings['dialog_message'] ?? '')),
    ];
    $body = [
        'message' => 'Atualizar controle remoto do aplicativo',
        'content' => base64_encode(json_encode($clean, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
        'branch' => GITHUB_BRANCH,
    ];
    if ($sha) {
        $body['sha'] = $sha;
    }
    return github_data_request('PUT', GITHUB_DATA_FOLDER . '/settings.json', $body);
}

function github_link_read()
{
    try {
        $file = github_file_read(GITHUB_DATA_FOLDER . '/unitv-link.json');
        $value = $file['value'];
        return ['url' => is_array($value) ? (string) ($value['url'] ?? '') : '', 'sha' => $file['sha']];
    } catch (Throwable $error) {
        if (strpos($error->getMessage(), 'HTTP 404') !== false) {
            return ['url' => '', 'sha' => null];
        }
        throw $error;
    }
}

function github_link_write($url, $sha = null)
{
    $body = [
        'message' => 'Atualizar link do APK UniTV',
        'content' => base64_encode(json_encode(['url' => $url], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)),
        'branch' => GITHUB_BRANCH,
    ];
    if ($sha) {
        $body['sha'] = $sha;
    }
    return github_data_request('PUT', GITHUB_DATA_FOLDER . '/unitv-link.json', $body);
}
