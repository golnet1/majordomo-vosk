<?php

class vosk extends module
{
    function __construct()
    {
        $this->name = 'vosk';
        $this->title = 'Vosk ASR';
        $this->module_category = '<#LANG_SECTION_APPLICATIONS#>';
        $this->checkInstalled();
    }

    function saveParams($data = 0)
    {
        $p = array();
        if (isset($this->id))        $p['id'] = $this->id;
        if (isset($this->view_mode)) $p['view_mode'] = $this->view_mode;
        if (isset($this->edit_mode)) $p['edit_mode'] = $this->edit_mode;
        if (isset($this->tab))       $p['tab'] = $this->tab;
        return parent::saveParams($p);
    }

    function getParams()
    {
        global $id, $mode, $view_mode, $edit_mode, $tab;
        if (isset($id))        $this->id = $id;
        if (isset($mode))      $this->mode = $mode;
        if (isset($view_mode)) $this->view_mode = $view_mode;
        if (isset($edit_mode)) $this->edit_mode = $edit_mode;
        if (isset($tab))       $this->tab = $tab;
    }

    function getConfig()
    {
        parent::getConfig();
        if (!isset($this->config['MODEL_PATH'])) {
            $this->config['MODEL_PATH'] = '/opt/vosk/models/vosk-model-small-ru-0.22';
        }
        if (!isset($this->config['MODELS_DIR'])) {
            $this->config['MODELS_DIR'] = '/opt/vosk/models';
        }
        if (!isset($this->config['PYTHON_BIN'])) {
            $this->config['PYTHON_BIN'] = '/usr/bin/python3';
        }
        if (!isset($this->config['TRIGGER_PHRASE'])) {
            $this->config['TRIGGER_PHRASE'] = '';
        }
        if (!isset($this->config['AUTO_START'])) {
            $this->config['AUTO_START'] = '0';
        }
        if (!isset($this->config['MEMBER_ID'])) {
            $this->config['MEMBER_ID'] = '0';
        }
        if (!isset($this->config['REMOTE_ASR_URL'])) {
            $this->config['REMOTE_ASR_URL'] = '';
        }
        $this->writePrependConfig();
    }

    function run()
    {
        $out = array();
        if ($this->action == 'admin') {
            $this->admin($out);
        } else {
            $this->usual($out);
        }
        if (isset($this->owner->action)) $out['PARENT_ACTION'] = $this->owner->action;
        if (isset($this->owner->name))   $out['PARENT_NAME'] = $this->owner->name;
        $out['VIEW_MODE'] = $this->view_mode;
        $out['EDIT_MODE'] = $this->edit_mode;
        $out['MODE'] = $this->mode;
        $out['ACTION'] = $this->action;
        $this->data = $out;
        $p = new parser(DIR_TEMPLATES . $this->name . '/' . $this->name . '.html', $this->data, $this);
        $this->result = $p->result;
    }

