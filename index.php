<?php
require __DIR__ . '/config.php';
require_once __DIR__ . '/github-data.php';

function h($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function redir() { header('Location: index.php'); exit; }
function auth_secret() {
    $key = GITHUB_TOKEN !== '' ? GITHUB_TOKEN : ADMIN_PASSWORD;
    return hash_hmac('sha256', 'configbox-admin-cookie|' . ADMIN_PASSWORD, $key);
}
function cookie_is_secure() {
    return (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}
function admin_auth_expiry() {
    $cookie = (string) ($_COOKIE['configbox_admin_auth'] ?? '');
    $parts = explode('.', $cookie, 2);
    if (count($parts) !== 2 || !ctype_digit($parts[0])) return 0;
    $expires = (int) $parts[0];
    if ($expires <= time() || $expires > time() + 30 * 24 * 60 * 60 + 120) return 0;
    $expected = hash_hmac('sha256', 'admin|' . $expires, auth_secret());
    return hash_equals($expected, $parts[1]) ? $expires : 0;
}
function is_admin_authenticated() { return admin_auth_expiry() > 0; }
function set_admin_auth_cookie() {
    $expires = time() + 30 * 24 * 60 * 60;
    $value = $expires . '.' . hash_hmac('sha256', 'admin|' . $expires, auth_secret());
    setcookie('configbox_admin_auth', $value, [
        'expires' => $expires, 'path' => '/', 'secure' => cookie_is_secure(),
        'httponly' => true, 'samesite' => 'Lax'
    ]);
    $_COOKIE['configbox_admin_auth'] = $value;
}
function clear_admin_auth_cookie() {
    setcookie('configbox_admin_auth', '', [
        'expires' => time() - 3600, 'path' => '/', 'secure' => cookie_is_secure(),
        'httponly' => true, 'samesite' => 'Lax'
    ]);
    unset($_COOKIE['configbox_admin_auth']);
}
function token() {
    $expires = admin_auth_expiry();
    return $expires > 0 ? hash_hmac('sha256', 'csrf|' . $expires, auth_secret()) : '';
}
function encpath($path) { return implode('/', array_map('rawurlencode', explode('/', trim($path, '/')))); }
function github($method, $path, $body = null) {
    $url = 'https://api.github.com/repos/' . rawurlencode(GITHUB_OWNER) . '/' . rawurlencode(GITHUB_REPOSITORY)
        . '/contents/' . encpath($path);
    if ($method === 'GET') $url .= '?ref=' . rawurlencode(GITHUB_BRANCH);
    $headers = ['Accept: application/vnd.github+json', 'Authorization: Bearer ' . GITHUB_TOKEN,
        'X-GitHub-Api-Version: 2022-11-28', 'User-Agent: ConfigBox-Admin'];
    if ($body !== null) $headers[] = 'Content-Type: application/json';
    $curl = curl_init($url);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers, CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_TIMEOUT => 60]);
    if ($body !== null) curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $response = curl_exec($curl); $error = curl_error($curl); $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE); curl_close($curl);
    if ($response === false || $error !== '') throw new RuntimeException('Falha de conexão com o GitHub: ' . $error);
    $json = json_decode($response, true);
    if ($status < 200 || $status >= 300) {
        $message = is_array($json) ? (string) ($json['message'] ?? '') : '';
        throw new RuntimeException('GitHub HTTP ' . $status . ($message !== '' ? ': ' . $message : '.'));
    }
    if (!is_array($json)) throw new RuntimeException('Resposta inválida da API do GitHub.');
    return $json;
}
function files($folder) {
    $items = github('GET', $folder); $out = [];
    if (!is_array($items)) return $out;
    foreach ($items as $item) {
        if (($item['type'] ?? '') === 'file' && !in_array(($item['name'] ?? ''), ['.gitkeep', '.keep', 'README.md'], true)) {
            $out[] = ['name' => $item['name'] ?? '', 'path' => $item['path'] ?? '', 'sha' => $item['sha'] ?? '',
                'size' => (int) ($item['size'] ?? 0), 'url' => $item['download_url'] ?? ''];
        }
    }
    usort($out, function ($a, $b) { return strcasecmp($a['name'], $b['name']); });
    return $out;
}
function safename($name) {
    $name = trim(basename((string) $name));
    if ($name === '' || $name === '.' || $name === '..' || strpos($name, "\0") !== false) throw new RuntimeException('Nome de arquivo inválido.');
    return $name;
}
function sizefmt($size) { return $size < 1024 ? $size . ' B' : ($size < 1048576 ? round($size / 1024, 1) . ' KB' : round($size / 1048576, 1) . ' MB'); }
function authpost() {
    if (!is_admin_authenticated()) redir();
    $expected = token();
    if ($expected === '' || !isset($_POST['csrf']) || !hash_equals($expected, (string) $_POST['csrf'])) {
        throw new RuntimeException('Autenticação expirada. Entre novamente no painel.');
    }
}

