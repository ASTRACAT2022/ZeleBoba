<?php
declare(strict_types=1);
if (($_SERVER['REQUEST_METHOD']??'')==='GET' && parse_url($_SERVER['REQUEST_URI']??'',PHP_URL_PATH)==='/health/live') {header('Content-Type: application/json');echo '{"status":"ok"}';exit;}
try {
    $container=require dirname(__DIR__).'/bootstrap.php';
    $response=(new App\Web\Application($container))->handle(Symfony\Component\HttpFoundation\Request::createFromGlobals());
    $response->send();
} catch (Throwable $e) { error_log(get_class($e)); http_response_code(503); echo 'Service unavailable'; }
