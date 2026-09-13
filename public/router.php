<?php
// Local PHP server only; never expose project files outside public/.
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if (in_array($path,['/app.css','/auth.js'],true)) return false;
require __DIR__.'/index.php';