    function admin(&$out)
    {
        $this->getConfig();

        $cmd = gr('cmd');

        if ($cmd == 'install_local') {
            $scriptDir = $this->getScriptDir();
            $pythonBin = $this->config['PYTHON_BIN'];
            $phpBin = PHP_BINDIR . '/php';
            if (!file_exists($phpBin)) $phpBin = 'php';
            $lockFile = '/tmp/vosk_install_local.lock';
            file_put_contents($lockFile, 'installing');
            $logFile = '/tmp/vosk_install_local.log';
            $reqFile = $scriptDir . '/requirements.txt';
            $cmdLine = $pythonBin . ' -m pip install -r ' . escapeshellarg($reqFile) . ' 2>&1 && '
                . $phpBin . ' ' . escapeshellarg($scriptDir . '/install_model.php')
                . ' --model-id vosk-model-small-ru-0.22'
                . ' --models-dir ' . escapeshellarg($this->config['MODELS_DIR'])
                . ' > ' . escapeshellarg($logFile) . ' 2>&1 &';
            file_put_contents($logFile, "Installing local server...\n");
            exec($cmdLine);
            $this->redirect('?action=vosk&tab=status');
        }
        if ($cmd == 'install_model') {
            $modelId = gr('model_id');
            $remoteUrl = $this->config['REMOTE_ASR_URL'];

            if (!empty($remoteUrl)) {
                $this->callRemoteAsync('POST', '/models/install', array('id' => $modelId));
                $this->showPleaseWait('Установка модели ' . htmlspecialchars($modelId) . '...', 'Загрузка запущена на удалённом сервере в фоновом режиме.');
            } else {
                $scriptDir = $this->getScriptDir();
                $phpBin = PHP_BINDIR . '/php';
                if (!file_exists($phpBin)) $phpBin = 'php';
                $lockFile = '/tmp/vosk_install_' . $modelId . '.lock';
                file_put_contents($lockFile, 'installing');
                $logFile = '/tmp/vosk_install_' . $modelId . '.log';
                $cmdLine = $phpBin . ' ' . escapeshellarg($scriptDir . '/install_model.php')
                    . ' --model-id ' . escapeshellarg($modelId)
                    . ' --models-dir ' . escapeshellarg($this->config['MODELS_DIR'])
                    . ' > ' . escapeshellarg($logFile) . ' 2>&1 &';
                exec($cmdLine);
                $this->redirect('?action=vosk&tab=models');
            }
        }
        if ($cmd == 'clear_lock') {
            $modelId = gr('model_id');
            $lockFile = '/tmp/vosk_install_' . $modelId . '.lock';
            if (file_exists($lockFile)) @unlink($lockFile);
            $this->redirect('?action=vosk&tab=models');
        }

        if ($cmd == 'delete_model') {
            $modelId = gr('model_id');
            $remoteUrl = $this->config['REMOTE_ASR_URL'];

            if (!empty($remoteUrl)) {
                $result = $this->callRemote('POST', '/models/delete', array('id' => $modelId));
                $this->redirectAfterCmd($result, 'удалена');
            } else {
                $modelDir = $this->config['MODELS_DIR'] . '/' . $modelId;
                $deleted = false;
                if (is_dir($modelDir)) {
                    exec('sudo rm -rf ' . escapeshellarg($modelDir) . ' 2>&1', $rmOut, $rmRet);
                    $deleted = ($rmRet === 0);
                }
                if ($deleted) {
                    $json = json_encode(array('id' => $modelId));
                    $ch = curl_init('http://127.0.0.1:5001/models/delete');
                    curl_setopt_array($ch, array(
                        CURLOPT_POST => true,
                        CURLOPT_POSTFIELDS => $json,
                        CURLOPT_HTTPHEADER => array('Content-Type: application/json'),
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_TIMEOUT => 5,
                    ));
                    curl_exec($ch);
                    curl_close($ch);
                }
                $this->redirectAfterCmd($deleted ? null : array('error' => 'Не удалось удалить'), 'удалена');
            }
        }

        if ($cmd == 'activate_model') {
            $modelId = gr('model_id');
            $remoteUrl = $this->config['REMOTE_ASR_URL'];
            $modelDir = $this->config['MODELS_DIR'] . '/' . $modelId;

            if (!empty($remoteUrl)) {
                $this->callRemoteAsync('POST', '/models/activate', array('path' => $modelId));
                $this->showPleaseWait('Активация модели ' . htmlspecialchars($modelId) . '...', 'Запрос отправлен на удалённый сервер.');
            } else {
                if (is_dir($modelDir)) {
                    $this->config['MODEL_PATH'] = $modelDir;
                    $this->saveConfig();
                    $json = json_encode(array('path' => $modelDir));
                    $ch = curl_init('http://127.0.0.1:5001/models/activate');
                    curl_setopt_array($ch, array(
                        CURLOPT_POST => true,
                        CURLOPT_POSTFIELDS => $json,
                        CURLOPT_HTTPHEADER => array('Content-Type: application/json'),
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_TIMEOUT => 5,
                    ));
                    curl_exec($ch);
                    curl_close($ch);
                }
            }
            while (ob_get_level()) ob_end_clean();
            header('Content-Type: text/html; charset=utf-8');
            echo '<html><body style="font-family:sans-serif;padding:40px;text-align:center">';
            echo '<p>Модель активирована. Страница обновится...</p>';
        echo '<script>setTimeout(function(){window.location.href="' . '?action=vosk&tab=models' . '"},3000);</script>';
            echo '</body></html>';
            exit;
        }

        if ($this->view_mode == 'update_settings') {
            $this->config['PYTHON_BIN'] = gr('python_bin', $this->config['PYTHON_BIN']);
            $this->config['TRIGGER_PHRASE'] = gr('trigger_phrase', $this->config['TRIGGER_PHRASE']);
            $this->config['AUTO_START'] = gr('auto_start', 0) ? '1' : '0';

            $remote = trim(gr('remote_asr_url', ''));
            if (!empty($remote)) {
                if (!preg_match('#^https?://#', $remote)) {
                    $remote = 'http://' . $remote;
                }
                $parts = parse_url($remote);
                $host = $parts['host'] ?? '';
                $port = $parts['port'] ?? '5001';
                $scheme = $parts['scheme'] ?? 'http';
                $this->config['REMOTE_ASR_URL'] = $scheme . '://' . $host . ':' . $port . '/recognize';
            } else {
                $this->config['REMOTE_ASR_URL'] = '';
            }

            $this->saveConfig();
            $this->writePrependConfig();
            $this->redirect('?action=vosk&tab=settings');
        }

        $remoteUrl = $this->config['REMOTE_ASR_URL'];
        if (!empty($remoteUrl)) {
            $rm = $this->callRemote('GET', '/models');
            $connected = $rm && isset($rm['models']);
            $activePath = $connected ? ($rm['active'] ?? '') : '';
            $out['MODEL_NAME'] = $activePath ? basename($activePath) : '—';
            $out['MODEL_PATH'] = $activePath;
            $out['MODEL_EXISTS'] = $activePath ? '1' : '0';
            $out['REMOTE_CONNECTED'] = $connected ? '1' : '0';
        } else {
            $modelPath = $this->config['MODEL_PATH'];
            $out['MODEL_NAME'] = basename($modelPath);
            $out['MODEL_PATH'] = $modelPath;
            $out['MODEL_EXISTS'] = is_dir($modelPath) ? '1' : '0';
            $pythonBin = $this->config['PYTHON_BIN'];
            $scriptDir = $this->getScriptDir();
            $serverScript = $scriptDir . '/vosk_server.py';
            $pythonOk = file_exists($pythonBin) && is_executable($pythonBin);
            $scriptOk = file_exists($serverScript);
            $reqOk = false;
            if ($pythonOk) {
                exec($pythonBin . ' -c "import vosk" 2>/dev/null', $trash, $code);
                $reqOk = ($code === 0);
            }
            $lockFile = '/tmp/vosk_install_local.lock';
            $lockExists = file_exists($lockFile);
            if ($lockExists) {
                $out['LOCAL_INSTALLED'] = '2';
            } else {
                $out['LOCAL_INSTALLED'] = ($pythonOk && $scriptOk && $reqOk) ? '1' : '0';
            }
            $out['REMOTE_CONNECTED'] = '1';
        }
        $out['PYTHON_BIN'] = $this->config['PYTHON_BIN'];
        $out['TRIGGER_PHRASE'] = $this->config['TRIGGER_PHRASE'];
        $out['TRIGGER_PHRASES'] = json_encode($this->parseTriggerPhrases(), JSON_UNESCAPED_UNICODE);
        $out['AUTO_START'] = $this->config['AUTO_START'] == '1' ? 'checked' : '';

        if (!empty($remoteUrl)) {
            $parts = parse_url($remoteUrl);
            $out['REMOTE_ASR_URL'] = ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '');
        } else {
            $out['REMOTE_ASR_URL'] = '';
        }
        $out['IS_REMOTE'] = !empty($this->config['REMOTE_ASR_URL']) ? '1' : '0';
        $out['LAST_TEXT'] = $this->getLastRecognizedText();
        $out['MODELS_DIR'] = $this->config['MODELS_DIR'];
        $out['AVAILABLE_MODELS'] = $this->getAvailableModels();