$settings = ['plan_active' => true, 'lock_message' => '', 'dialog_message' => ''];
$devices = []; $configs = []; $unitvLink = ['url' => '']; $msg = null; $error = false;
try {
    if (isset($_GET['logout'])) { clear_admin_auth_cookie(); redir(); }
    if (isset($_POST['login'])) {
        if (ADMIN_PASSWORD === '') throw new RuntimeException('Senha administrativa não configurada no ambiente do servidor.');
        if (!hash_equals(ADMIN_PASSWORD, (string) ($_POST['password'] ?? ''))) throw new RuntimeException('Senha inválida.');
        set_admin_auth_cookie(); redir();
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        authpost();
        if (isset($_POST['save_control'])) {
            $current = github_settings_read();
            $settings = ['plan_active' => isset($_POST['plan_active']),
                'lock_message' => trim((string) ($_POST['lock_message'] ?? '')),
                'dialog_message' => trim((string) ($_POST['dialog_message'] ?? ''))];
            github_settings_write($settings, $current['sha']); $msg = 'Controle salvo no GitHub.';
        }
        if (isset($_POST['delete_config'])) {
            $name = safename($_POST['name'] ?? '');
            if (in_array($name, ['.gitkeep', '.keep', 'README.md'], true)) throw new RuntimeException('Arquivo de manutenção não pode ser excluído.');
            github('DELETE', GITHUB_CONFIG_FOLDER . '/' . $name, ['message' => 'Excluir configuração ' . $name,
                'sha' => (string) ($_POST['sha'] ?? ''), 'branch' => GITHUB_BRANCH]);
            $msg = 'Configuração excluída: ' . $name;
        }
        if (isset($_POST['delete_apk'])) {
            $name = safename($_POST['name'] ?? '');
            if (in_array($name, ['.gitkeep', '.keep', 'README.md'], true)) throw new RuntimeException('Arquivo de manutenção não pode ser excluído.');
            github('DELETE', GITHUB_APK_FOLDER . '/' . $name, ['message' => 'Excluir APK ' . $name,
                'sha' => (string) ($_POST['sha'] ?? ''), 'branch' => GITHUB_BRANCH]);
            $msg = 'APK excluído.';
        }
        if (isset($_POST['device_action'])) {
            $action = (string) $_POST['device_action'];
            $deviceHash = strtolower(trim((string) ($_POST['device_hash'] ?? '')));
            if (!in_array($action, ['block', 'unblock'], true) || !preg_match('/^[a-f0-9]{64}$/', $deviceHash)) {
                throw new RuntimeException('Ação ou identificador de aparelho inválido.');
            }
            $current = github_devices_read(); $found = false;
            foreach ($current['items'] as &$device) {
                if (hash_equals(github_device_hash($device), $deviceHash)) {
                    $device['blocked'] = $action === 'block';
                    $device['message'] = trim((string) ($_POST['device_message'] ?? ''));
                    $found = true; break;
                }
            }
            unset($device);
            if (!$found) throw new RuntimeException('Aparelho não encontrado. Atualize a página e tente novamente.');
            github_devices_write($current['items'], $current['sha']);
            $msg = $action === 'block' ? 'Aparelho bloqueado no GitHub.' : 'Aparelho desbloqueado no GitHub.';
        }
        if (isset($_POST['upload_configs'])) {
            $old = files(GITHUB_CONFIG_FOLDER); $map = [];
            foreach ($old as $file) $map[$file['name']] = $file;
            $upload = $_FILES['configs'] ?? null;
            if (!$upload || !is_array($upload['name'] ?? null)) throw new RuntimeException('Escolha as configurações.');
            $count = 0;
            foreach ($upload['name'] as $index => $original) {
                if (($upload['error'][$index] ?? 4) !== UPLOAD_ERR_OK) continue;
                $name = safename($original);
                $body = ['message' => (isset($map[$name]) ? 'Atualizar ' : 'Adicionar ') . $name,
                    'content' => base64_encode(file_get_contents($upload['tmp_name'][$index])), 'branch' => GITHUB_BRANCH];
                if (isset($map[$name])) $body['sha'] = $map[$name]['sha'];
                github('PUT', GITHUB_CONFIG_FOLDER . '/' . $name, $body); $count++;
            }
            $msg = $count . ' configuração(ões) enviada(s).';
        }
        if (isset($_POST['save_unitv_url'])) {
            $url = trim((string) ($_POST['unitv_url'] ?? ''));
            if ($url !== '' && !filter_var($url, FILTER_VALIDATE_URL)) throw new RuntimeException('Informe uma URL válida do APK.');
            $link = github_link_read(); github_link_write($url, $link['sha']);
            $msg = $url === '' ? 'Link do UniTV removido.' : 'Link do UniTV salvo no GitHub.';
        }
    }

    $logged = is_admin_authenticated();
    if ($logged) {
        $settings = github_settings_read()['settings'];
        $devices = github_devices_read()['items'];
        $configs = files(GITHUB_CONFIG_FOLDER);
        $unitvLink = github_link_read();
    }
} catch (Throwable $exception) {
    $msg = $exception->getMessage(); $error = true;
    $logged = is_admin_authenticated();
}
?><!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>ConfigBox Admin</title><style>
:root{font-family:system-ui,-apple-system,Segoe UI,sans-serif;color:#17243b;background:#f5f7fb}*{box-sizing:border-box}body{margin:0}.shell{max-width:1060px;margin:auto;padding:24px 16px 55px}.card{background:#fff;border:1px solid #e3e9f2;border-radius:18px;padding:20px;margin:16px 0;box-shadow:0 7px 25px #1e3a5f0b}.login{max-width:420px;margin:12vh auto}.head{display:flex;align-items:center;justify-content:space-between;gap:16px}.brand{display:flex;gap:12px;align-items:center}.logo{width:43px;height:43px;border-radius:13px;background:#2563eb;color:#fff;display:grid;place-items:center;font-weight:900;font-size:20px}.eyebrow{color:#2563eb;font-size:11px;font-weight:900;letter-spacing:1.2px;margin:0 0 4px}.title{font-size:26px;margin:0;letter-spacing:-.5px}.sub,.muted{color:#6d7b91;font-size:13px}.repo{margin-top:18px;padding:13px;border-radius:12px;background:#eaf2ff;color:#2458a8;font:13px monospace;overflow:auto}.notice{padding:12px 14px;border-radius:11px;margin:16px 0;font-size:14px}.success{background:#e8f8ee;color:#176b3c}.error{background:#fff0f0;color:#a51d1d}.grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}.section-head{display:flex;justify-content:space-between;align-items:center;gap:8px;margin-bottom:14px}.section-head h2{font-size:19px;margin:0}.count{background:#e8eefb;color:#31599d;border-radius:99px;padding:5px 10px;font-size:12px;font-weight:800}.input,.textarea{width:100%;border:1px solid #d3ddea;border-radius:10px;padding:11px;font:inherit;margin:5px 0 12px;background:#fcfdff}.textarea{min-height:80px;resize:vertical}.button{display:inline-flex;border:0;border-radius:10px;padding:11px 14px;font:inherit;font-weight:800;cursor:pointer;text-decoration:none;justify-content:center}.primary{background:#2563eb;color:white}.secondary{background:#edf2f8;color:#29415f}.danger{background:#fff0f0;color:#aa211b}.file-list{display:flex;flex-direction:column;gap:9px;margin-top:14px}.file-row{display:flex;align-items:center;gap:10px;border:1px solid #e3eaf3;border-radius:12px;padding:11px}.icon{width:37px;height:37px;border-radius:10px;background:#eff6ff;color:#2563eb;display:grid;place-items:center;font-size:11px;font-weight:900;flex:none}.file-info{min-width:0;flex:1}.file-name{font-weight:800;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.file-meta{font-size:12px;color:#7a8799;margin-top:3px}.file-actions{display:flex;gap:6px;flex:none}.empty{border:1px dashed #cbd6e5;border-radius:12px;padding:20px;text-align:center;color:#7b889a;font-size:13px}.device{border:1px solid #e3eaf3;border-radius:12px;padding:14px;margin-top:9px}.device-top{display:flex;justify-content:space-between;gap:10px}.device-info{min-width:0}.device-info strong{display:block}.device-info small{display:block;color:#718096;margin-top:4px;word-break:break-word}.badge{font-size:11px;border-radius:99px;padding:5px 9px;background:#e8f8ee;color:#176b3c;height:max-content;white-space:nowrap}.badge.blocked{background:#fff0f0;color:#a51d1d}.device form{margin-top:10px}.footer{text-align:center;color:#8794a7;font-size:12px}@media(max-width:720px){.grid{grid-template-columns:1fr}.title{font-size:23px}.file-row{align-items:flex-start;flex-wrap:wrap}.file-info{min-width:calc(100% - 50px)}.file-actions{width:100%;padding-left:47px}.file-actions .button{flex:1}.head{align-items:flex-start}}
</style></head><body><main class="shell"><?php if(!$logged):?><section class="card login"><div class="brand"><div class="logo">C</div><div><p class="eyebrow">CONFIGBOX ADMIN</p><h1 class="title">Entrar no painel</h1></div></div><p class="sub">Gerencie configurações, APK e aparelhos.</p><?php if($msg):?><div class="notice error"><?=h($msg)?></div><?php endif;?><form method="post"><input class="input" type="password" name="password" placeholder="Senha administrativa" required><button class="button primary" style="width:100%" name="login" value="1">Entrar</button></form></section><?php else:?><header class="head"><div class="brand"><div class="logo">C</div><div><p class="eyebrow">PAINEL ADMINISTRATIVO</p><h1 class="title">ConfigBox</h1><p class="sub">Arquivos e controle do ativador</p></div></div><a class="button secondary" href="?logout=1">Sair</a></header><div class="repo"><?=h(GITHUB_OWNER.'/'.GITHUB_REPOSITORY)?> · <?=h(GITHUB_BRANCH)?></div><?php if($msg):?><div class="notice <?=$error?'error':'success'?>"><?=h($msg)?></div><?php endif;?><section class="card"><div class="section-head"><h2>Controle do aplicativo</h2><span class="count">Acesso remoto</span></div><form method="post"><input type="hidden" name="csrf" value="<?=h(token())?>"><label><input type="checkbox" name="plan_active" <?=$settings['plan_active']?'checked':''?>> Ativador liberado</label><label class="muted">Mensagem quando bloqueado<textarea class="textarea" name="lock_message"><?=h($settings['lock_message']??'')?></textarea></label><label class="muted">Diálogo ao abrir o app<textarea class="textarea" name="dialog_message"><?=h($settings['dialog_message']??'')?></textarea></label><button class="button primary" name="save_control" value="1">Salvar controle</button></form></section><div class="grid"><section class="card"><div class="section-head"><h2>Configurações</h2><span class="count"><?=count($configs)?></span></div><form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf" value="<?=h(token())?>"><input class="input" type="file" name="configs[]" multiple><button class="button primary" name="upload_configs" value="1">Enviar configurações</button></form><div class="file-list"><?php if(!$configs):?><div class="empty">Nenhuma configuração em <b><?=h(GITHUB_CONFIG_FOLDER)?></b>.</div><?php else:foreach($configs as $f):?><div class="file-row"><div class="icon">CFG</div><div class="file-info"><div class="file-name" title="<?=h($f['name'])?>"><?=h($f['name'])?></div><div class="file-meta"><?=h(sizefmt($f['size']))?></div></div><div class="file-actions"><a class="button secondary" target="_blank" href="<?=h($f['url'])?>">Abrir</a><form method="post" onsubmit="return confirm('Excluir <?=h($f['name'])?>?')"><input type="hidden" name="csrf" value="<?=h(token())?>"><input type="hidden" name="name" value="<?=h($f['name'])?>"><input type="hidden" name="sha" value="<?=h($f['sha'])?>"><button class="button danger" name="delete_config" value="1">Excluir</button></form></div></div><?php endforeach;endif;?></div></section><section class="card"><div class="section-head"><h2>Link do APK UniTV</h2><span class="count">URL direta</span></div><p class="muted">Cole o link direto para o arquivo .apk. O botão do aplicativo consultará este endereço e abrirá a instalação depois do download.</p><form method="post"><input type="hidden" name="csrf" value="<?=h(token())?>"><input class="input" type="url" name="unitv_url" value="<?=h(($unitvLink['url']??''))?>" placeholder="https://servidor.com/unitv.apk"><button class="button primary" name="save_unitv_url" value="1">Salvar link do APK</button></form><p class="muted">O link fica salvo em <b>admin-data/unitv-link.json</b> no GitHub.</p></section></div><section class="card"><div class="section-head"><h2>Aparelhos cadastrados</h2><span class="count"><?=count($devices)?></span></div><?php if(!$devices):?><div class="empty">Nenhum aparelho consultou o Admin ainda.</div><?php else:foreach($devices as $d):?><div class="device"><div class="device-top"><div class="device-info"><strong><?=h($d['model']??'Modelo desconhecido')?></strong><small>Identificador (hash): <?=h(github_device_hash($d))?></small><small>Último acesso: <?=h($d['last_seen']??'não informado')?></small></div><span class="badge <?=$d['blocked']?'blocked':''?>"><?=$d['blocked']?'Bloqueado':'Liberado'?></span></div><form method="post"><input type="hidden" name="csrf" value="<?=h(token())?>"><input type="hidden" name="device_hash" value="<?=h(github_device_hash($d))?>"><input class="input" name="device_message" placeholder="Mensagem para este aparelho" value="<?=h($d['message']??'')?>"><button class="button <?=$d['blocked']?'primary':'danger'?>" name="device_action" value="<?=$d['blocked']?'unblock':'block'?>"><?=$d['blocked']?'Desbloquear aparelho':'Bloquear aparelho'?></button></form></div><?php endforeach;endif;?></section><p class="footer">Android ID é o identificador principal. O IMEI pode não estar disponível em versões recentes.</p><?php endif;?></main></body></html>
