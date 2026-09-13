<?php
declare(strict_types=1);
// Migrate Telegram bot from Django billing to ZeleBoba.
// Seals the new bot token via Vault and writes settings directly to app_settings
// (bypassing Settings::save which blocks bot replacement).
require __DIR__.'/../vendor/autoload.php';
date_default_timezone_set('UTC');
chdir(__DIR__);
if (is_file(__DIR__.'/.env')) (new Symfony\Component\Dotenv\Dotenv())->load(__DIR__.'/.env');
$defaults=['DATABASE_DSN'=>'sqlite:var/billing.sqlite','DATABASE_USER'=>'','DATABASE_PASSWORD'=>''];
$config=[];
foreach ($defaults as $key=>$default) $config[$key]=$_SERVER[$key]??$_ENV[$key]??(getenv($key)!==false?getenv($key):$default);
$c = new App\Container($config);

$newToken = '8388982852:REPLACE_WITH_REAL_BOT_TOKEN';
$newUsername = 'astracatvpnX_bot';
$newAppUrl = 'https://cabinet.astracat.network';
$newWebhookSecret = 'REPLACE_WITH_REAL_WEBHOOK_SECRET';

$db = $c->db;
$vault = $c->settings->vault;

// Seal the token
$sealedToken = $vault->seal('TELEGRAM_BOT_TOKEN', $newToken);
$sealedSecret = $vault->seal('TELEGRAM_WEBHOOK_SECRET', $newWebhookSecret);

$now = time();
$db->transaction(function () use ($db, $sealedToken, $sealedSecret, $newUsername, $newAppUrl, $now) {
    $upsert = 'INSERT INTO app_settings VALUES(?,?,?) ON CONFLICT(name) DO UPDATE SET value=excluded.value,updated_at=excluded.updated_at';
    $db->execute($upsert, ['TELEGRAM_BOT_TOKEN', $sealedToken, $now]);
    $db->execute($upsert, ['TELEGRAM_WEBHOOK_SECRET', $sealedSecret, $now]);
    $db->execute($upsert, ['TELEGRAM_BOT_USERNAME', $newUsername, $now]);
    $db->execute($upsert, ['APP_URL', $newAppUrl, $now]);
    $db->execute('UPDATE settings_revision SET revision=revision+1 WHERE id=1');
});

echo "Bot migrated:\n";
echo "  TELEGRAM_BOT_USERNAME = $newUsername\n";
echo "  APP_URL = $newAppUrl\n";
echo "  TELEGRAM_WEBHOOK_SECRET set (sealed)\n";
echo "  TELEGRAM_BOT_TOKEN sealed\n";
echo "Done.\n";