        $tab = gr('tab');
        if (!$tab) $tab = 'status';
        $out['TAB'] = $tab;
        $out['TAB_STATUS'] = ($tab == 'status') ? '1' : '0';
        $out['TAB_SETTINGS'] = ($tab == 'settings') ? '1' : '0';
        $out['TAB_MODELS'] = ($tab == 'models') ? '1' : '0';
        $out['TAB_HELP'] = ($tab == 'help') ? '1' : '0';
    }

    function usual(&$out)
    {
        $this->getConfig();
        $out['TRIGGER_PHRASE'] = $this->config['TRIGGER_PHRASE'];
        $out['TRIGGER_PHRASES'] = json_encode($this->parseTriggerPhrases(), JSON_UNESCAPED_UNICODE);
        $out['IS_ADMIN'] = '0';
    }

    private function getScriptDir()
    {
        return DIR_MODULES . $this->name . '/lib';
    }

    private function getCurrentUserId()
    {
        if (session_status() === PHP_SESSION_NONE) {
            @session_name('prj');
            @session_start();
        }
        if (isset($_SESSION['DATA'])) {
            $data = @unserialize($_SESSION['DATA']);
            if (isset($data['SITE_USER_ID'])) {
                return (int)$data['SITE_USER_ID'];
            }
        }
        return 0;
    }

    private function callRemoteAsync($method, $path, $data = null)
    {
        $base = $this->getRemoteBaseUrl();
        if (!$base) return;
        $url = $base . $path;
        $json = $data ? json_encode($data) : '';
        $json = str_replace("'", "'\\''", $json);
        $cmd = "curl -s -X $method -H 'Content-Type: application/json'";
        if ($json) $cmd .= " -d '$json'";
        $cmd .= ' ' . escapeshellarg($url) . ' > /dev/null 2>&1 &';
        exec($cmd);
    }

    private function showPleaseWait($title, $message)
    {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: text/html; charset=utf-8');
        echo '<html><body style="font-family:sans-serif;padding:40px;text-align:center">';
        echo '<h2>' . $title . '</h2>';
        echo '<p>' . htmlspecialchars($message) . '</p>';
        echo '<p>Страница обновится через 3 секунды...</p>';
        echo '<script>setTimeout(function(){window.location.href="' . '?action=vosk&tab=models' . '"},3000);</script>';
        echo '</body></html>';
        exit;
    }

    private function redirectAfterCmd($result, $actionText)
    {
        while (ob_get_level()) ob_end_clean();
        $msg = 'Модель ' . $actionText;
        if ($result === null || (isset($result['ok']) && $result['ok'])) {
            $msg .= '. Страница обновится...';
        } else {
            $err = isset($result['error']) ? htmlspecialchars($result['error']) : 'ошибка';
            $msg = 'Ошибка: ' . $err;
        }
        header('Content-Type: text/html; charset=utf-8');
        echo '<html><body style="font-family:sans-serif;padding:40px;text-align:center">';
        echo '<p>' . $msg . '</p>';
        echo '<script>setTimeout(function(){window.location.href="' . '?action=vosk&tab=models' . '"},3000);</script>';
        echo '</body></html>';
        exit;
    }

    private function getStatusFile()
    {
        return '/tmp/vosk_status.json';
    }

    function getLastRecognizedText()
    {
        $statusFile = $this->getStatusFile();
        if (!file_exists($statusFile)) return '';
        $data = json_decode(file_get_contents($statusFile), true);
        return isset($data['last_text']) ? $data['last_text'] : '';
    }

    private function writePrependConfig()
    {
        $phrases = $this->parseTriggerPhrases();
        $cfgFile = DIR_MODULES . $this->name . '/prepend_config.json';
        file_put_contents($cfgFile, json_encode(array(
            'phrases' => $phrases,
            'apiUrl' => '/api.php/module/vosk/',
        ), JSON_UNESCAPED_UNICODE));
    }

    private function getRemoteBaseUrl()
    {
        $url = $this->config['REMOTE_ASR_URL'];
        if (empty($url)) return '';
        $parts = parse_url($url);
        $base = $parts['scheme'] . '://' . $parts['host'];
        if (isset($parts['port'])) $base .= ':' . $parts['port'];
        return $base;
    }

    private function callRemote($method, $path, $data = null, $timeout = 10)
    {
        $base = $this->getRemoteBaseUrl();
        if (!$base) return null;
        $url = $base . $path;
        $ch = curl_init($url);
        $opts = array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
        );
        if ($method === 'POST') {
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = is_string($data) ? $data : json_encode($data);
            $opts[CURLOPT_HTTPHEADER] = array('Content-Type: application/json');
        }
        curl_setopt_array($ch, $opts);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $parsed = json_decode($response, true);
        if ($httpCode != 200) {
            return $parsed ?: array('error' => 'HTTP ' . $httpCode);
        }
        return $parsed;
    }

    private function getAvailableModels()
    {
        $remoteUrl = $this->config['REMOTE_ASR_URL'];

        // Удалённый режим
        if (!empty($remoteUrl)) {
            $remoteResult = $this->callRemote('GET', '/models');
            $remoteModels = array();
            $activePath = '';
            $remoteError = false;
            if ($remoteResult && isset($remoteResult['models'])) {
                $activePath = $remoteResult['active'] ?? '';
                foreach ($remoteResult['models'] as $m) {
                    $remoteModels[$m['id']] = $m;
                }
            } else {
                $remoteError = true;
            }

            $models = $this->getPredefinedModels();
            $result = array();
            foreach ($models as $m) {
                $id = $m['ID'];
                $rm = isset($remoteModels[$id]) ? $remoteModels[$id] : null;
                $installing = $rm && !empty($rm['installing']);
                $installed = $rm !== null && !$installing;
                $isActive = $installed && ($rm['path'] === $activePath) ? '1' : '0';
                $m['TYPE'] = ($rm && isset($rm['type'])) ? $rm['type'] : $m['TYPE'];
                $m['INSTALLED'] = $installed ? '1' : '0';
                $m['ACTIVE'] = $isActive;
                $m['INSTALLING'] = $installing ? '1' : '0';
                $m['DESC'] = $installed ? 'На удалённом сервере' : ($installing ? 'Загрузка...' : $m['DESC']);
                $result[] = $m;
            }

            // Если удалённый не ответил — показать заглушку
            if ($remoteError) {
                return array(array(
                    'ID' => 'remote_error',
                    'TITLE' => 'Ошибка подключения к удалённому серверу',
                    'SIZE' => '', 'TYPE' => '', 'WER' => '', 'URL' => '', 'DESC' => '',
                    'INSTALLED' => '0', 'ACTIVE' => '0', 'INSTALLING' => '0',
                ));
            }

            return $result;
        }

        // Локальный режим
        $modelsDir = $this->config['MODELS_DIR'];
        $current = $this->config['MODEL_PATH'];

        $models = $this->getPredefinedModels();

        foreach ($models as &$m) {
            $dir = $modelsDir . '/' . $m['ID'];
            $installed = false;
            if (is_dir($dir)) {
                $items = array_diff(scandir($dir), array('.', '..'));
                $hasModelDir = false;
                foreach ($items as $item) {
                    $itemPath = $dir . '/' . $item;
                    if (is_dir($itemPath) && $item !== $m['ID']) {
                        $hasModelDir = true;
                        break;
                    }
                }
                $installed = $hasModelDir;
            }
            $lockFile = '/tmp/vosk_install_' . $m['ID'] . '.lock';
            $lockExists = file_exists($lockFile);
            if ($lockExists && $installed) {
                @unlink($lockFile);
                $lockExists = false;
            }
            $m['INSTALLED'] = $installed ? '1' : '0';
            $m['ACTIVE'] = ($installed && $current === $dir) ? '1' : '0';
            $m['INSTALLING'] = $lockExists ? '1' : '0';
        }

        return $models;
    }

    private function getPredefinedModels()
    {
        return array(
            array(
                'ID' => 'vosk-model-small-ru-0.22',
                'TITLE' => 'Русский (малая)',
                'SIZE' => '88MB',
                'TYPE' => 'vosk',
                'WER' => '~23%',
                'URL' => 'https://github.com/kercre123/vosk-models/raw/main/vosk-model-small-ru-0.22.zip',
                'DESC' => 'Малая модель, быстрая, для Raspberry Pi/Android',
            ),
            array(
                'ID' => 'vosk-model-ru-0.22',
                'TITLE' => 'Русский (большая 0.22)',
                'SIZE' => '1.5GB',
                'TYPE' => 'vosk',
                'WER' => '~5.7%',
                'URL' => 'https://alphacephei.com/vosk/models/vosk-model-ru-0.22.zip',
                'DESC' => 'Большая модель для сервера, Kaldi nnet3',
            ),
            array(
                'ID' => 'vosk-model-ru-0.42',
                'TITLE' => 'Русский (большая 0.42)',
                'SIZE' => '1.8GB',
                'TYPE' => 'vosk',
                'WER' => '~4.5%',
                'URL' => 'https://github.com/mikl-tmp/Vosk-models-for-SVI/raw/main/vosk-model-ru-0.42.zip',
                'DESC' => 'Большая модель для сервера, улучшенная',
            ),
            array(
                'ID' => 'vosk-model-ru-0.54',
                'TITLE' => 'Русский (Zipformer2)',
                'SIZE' => '967MB',
                'TYPE' => 'sherpa-onnx',
                'WER' => '~6.1%',
                'URL' => 'https://huggingface.co/alphacep/vosk-model-ru',
                'DESC' => 'Zipformer2 (icefall). Установка: git clone https://huggingface.co/alphacep/vosk-model-ru',
            ),
        );
    }

    private function installSherpaOnnxDeps()
    {
        exec('/usr/bin/pip3 install sherpa-onnx 2>&1', $out, $ret);
    }

    function api($params)
    {
        $action = $params['request'][0] ?? '';

        if ($action == 'config') {
            $this->getConfig();
            $phrases = $this->parseTriggerPhrases();
            header('Content-Type: application/json');
            echo json_encode(array(
                'triggerPhrase' => $phrases[0] ?? '',
                'triggerPhrases' => $phrases,
                'apiUrl' => '/api.php/module/vosk/',
            ));
            exit;
        }

        if ($action == 'status') {
            $this->getConfig();
            $remoteUrl = $this->config['REMOTE_ASR_URL'];
            $connected = false;
            $active = '';
            if (!empty($remoteUrl)) {
                $rm = $this->callRemote('GET', '/models');
                $connected = $rm && isset($rm['models']);
                $active = $connected ? ($rm['active'] ?? '') : '';
            }
            $pythonOk = file_exists($this->config['PYTHON_BIN']);
            $scriptOk = file_exists($this->getScriptDir() . '/vosk_server.py');
            $reqOk = file_exists($this->getScriptDir() . '/requirements.txt');
            $lockFile = '/tmp/vosk_install_local.lock';
            $installing = file_exists($lockFile);
            $installed = $pythonOk && $scriptOk && $reqOk && !$installing;
            header('Content-Type: application/json');
            echo json_encode(array(
                'connected' => $connected,
                'is_remote' => !empty($remoteUrl),
                'active_model' => $active ? basename($active) : '',
                'active_path' => $active,
                'local_installed' => $installing ? '2' : ($installed ? '1' : '0'),
            ));
            exit;
        }

        if ($action == 'recognize') {
            $this->getConfig();

            $audioData = isset($params['raw_input']) ? $params['raw_input'] : file_get_contents('php://input');
            $text = '';

            if (!empty($this->config['REMOTE_ASR_URL'])) {
                $ch = curl_init($this->config['REMOTE_ASR_URL']);
                curl_setopt_array($ch, array(
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => $audioData,
                    CURLOPT_HTTPHEADER => array('Content-Type: audio/wav'),
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 30,
                ));
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                if ($httpCode == 200) {
                    $data = json_decode($response, true);
                    if ($data && isset($data['text'])) {
                        $text = trim($data['text']);
                    }
                }
            } else {
                $modelPath = $this->config['MODEL_PATH'];
                if (!is_dir($modelPath)) {
                    header('Content-Type: application/json');
                    echo json_encode(array('error' => 'Model not found'));
                    exit;
                }

                $tmpFile = tempnam(sys_get_temp_dir(), 'vosk_') . '.wav';
                file_put_contents($tmpFile, $audioData);

                $pythonBin = $this->config['PYTHON_BIN'];
                $scriptDir = $this->getScriptDir();
                $cmd = $pythonBin . ' ' . escapeshellarg($scriptDir . '/vosk_asr.py')
                    . ' --model ' . escapeshellarg($modelPath)
                    . ' --file ' . escapeshellarg($tmpFile);

                $output = array();
                exec($cmd . ' 2>/dev/null', $output, $ret);
                @unlink($tmpFile);

                $result = implode('', $output);
                $data = json_decode($result, true);
                if ($data && isset($data['text'])) {
                    $text = trim($data['text']);
                }
            }

            if ($text) $this->updateStatusFile($text);

            header('Content-Type: application/json');
            echo json_encode(array('text' => $text, 'success' => !empty($text)));
            exit;
        }

        if ($action == 'recognized') {
            $input = json_decode(file_get_contents('php://input'), true);
            if (isset($input['text']) && !empty($input['text'])) {
                $this->getConfig();
                $this->processRecognizedText(trim($input['text']));
            }
            header('Content-Type: application/json');
            echo json_encode(array('ok' => true));
            exit;
        }

        if ($action == 'process') {
            $input = json_decode($params['raw_input'], true);
            if (!$input) $input = array();
            $text = isset($input['text']) ? trim($input['text']) : '';
            if (!empty($text)) {
                $this->getConfig();
                $userId = $this->getCurrentUserId();
                if (!$userId) $userId = (int)$this->config['MEMBER_ID'];
                say($text, 0, $userId, 'voice');
            }
            header('Content-Type: application/json');
            echo json_encode(array('ok' => true, 'text' => $text));
            exit;
        }
    }

    private function parseTriggerPhrases()
    {
        $raw = $this->config['TRIGGER_PHRASE'] ?? '';
        $parts = preg_split('/[,;]/', $raw);
        $phrases = array();
        foreach ($parts as $p) {
            $p = trim(mb_strtolower($p));
            if ($p !== '') {
                $phrases[] = $p;
            }
        }
        if (empty($phrases)) {
            $phrases[] = 'мажордом';
        }
        return $phrases;
    }

    private function processRecognizedText($text)
    {
        $text = mb_strtolower($text);
        $this->updateStatusFile($text);
        $triggers = $this->parseTriggerPhrases();
        $userId = $this->getCurrentUserId();
        if (!$userId) $userId = (int)$this->config['MEMBER_ID'];

        if (!empty($triggers)) {
            foreach ($triggers as $trigger) {
                if (mb_strpos($text, $trigger) === 0) {
                    $command = trim(mb_substr($text, mb_strlen($trigger)));
                    if (!empty($command)) {
                        say($command, 0, $userId, 'voice');
                    }
                    break;
                }
            }
        } else {
            say($text, 0, $userId, 'voice');
        }
    }

    private function updateStatusFile($text)
    {
        $statusFile = $this->getStatusFile();
        $data = array(
            'last_text' => $text,
            'last_time' => time(),
        );
        file_put_contents($statusFile, json_encode($data));
    }

    function processCycle()
    {
    }

    function install($data = '')
    {
        parent::install();

        // --- Управление .htaccess и modules/prepend.php ---
        $loaderPath = ROOT . 'modules/prepend.php';
        $htaccessPath = ROOT . '.htaccess';

        // Создать modules/prepend.php, если нет
        if (!file_exists($loaderPath)) {
            $content = <<<'PHP'
<?php
if (PHP_SAPI === 'cli') return;

$configFile = __DIR__ . '/../config.php';
if (!file_exists($configFile)) return;
include_once $configFile;

$link = @mysqli_connect(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
if (!$link) return;

$result = mysqli_query($link, "SELECT NAME FROM project_modules WHERE HIDDEN=0");
if (!$result) { mysqli_close($link); return; }

while ($row = mysqli_fetch_assoc($result)) {
    $prepend = __DIR__ . '/' . $row['NAME'] . '/prepend.php';
    if (file_exists($prepend)) {
        include_once $prepend;
    }
}

mysqli_close($link);
PHP;
            file_put_contents($loaderPath, $content);
        }

        // Удалить старую Piper-строку (если есть) — чтобы не было двух директив
        if (file_exists($htaccessPath)) {
            $htContent = file_get_contents($htaccessPath);
            $oldLine = 'php_value auto_prepend_file ' . ROOT . 'modules/piper_tts/prepend.php';
            $htContent = str_replace(array($oldLine . "\r\n", $oldLine . "\n", $oldLine), '', $htContent);
            file_put_contents($htaccessPath, $htContent);
        }

        // Добавить строку с modules/prepend.php, если её нет
        if (file_exists($htaccessPath)) {
            $htContent = file_get_contents($htaccessPath);
            $line = 'php_value auto_prepend_file ' . $loaderPath;
            if (strpos($htContent, 'modules/prepend.php') === false) {
                file_put_contents($htaccessPath, $line . "\n" . $htContent);
            }
        }
    }

    function uninstall()
    {
        $statusFile = $this->getStatusFile();
        if (file_exists($statusFile)) @unlink($statusFile);

        // --- Управление .htaccess и modules/prepend.php ---
        $loaderPath = ROOT . 'modules/prepend.php';
        $htaccessPath = ROOT . '.htaccess';

        $piperInstalled = false;
        $link = @mysqli_connect(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
        if ($link) {
            $res = mysqli_query($link, "SELECT ID FROM project_modules WHERE NAME='piper_tts' AND HIDDEN=0");
            if ($res && mysqli_num_rows($res) > 0) {
                $piperInstalled = true;
            }
            mysqli_close($link);
        }

        // Piper не установлен — можно чистить
        if (!$piperInstalled) {
            if (file_exists($htaccessPath)) {
                $htContent = file_get_contents($htaccessPath);
                $line = 'php_value auto_prepend_file ' . $loaderPath;
                $htContent = str_replace(array($line . "\r\n", $line . "\n", $line), '', $htContent);
                $htContent = preg_replace('/\n{3,}/', "\n\n", $htContent);
                file_put_contents($htaccessPath, $htContent);
            }
            if (file_exists($loaderPath)) {
                @unlink($loaderPath);
            }
        }

        parent::uninstall();
    }

    function dbInstall($data)
    {
        parent::dbInstall($data);
    }
}
