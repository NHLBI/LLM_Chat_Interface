#!/usr/bin/env php
<?php
$path = getenv('CHAT_CONFIG_PATH') ?: '/etc/apps/chat_config.ini';
$cfg = parse_ini_file($path, true, INI_SCANNER_RAW);
if ($cfg === false) {
    fwrite(STDERR, "Unable to read $path\n");
    exit(1);
}
$defaultRaw = $cfg['azure']['default'] ?? '';
$default = trim($defaultRaw, "\"' ");
if ($default === '' || !isset($cfg[$default])) {
    fwrite(STDERR, "Azure default deployment missing\n");
    exit(1);
}
$section = $cfg[$default];
$endpoint = rtrim(trim($section['url'] ?? '', "\"' "), '/');
$apiKey = trim($section['api_key'] ?? '', "\"' ");
if ($endpoint === '' || $apiKey === '') {
    fwrite(STDERR, "Azure endpoint or api_key missing for $default\n");
    exit(1);
}
printf("%s\n%s", $endpoint, $apiKey);
