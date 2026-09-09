<?php
declare(strict_types=1);
namespace App\Identity;
use App\Infrastructure\Database;
use App\Billing\BillingError;
final class Auth
{
    public function __construct(private Database $db) {}
    public function register(string $email,string $password): string
    {
        $email=mb_strtolower(trim($email));
        if (!filter_var($email,FILTER_VALIDATE_EMAIL) || strlen($email)>254 || strlen($password)<12 || strlen($password)>128) throw new BillingError('Введите корректную почту и пароль от 12 до 128 символов.');
        $id=Database::id();
        try { $this->db->execute('INSERT INTO users(id,email,password_hash,created_at) VALUES(?,?,?,?)',[$id,$email,password_hash($password,PASSWORD_ARGON2ID),time()]); }
        catch (\PDOException $e) { if (in_array($e->getCode(),['23000','23505'])) throw new BillingError('Не удалось создать аккаунт с этой почтой.'); throw $e; }
        return $id;
    }
    public function login(string $email,string $password): string
    {
        $user=$this->db->one('SELECT * FROM users WHERE email=?',[mb_strtolower(trim($email))]);
        $hash=$user['password_hash']??'$argon2id$v=19$m=65536,t=4,p=1$ZXhhbXBsZXNhbHRleGFtcA$el3WCJBajqpnjEmAU/Ut6WxFgDKR6wKvLGKHvmbU8qM';
        if (!password_verify($password,$hash) || !$user || (int)($user['disabled']??0)===1) throw new BillingError('Неверная почта или пароль.');
        return $user['id'];
    }
    public function issue(string $userId): string
    {
        $raw=bin2hex(random_bytes(32));
        $this->db->execute('INSERT INTO sessions(id,user_id,csrf,expires_at) VALUES(?,?,?,?)',[hash('sha256',$raw),$userId,bin2hex(random_bytes(32)),time()+86400]);
        return $raw;
    }
    public function session(string $raw): ?array
    {
        return $this->db->one('SELECT u.id,u.email,u.telegram_id,u.role,s.csrf,s.admin_verified_until,CASE WHEN u.totp_secret IS NULL THEN 0 ELSE 1 END AS mfa_enabled FROM sessions s JOIN users u ON u.id=s.user_id WHERE s.id=? AND s.expires_at>? AND u.disabled=0',[hash('sha256',$raw),time()]);
    }
    public function logout(string $raw): void { $this->db->execute('DELETE FROM sessions WHERE id=?',[hash('sha256',$raw)]); }
    public function throttle(string $key,int $limit,int $seconds=900): void
    {
        $hits=$this->db->transaction(function () use ($key,$seconds) {
            $bucket=hash('sha256',$key);
            $this->db->execute('INSERT INTO rate_limits VALUES(?,0,?) ON CONFLICT(bucket) DO NOTHING',[$bucket,time()+$seconds]);
            $row=$this->db->one('SELECT * FROM rate_limits WHERE bucket=?'.$this->db->lock(),[$bucket]);
            $hits=(int)$row['expires_at']<=time()?1:(int)$row['hits']+1;
            $this->db->execute('UPDATE rate_limits SET hits=?,expires_at=? WHERE bucket=?',[$hits,(int)$row['expires_at']<=time()?time()+$seconds:$row['expires_at'],$bucket]);
            return $hits;
        });
        if ($hits>$limit) throw new BillingError('Слишком много запросов. Попробуйте позже.');
    }
}
