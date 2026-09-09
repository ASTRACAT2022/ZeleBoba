<?php
declare(strict_types=1);
namespace App\Web;
use App\Settings\{IntegrationCheck,Readiness};
use App\Infrastructure\Database;
use App\Billing\BillingError;
use Symfony\Component\HttpFoundation\{Response,RedirectResponse};
trait AdminActions
{
    private function administration(string $handler,string $id):Response
    {
        $input=$this->request->request;$db=$this->app->db;$uid=$this->user['id'];
        if($handler==='admin-config-save'){
            $this->app->settings->save($input->all(),$uid,$input->getInt('revision',-1));return new RedirectResponse('/admin/config?saved=1',303);
        }
        if($handler==='admin-check'){
            (new IntegrationCheck($this->app))->check($id,$input->get('register')==='1');$this->app->billing->audit($uid,'integration.checked',$id);return new RedirectResponse('/admin/config?checked=1',303);
        }
        if($handler==='admin-config')return $this->render('admin-config',array_merge($this->app->settings->form(),['checks'=>$db->all('SELECT integration,status,checked_at FROM integration_checks'),'saved'=>$this->request->query->has('saved'),'checked'=>$this->request->query->has('checked')]));
        if($handler==='admin-readiness')return $this->render('readiness',['checks'=>Readiness::report($this->app)]);
        if($handler==='admin-plans')return $this->render('admin-plans',['plans'=>$db->all('SELECT * FROM plans ORDER BY active DESC,price_minor')]);
        if($handler==='admin-plan-save'){
            $name=trim($input->get('name',''));$price=filter_var($input->get('price_minor'),FILTER_VALIDATE_INT);$days=$input->getInt('duration_days');$devices=$input->getInt('devices');$traffic=filter_var($input->get('traffic_gb'),FILTER_VALIDATE_INT);$squad=trim($input->get('squad_uuid',''));
            if(!$name||mb_strlen($name)>100||$price<100||$price>100000000||$days<1||$days>3650||$devices<1||$devices>20||$traffic===false||$traffic<0||$traffic>100000||($squad!==''&&!preg_match('/^[0-9a-f-]{36}$/iD',$squad)))throw new BillingError('Проверьте параметры тарифа. Цена указывается в копейках.');
            $db->transaction(function()use($db,$input,$name,$price,$days,$devices,$traffic,$squad,$id,$uid){
                if(!$db->one('SELECT id FROM plans WHERE id=?'.$db->lock(),[$id]))throw new BillingError('Тариф не найден.');
                $db->execute('UPDATE plans SET name=?,price_minor=?,duration_days=?,devices=?,traffic_bytes=?,squad_uuid=?,active=? WHERE id=?',[$name,$price,$days,$devices,$traffic*1073741824,$squad,$input->get('active')==='1'?1:0,$id]);$this->app->billing->audit($uid,'plan.updated',$id);
            });return new RedirectResponse('/admin/plans',303);
        }
        if($handler==='admin-users')return $this->render('admin-users',['users'=>$db->all('SELECT id,email,telegram_id,role,disabled,created_at FROM users ORDER BY created_at DESC LIMIT 100')]);
        if($handler==='admin-user-toggle'){
            $db->transaction(function()use($db,$id,$uid){
                $target=$db->one('SELECT * FROM users WHERE id=?'.$db->lock(),[$id]);
                if(!$target||$target['role']==='admin')throw new BillingError('Администраторов нельзя отключить этой операцией.');
                $db->execute('UPDATE users SET disabled=? WHERE id=?',[(int)$target['disabled']===1?0:1,$id]);$db->execute('DELETE FROM sessions WHERE user_id=?',[$id]);$this->app->billing->audit($uid,'user.access_changed',$id);
            });return new RedirectResponse('/admin/users',303);
        }
        throw new \LogicException('Unknown admin action');
    }
}
