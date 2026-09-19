<?php
declare(strict_types=1);
namespace App\Settings;
use App\Container;
final class Readiness
{
    public static function report(Container $app):array
    {
        $c=$app->config;$db=$app->db;$checks=[];
        $add=function(string $label,bool $ok,string $detail)use(&$checks){$checks[]=['label'=>$label,'ok'=>$ok,'detail'=>$detail];};
        $add('Боевой режим',$c['APP_ENV']==='prod','Включите после настройки HTTPS и интеграций.');
        $add('PostgreSQL',$db->postgres(),'SQLite подходит только для локальной разработки.');
        $add('Ограниченные права базы',\App\Infrastructure\Permissions::safe($db),'Процесс приложения не должен быть владельцем таблиц или изменять финансовый журнал.');
        $add('HTTPS кабинета',str_starts_with($c['APP_URL'],'https://'),'URL должен совпадать с публичным адресом.');
        $add('Реальные адаптеры',in_array($c['PAYMENT_DRIVER'],['platega'],true)&&$c['PROVISION_DRIVER']==='remnawave','Демо не выдаёт реальный доступ.');
        $add('Параметры оплаты и панели',Settings::purchaseErrors($c)===[],'Магазин, ключи и группа панели должны быть заполнены.');
        $paymentCheck='platega';
        foreach(['telegram',$paymentCheck,'remnawave'] as $name){$row=$db->one('SELECT * FROM integration_checks WHERE integration=?',[$name]);$ok=$row && $row['status']==='ok' && hash_equals($row['config_hash'],IntegrationCheck::fingerprint($c,$name)) && (int)$row['checked_at']>time()-86400;$add('Проверка '.$name,(bool)$ok,'Проверка текущих настроек должна пройти в последние 24 часа.');}
        $admins=$db->all("SELECT totp_secret FROM users WHERE role='admin' AND disabled=0");
        $add('Защита администраторов',count($admins)>0&&!in_array(null,array_column($admins,'totp_secret'),true),'Для каждого администратора обязательна 2FA.');
        foreach(['worker','scheduler'] as $name){$row=$db->one('SELECT seen_at FROM runtime_heartbeats WHERE name=?',[$name]);$add('Процесс '.$name,$row && (int)$row['seen_at']>time()-180,'Ожидается сигнал не старше трёх минут.');}
        $add('Очередь без остановленных заданий',$db->one("SELECT id FROM outbox WHERE status='dead' LIMIT 1")===null,'Исправьте причину и повторите задание.');
        $add('Доступные тарифы',$db->one('SELECT id FROM plans WHERE active=1 LIMIT 1')!==null,'Создайте хотя бы один активный тариф.');
        $add('Продажи включены',$c['PURCHASES_ENABLED']==='1','Включайте после проверки тестовой покупки и восстановления.');
        return $checks;
    }
}
