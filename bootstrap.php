<?php
declare(strict_types=1);
require __DIR__.'/vendor/autoload.php';
date_default_timezone_set('UTC');
chdir(__DIR__);
$otelWasInEnvironment=getenv('OTEL_PHP_AUTOLOAD_ENABLED')!==false;
if (is_file(__DIR__.'/.env')) (new Symfony\Component\Dotenv\Dotenv())->load(__DIR__.'/.env');
if (!$otelWasInEnvironment && (($_SERVER['OTEL_PHP_AUTOLOAD_ENABLED']??$_ENV['OTEL_PHP_AUTOLOAD_ENABLED']??'')==='true')) {
    \OpenTelemetry\SDK\SdkAutoloader::autoload();
}
$defaults=['DATABASE_DSN'=>'sqlite:var/billing.sqlite','DATABASE_USER'=>'','DATABASE_PASSWORD'=>''];
$config=[];
foreach ($defaults as $key=>$default) $config[$key]=$_SERVER[$key]??$_ENV[$key]??(getenv($key)!==false?getenv($key):$default);
return new App\Container($config);
