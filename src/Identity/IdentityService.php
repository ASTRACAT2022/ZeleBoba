<?php
declare(strict_types=1);
namespace App\Identity;
use App\Billing\BillingError;
use App\Infrastructure\Database;

/** Maps external login identities to an internal user; never uses a username. */
final class IdentityService
{
    public function __construct(private Database $db) {}
    public function userId(string $type,string $externalId): ?string
    {
        return $this->db->one('SELECT user_id FROM user_identities WHERE type=? AND external_id=?',[$type,$this->normalise($type,$externalId)])['user_id'] ?? null;
    }
    public function attach(string $userId,string $type,string $externalId,bool $verified=true): void
    {
        $externalId=$this->normalise($type,$externalId);
        $owner=$this->userId($type,$externalId);
        if ($owner!==null && $owner!==$userId) throw new BillingError('Эта identity уже связана с другим аккаунтом.');
        $this->db->execute('INSERT INTO user_identities(id,user_id,type,external_id,verified_at,created_at) VALUES(?,?,?,?,?,?) ON CONFLICT(type,external_id) DO NOTHING',[
            Database::id(),$userId,$type,$externalId,$verified?time():null,time()
        ]);
    }
    private function normalise(string $type,string $value): string
    {
        if ($type==='telegram' && preg_match('/^[1-9][0-9]{0,19}$/D',$value)) return $value;
        if ($type==='email' && filter_var($value=mb_strtolower(trim($value)),FILTER_VALIDATE_EMAIL)) return $value;
        throw new BillingError('Некорректная identity.');
    }
}
