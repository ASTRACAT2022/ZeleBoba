<?php
declare(strict_types=1);
namespace App\Observability;
use App\Infrastructure\Database;
final class ConsistencyChecker {
 public function __construct(private Database $db){}
 public function run():array {
  $missing=$this->db->all("SELECT p.id FROM payments p LEFT JOIN ledger_entries l ON l.order_id=p.order_id LEFT JOIN orders o ON o.id=p.order_id WHERE p.status='succeeded' GROUP BY p.id,o.status HAVING COUNT(l.id)<>2 OR SUM(l.amount_minor)<>0 OR o.status NOT IN ('paid','fulfilled')");
  $creatorDrift=[];
  // A creator commission and its positive ledger entry are one atomic fact.
  // Keep reconciliation non-destructive: it reports drift for an operator
  // instead of inventing a financial correction with incomplete evidence.
  if ($this->creatorTablesExist()) {
   $creatorDrift=$this->db->all("SELECT c.id FROM creator_commissions c LEFT JOIN creator_ledger l ON l.commission_id=c.id AND l.entry_type='commission' AND l.amount_minor=c.commission_minor GROUP BY c.id HAVING COUNT(l.id)<>1 UNION SELECT l.id FROM creator_ledger l LEFT JOIN creator_commissions c ON c.id=l.commission_id WHERE l.entry_type='commission' AND c.id IS NULL UNION SELECT c.id FROM creator_commissions c LEFT JOIN creator_ledger l ON l.creator_id=c.creator_id AND l.entry_type='reversal' AND l.metadata LIKE '%' || c.id || '%' WHERE c.status='reversed' GROUP BY c.id HAVING COUNT(l.id)<>1");
  }
  $details=['critical_payment_drift'=>array_column($missing,'id'),'critical_creator_drift'=>array_column($creatorDrift,'id')];$status=($missing||$creatorDrift)?'critical':'ok';
  $this->db->execute('INSERT INTO consistency_checks(id,kind,status,details,checked_at) VALUES(?,?,?,?,?)',[Database::id(),'money',$status,json_encode($details,JSON_THROW_ON_ERROR),time()]);
  return ['status'=>$status,'count'=>count($missing)+count($creatorDrift),'details'=>$details];
 }
 private function creatorTablesExist():bool {
  if($this->db->postgres())return (bool)($this->db->one("SELECT to_regclass('creator_commissions') name")['name']??null);
  return $this->db->one("SELECT name FROM sqlite_master WHERE type='table' AND name='creator_commissions'")!==null;
 }

 /**
  * Paid-but-not-delivered: succeeded payments whose order is not yet
  * paid/fulfilled. Non-destructive — returns the rows so a caller can
  * surface them as an operator alert (no money mutation here).
  *
  * @return array{order_id:string,user_id:string,payment_id?:string,amount_minor:int,provider?:string,status?:string}
  */
 public function deliveryGaps(): array {
  $rows=$this->db->all(
    "SELECT DISTINCT o.id AS order_id,o.user_id,o.provider,o.status,o.provider_payment_id,
            p.id AS payment,
            COALESCE(p.amount_minor,o.price_minor) AS amount_minor
     FROM payments p JOIN orders o ON o.id=p.order_id
     WHERE p.status='succeeded' AND o.status NOT IN ('paid','fulfilled') ORDER BY o.id"
  );
  return array_map(fn($r)=>[
    'order_id'=>$r['order_id'],'user_id'=>$r['user_id'],
    'provider'=>$r['provider'],'status'=>$r['status'],
    'provider_payment_id'=>$r['provider_payment_id']??null,
    'amount_minor'=>(int)$r['amount_minor'],
  ],$rows);
 }
}
