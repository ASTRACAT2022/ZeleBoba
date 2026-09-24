<?php
declare(strict_types=1);
namespace App\Identity;
use App\Infrastructure\Database;
use App\Billing\BillingError;
final class TelegramLogin
{
    public function __construct(private Database $db,private Auth $auth) {}
    public function begin():array
    {
        $token=bin2hex(random_bytes(24));$browser=bin2hex(random_bytes(32));
        $this->db->execute("INSERT INTO login_challenges(token_hash,browser_hash,kind,state,expires_at,created_at) VALUES(?,?,'browser','pending',?,?)",[hash('sha256',$token),hash('sha256',$browser),time()+300,time()]);
        return ['token'=>$token,'browser'=>$browser];
    }
    public function pending(string $token):bool{return $this->db->one("SELECT token_hash FROM login_challenges WHERE token_hash=? AND kind='browser' AND state='pending' AND expires_at>?",[hash('sha256',$token),time()])!==null;}
    public function approve(string $token,string $telegramId):bool
    {
        return $this->db->transaction(function()use($token,$telegramId){
            $row=$this->db->one("SELECT * FROM login_challenges WHERE token_hash=? AND kind='browser' AND state='pending' AND expires_at>?".$this->db->lock(),[hash('sha256',$token),time()]);
            if (!$row) return false;
            $uid=$this->telegramUser($telegramId);
            $this->db->execute("UPDATE login_challenges SET state='approved',user_id=? WHERE token_hash=?",[$uid,$row['token_hash']]);return true;
        });
    }
    public function telegramUser(string $tg):string
    {
        if (!preg_match('/^[1-9][0-9]{0,19}$/D',$tg))throw new BillingError('Некорректный Telegram ID.');
        $identities=new IdentityService($this->db);
        $id=$identities->userId('telegram',$tg);
        if ($id===null) {
            // Rolling-migration compatibility: an older account may already
            // have the immutable Telegram id in the legacy projection while
            // user_identities has not been populated yet.
            $legacy=$this->db->one('SELECT id FROM users WHERE telegram_id=?',[$tg]);
            if($legacy){
                $id=(string)$legacy['id'];
                $identities->attach($id,'telegram',$tg,true);
            }
        }
        if ($id===null) {
            $id=Database::id();
            try {
                $this->db->execute('INSERT INTO users(id,telegram_id,created_at) VALUES(?,?,?)',[$id,$tg,time()]); // legacy read projection
                $identities->attach($id,'telegram',$tg,true);
            } catch (\PDOException $e) {
                $id=$identities->userId('telegram',$tg) ?? throw $e;
            }
        }
        $user=$this->db->one('SELECT id,disabled FROM users WHERE id=?',[$id]);
        if ((int)$user['disabled']===1)throw new BillingError('Аккаунт отключён.');
        return $user['id'];
    }
    public function magic(string $tg):string
    {
        return $this->db->transaction(function()use($tg){
            $uid=$this->telegramUser($tg);$token=bin2hex(random_bytes(32));
            $this->db->execute("UPDATE login_challenges SET state='consumed' WHERE user_id=? AND kind='magic' AND state='approved'",[$uid]);
            $this->db->execute("INSERT INTO login_challenges(token_hash,user_id,kind,state,expires_at,created_at) VALUES(?,?,'magic','approved',?,?)",[hash('sha256',$token),$uid,time()+300,time()]);return $token;
        });
    }
    public function ready(string $browser):bool{return $this->db->one("SELECT token_hash FROM login_challenges WHERE browser_hash=? AND kind='browser' AND state='approved' AND expires_at>?",[hash('sha256',$browser),time()])!==null;}
    public function consume(string $proof,string $kind):string
    {
        if(!in_array($kind,['browser','magic'],true)||strlen($proof)!==64)throw new BillingError('Ссылка входа недействительна.');
        return $this->db->transaction(function()use($proof,$kind){
            $column=$kind==='browser'?'browser_hash':'token_hash';
            $row=$this->db->one("SELECT * FROM login_challenges WHERE $column=? AND kind=? AND state='approved' AND expires_at>?".$this->db->lock(),[hash('sha256',$proof),$kind,time()]);
            if(!$row)throw new BillingError('Ссылка уже использована или истекла. Получите новую в боте.');
            $user=$this->db->one('SELECT disabled FROM users WHERE id=?',[$row['user_id']]);
            if(!$user || (int)$user['disabled']===1)throw new BillingError('Аккаунт отключён.');
            $session=$this->auth->issue($row['user_id']);
            $this->db->execute("UPDATE login_challenges SET state='consumed' WHERE token_hash=?",[$row['token_hash']]);
            $this->db->execute('INSERT INTO audit_log VALUES(?,?,?,?,?)',[Database::id(),$row['user_id'],'auth.telegram',$row['user_id'],time()]);
            return $session;
        });
    }
}
