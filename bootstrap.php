<?php
declare(strict_types=1);
require __DIR__.'/vendor/autoload.php';
date_default_timezone_set('UTC');
chdir(__DIR__);
if (is_file(__DIR__.'/.env')) (new Symfony\Component\Dotenv\Dotenv())->load(__DIR__.'/.env');
$defaults=['DATABASE_DSN'=>'sqlite:var/billing.sqlite','DATABASE_USER'=>'','DATABASE_PASSWORD'=>''];
$config=[];
foreach ($defaults as $key=>$default) $config[$key]=$_SERVER[$key]??$_ENV[$key]??(getenv($key)!==false?getenv($key):$default);
return new App\Container($config);
