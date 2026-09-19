<?php
declare(strict_types=1);
namespace App\Observability;
use App\Infrastructure\Database;
final class ConsistencyChecker {
 public function __construct(private Database $db){}
 public function run():array {
  $missing=$this->db->all("SELECT p.id FROM payments p LEFT JOIN ledger_entries l ON l.order_id=p.order_id LEFT JOIN orders o ON o.id=p.order_id WHERE p.status='succeeded' GROUP BY p.id,o.status HAVING COUNT(l.id)<>2 OR SUM(l.amount_minor)<>0 OR o.status NOT IN ('paid','fulfilled')");
  $details=['critical_payment_drift'=>array_column($missing,'id')];$status=$missing?'critical':'ok';
  $this->db->execute('INSERT INTO consistency_checks(id,kind,status,details,checked_at) VALUES(?,?,?,?,?)',[Database::id(),'money',$status,json_encode($details,JSON_THROW_ON_ERROR),time()]);
  return ['status'=>$status,'count'=>count($missing),'details'=>$details];
 }
}
