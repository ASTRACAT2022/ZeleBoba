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
        $ok=password_verify($password,$hash);
        if (!$ok && $user && is_string($hash) && str_starts_with($hash,'pbkdf2_sha256$')) {
            $ok=self::verifyDjangoPbkdf2($password,$hash);
            if ($ok) {
                // Upgrade legacy Django hash to Argon2id on successful login.
                $this->db->execute('UPDATE users SET password_hash=? WHERE id=?',[password_hash($password,PASSWORD_ARGON2ID),$user['id']]);
            }
        }
        if (!$ok || !$user || (int)($user['disabled']??0)===1) throw new BillingError('Неверная почта или пароль.');
        return $user['id'];
    }
    /** Verify Django-style `pbkdf2_sha256$iterations$salt$base64` hashes (migration compat). */
    public static function verifyDjangoPbkdf2(string $password,string $hash): bool
    {
        $parts=explode('$',$hash);
        if (count($parts)!==4 || $parts[0]!=='pbkdf2_sha256') return false;
        $iterations=(int)$parts[1];
        if ($iterations<1 || $iterations>5000000 || $parts[2]==='' || $parts[3]==='') return false;
        $expected=base64_decode($parts[3],true);
        if ($expected===false) return false;
        $calc=hash_pbkdf2('sha256',$password,$parts[2],$iterations,strlen($expected),true);
        return hash_equals($expected,$calc);
    }
    public function issue(string $userId): string
    {
        $raw=bin2hex(random_bytes(32));
        $this->db->execute('INSERT INTO sessions(id,user_id,csrf,expires_at) VALUES(?,?,?,?)',[hash('sha256',$raw),$userId,bin2hex(random_bytes(32)),time()+86400]);
        return $raw;
    }
    public function session(string $raw): ?array
    {
        return $this->db->one('SELECT u.id,u.email,u.telegram_id,u.role,u.balance_kopeks,s.csrf,s.admin_verified_until,CASE WHEN u.totp_secret IS NULL THEN 0 ELSE 1 END AS mfa_enabled FROM sessions s JOIN users u ON u.id=s.user_id WHERE s.id=? AND s.expires_at>? AND u.disabled=0',[hash('sha256',$raw),time()]);
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
