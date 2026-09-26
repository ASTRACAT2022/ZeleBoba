#!/usr/bin/env php
<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
$json = json_encode(\App\TelegramUI\Screens::buildPrototypeScreens(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
if (($argv[1] ?? '') === '--js') {
    echo 'window.TG_UI_SCREENS = '.$json.";\n";
} else {
    echo $json;
}
