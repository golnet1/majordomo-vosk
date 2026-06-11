<?php
if (PHP_SAPI === 'cli') return;
if (!isset($_SERVER['REQUEST_URI'])) return;
$uri = $_SERVER['REQUEST_URI'];
if (preg_match('#/(admin|popup/)#', $uri)) return;
if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') return;

// Read cached config for inline injection
$cfgFile = __DIR__ . '/prepend_config.json';
$triggerPhrases = '[]';
$apiUrl = '/api.php/module/vosk/';
if (file_exists($cfgFile)) {
    $cfg = json_decode(file_get_contents($cfgFile), true);
    if ($cfg && !empty($cfg['phrases'])) {
        $triggerPhrases = json_encode($cfg['phrases'], JSON_UNESCAPED_UNICODE);
    }
    if ($cfg && !empty($cfg['apiUrl'])) {
        $apiUrl = $cfg['apiUrl'];
    }
}

ob_start(function ($buffer) use ($triggerPhrases, $apiUrl) {
    $ct = '';
    foreach (headers_list() as $h) {
        if (stripos($h, 'Content-Type:') === 0) {
            $ct = trim(substr($h, 13));
            break;
        }
    }
    if (strpos($ct, 'text/html') === false && strpos($ct, 'text/plain') === false) {
        return $buffer;
    }
    $isGzip = strlen($buffer) > 2 && ord($buffer[0]) === 0x1f && ord($buffer[1]) === 0x8b;
    if ($isGzip) {
        $buffer = gzdecode($buffer);
        if ($buffer === false) return false;
    }
    $cacheBust = filemtime(__FILE__);
    $script = '<script>'
        . 'window.VOSK_CONFIG=window.VOSK_CONFIG||{};'
        . 'window.VOSK_CONFIG.triggerPhrases=' . $triggerPhrases . ';'
        . 'window.VOSK_CONFIG.apiUrl="' . $apiUrl . '";'
        . '</script>'
        . '<script src="/templates/vosk/js/vosk.js?' . $cacheBust . '"></script>';
    if (($pos = stripos($buffer, '</body>')) !== false) {
        $buffer = substr_replace($buffer, $script . "\n</body>", $pos, 7);
    } else {
        $buffer .= "\n" . $script;
    }
    if ($isGzip) {
        $buffer = gzencode($buffer);
    }
    return $buffer;
});
