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
