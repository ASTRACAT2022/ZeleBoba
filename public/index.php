<?php
declare(strict_types=1);
if (($_SERVER['REQUEST_METHOD']??'')==='GET' && parse_url($_SERVER['REQUEST_URI']??'',PHP_URL_PATH)==='/health/live') {header('Content-Type: application/json');echo '{"status":"ok"}';exit;}
try {
    $container=require dirname(__DIR__).'/bootstrap.php';
    $response=(new App\Web\Application($container))->handle(Symfony\Component\HttpFoundation\Request::createFromGlobals());
    $response->headers->set('Content-Security-Policy',"default-src 'self'; style-src 'self'; script-src 'self'; img-src 'self' data:; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");
    $response->headers->set('X-Content-Type-Options','nosniff');
    $response->headers->set('Referrer-Policy','no-referrer');
    $response->headers->set('Cache-Control','no-store');
    if ($container->config['APP_ENV']==='prod') $response->headers->set('Strict-Transport-Security','max-age=31536000');
    $response->send();
} catch (Throwable $e) { error_log(get_class($e)); http_response_code(503); echo 'Service unavailable'; }
