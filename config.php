<?php
define('GITHUB_TOKEN', getenv('GITHUB_TOKEN') ?: '');
// Mantidos os valores existentes no projeto; somente GITHUB_TOKEN requer variável no Render.
define('ADMIN_PASSWORD', 'digital');
define('PUBLIC_APP_KEY', 'desenvolvedorviniciusbb1');
define('GITHUB_OWNER', (string) (getenv('GITHUB_OWNER') ?: 'viniciusoficial'));
define('GITHUB_REPOSITORY', (string) (getenv('GITHUB_REPOSITORY') ?: 'painelconfig_rev_unitvapp04'));
define('GITHUB_BRANCH', (string) (getenv('GITHUB_BRANCH') ?: 'main'));
define('GITHUB_CONFIG_FOLDER', (string) (getenv('GITHUB_CONFIG_FOLDER') ?: 'configs'));
define('GITHUB_APK_FOLDER', (string) (getenv('GITHUB_APK_FOLDER') ?: 'unitvapk'));
define('GITHUB_DATA_FOLDER', (string) (getenv('GITHUB_DATA_FOLDER') ?: 'admin-data'));
define('GITHUB_DEVICES_FILE', (string) (getenv('GITHUB_DEVICES_FILE') ?: 'devices.json'));
