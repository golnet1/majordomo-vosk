<?php
chdir(dirname(__FILE__) . '/../');

include_once("./config.php");
include_once("./lib/loader.php");
include_once("./lib/threads.php");

$latest_check = 0;
$checkEvery = 5;

set_time_limit(0);

$db = new mysql(DB_HOST, '', DB_USER, DB_PASSWORD, DB_NAME);
include_once("./load_settings.php");
include_once(DIR_MODULES . "control_modules/control_modules.class.php");
$ctl = new control_modules();
include_once(DIR_MODULES . 'vosk/vosk.class.php');

$vosk_module = new vosk();
$vosk_module->getConfig();

echo date("H:i:s") . " running " . basename(__FILE__) . PHP_EOL;

while (1) {
    setGlobal((str_replace('.php', '', basename(__FILE__))) . 'Run', time(), 1);
    $control_status = getGlobal((str_replace('.php', '', basename(__FILE__))) . 'Exit');
    if ($control_status) {
        $vosk_module->stopAsrProcess();
        setGlobal((str_replace('.php', '', basename(__FILE__))) . 'Exit', false);
    }

    if (file_exists('./reboot') || isset($_GET['onetime'])) {
        $vosk_module->stopAsrProcess();
        $db->Disconnect();
        exit;
    }

    if ((time() - $latest_check) > $checkEvery) {
        $latest_check = time();
        $vosk_module->processCycle();
    }

    sleep(1);
}

$db->Disconnect();
