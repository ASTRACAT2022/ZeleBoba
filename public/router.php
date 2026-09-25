<?php
// Local PHP server only; serve the same public assets as nginx.
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
$assets=['/app.css','/stripe.css','/auth.js','/theme.js','/control.js'];
if (in_array($path,$assets,true) || str_starts_with($path,'/branding/')) {
    $asset=realpath(__DIR__.$path);
    if ($asset && str_starts_with($asset,__DIR__.DIRECTORY_SEPARATOR) && is_file($asset) && pathinfo($asset,PATHINFO_EXTENSION)!=='php') return false;
}
require __DIR__.'/index.php';
