<?php
declare(strict_types=1);
namespace App\Identity;
use App\Infrastructure\Database;
use App\Settings\Vault;
use App\Billing\BillingError;
final class Mfa
{
    public function __construct(private Database $db,private Vault $vault) {}
    public function begin(string $uid):string
    {
        if($this->db->one('SELECT totp_secret FROM users WHERE id=?',[$uid])['totp_secret'])throw new BillingError('Двухфакторная защита уже включена.');
        $secret='';$alphabet='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';for($i=0;$i<32;$i++)$secret.=$alphabet[random_int(0,31)];
        $this->db->execute('INSERT INTO mfa_enrollments VALUES(?,?,?) ON CONFLICT(user_id) DO UPDATE SET secret=excluded.secret,expires_at=excluded.expires_at',[$uid,$this->vault->seal('mfa:'.$uid,$secret),time()+600]);return $secret;
    }
    public function enroll(string $uid,string $code):array
    {
        return $this->db->transaction(function()use($uid,$code){
            $u=$this->db->one('SELECT totp_secret FROM users WHERE id=?'.$this->db->lock(),[$uid]);
            if($u['totp_secret'])throw new BillingError('Защита уже включена.');
            $row=$this->db->one('SELECT * FROM mfa_enrollments WHERE user_id=? AND expires_at>?',[$uid,time()]);
            if(!$row)throw new BillingError('Настройка истекла. Начните снова.');
            $step=$this->validStep($this->vault->open('mfa:'.$uid,$row['secret']),$code,0);
            $this->db->execute('UPDATE users SET totp_secret=?,totp_last_step=? WHERE id=?',[$row['secret'],$step,$uid]);
            $this->db->execute('DELETE FROM mfa_enrollments WHERE user_id=?',[$uid]);$codes=[];
            for($n=0;$n<8;$n++){$codes[]=bin2hex(random_bytes(8));$this->db->execute('INSERT INTO mfa_recovery VALUES(?,?)',[$uid,hash('sha256',end($codes))]);}
            return $codes;
        });
    }
    public function verify(string $uid,string $code):void
    {
        $this->db->transaction(function()use($uid,$code){
            $user=$this->db->one('SELECT totp_secret,totp_last_step FROM users WHERE id=?'.$this->db->lock(),[$uid]);
            if(!$user || !$user['totp_secret'])throw new BillingError('Сначала включите двухфакторную защиту.');
            if(strlen($code)===16 && $this->db->execute('DELETE FROM mfa_recovery WHERE user_id=? AND code_hash=?',[$uid,hash('sha256',$code)]))return;
            $step=$this->validStep($this->vault->open('mfa:'.$uid,$user['totp_secret']),$code,(int)$user['totp_last_step']);
            $this->db->execute('UPDATE users SET totp_last_step=? WHERE id=?',[$step,$uid]);
        });
    }
    public function stepUp(string $session):void{$this->db->execute('UPDATE sessions SET admin_verified_until=? WHERE id=?',[time()+900,hash('sha256',$session)]);}
    private function validStep(string $secret,string $code,int $last):int
    {
        $now=intdiv(time(),30);
        foreach([$now,$now-1,$now+1] as $step)if($step>$last && hash_equals(self::code($secret,$step),$code))return $step;
        throw new BillingError('Неверный или уже использованный код. Дождитесь нового кода.');
    }
    public static function code(string $secret,int $step):string
    {
        $bits='';$alphabet='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        foreach(str_split($secret) as $char){$n=strpos($alphabet,$char);if($n===false)throw new \InvalidArgumentException('Base32');$bits.=str_pad(decbin($n),5,'0',STR_PAD_LEFT);}
        $key='';foreach(str_split($bits,8) as $byte)if(strlen($byte)===8)$key.=chr(bindec($byte));
        $hash=hash_hmac('sha1',pack('N2',intdiv($step,4294967296),$step%4294967296),$key,true);$offset=ord($hash[19])&15;
        $value=unpack('N',substr($hash,$offset,4))[1]&0x7fffffff;
        return str_pad((string)($value%1000000),6,'0',STR_PAD_LEFT);
    }
}
