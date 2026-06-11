<?php
$opts = getopt('', array('model-id:', 'models-dir:'));
$modelId = $opts['model-id'] ?? '';
$modelsDir = $opts['models-dir'] ?? '/opt/vosk/models';
$lockFile = '/tmp/vosk_install_' . $modelId . '.lock';

if (!$modelId) {
    echo "Error: --model-id is required\n";
    if (file_exists($lockFile)) @unlink($lockFile);
    exit(1);
}

$models = array(
    'vosk-model-small-ru-0.22' => array('url' => 'https://github.com/kercre123/vosk-models/raw/main/vosk-model-small-ru-0.22.zip', 'type' => 'vosk'),
    'vosk-model-ru-0.22' => array('url' => 'https://alphacephei.com/vosk/models/vosk-model-ru-0.22.zip', 'type' => 'vosk'),
    'vosk-model-ru-0.42' => array('url' => 'https://github.com/mikl-tmp/Vosk-models-for-SVI/raw/main/vosk-model-ru-0.42.zip', 'type' => 'vosk'),
    'vosk-model-ru-0.54' => array('url' => '', 'type' => 'sherpa-onnx', 'git' => 'https://huggingface.co/alphacep/vosk-model-ru'),
);

$modelUrl = $models[$modelId]['url'] ?? '';
$modelType = $models[$modelId]['type'] ?? '';
$modelGit = $models[$modelId]['git'] ?? '';

if (!$modelUrl && !$modelGit) {
    echo "Error: unknown model $modelId\n";
    if (file_exists($lockFile)) @unlink($lockFile);
    exit(1);
}

$destDir = $modelsDir . '/' . $modelId;

if ($modelGit) {
    if (is_dir($destDir)) {
        exec('rm -rf ' . escapeshellarg($destDir));
    }
    echo "Cloning $modelId from $modelGit...\n";
    exec('git clone --depth 1 ' . escapeshellarg($modelGit) . ' ' . escapeshellarg($destDir) . ' 2>&1', $out, $ret);
    echo implode("\n", $out) . "\n";
    echo "git clone exit code: $ret\n";
    if ($ret == 0) {
        echo "Clone complete: $modelId\n";
        if ($modelType === 'sherpa-onnx') {
            echo "Installing sherpa-onnx Python package...\n";
            exec('/usr/bin/pip3 install sherpa-onnx 2>&1', $out, $ret);
            echo "pip3 install exit code: $ret\n";
        }
    } else {
        if (is_dir($destDir)) {
            exec('rm -rf ' . escapeshellarg($destDir));
        }
        echo "Clone failed\n";
    }
} else {
    if (!is_dir($destDir)) {
        mkdir($destDir, 0755, true);
    }

    $zipFile = $destDir . '/' . $modelId . '.zip';
    echo "Downloading $modelId...\n";

    $fp = fopen($zipFile, 'w');
    $ch = curl_init($modelUrl);
    curl_setopt($ch, CURLOPT_FILE, $fp);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 3600);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');
    curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    fclose($fp);

    echo "HTTP code: $httpCode\n";
    if ($error) echo "curl error: $error\n";

    if ($httpCode == 200) {
        $zip = new ZipArchive();
        if ($zip->open($zipFile) === true) {
            $zip->extractTo($modelsDir);
            $zip->close();
            echo "Extracted to $modelsDir\n";
        } else {
            echo "Error: cannot open zip file\n";
        }
        @unlink($zipFile);
        echo "Download complete: $modelId\n";
    } else {
        @unlink($zipFile);
        if (is_dir($destDir)) {
            exec('rm -rf ' . escapeshellarg($destDir));
        }
        echo "Download failed (HTTP $httpCode)\n";
    }
}

if (file_exists($lockFile)) @unlink($lockFile);
