<?php
declare(strict_types=1);
namespace App\Observability;
use App\Infrastructure\Database;
final class StateExplanation {
 public function __construct(private Database $db){}
 public function subscription(string $id):array {
  $s=$this->db->one('SELECT * FROM subscriptions WHERE id=?',[$id]); if(!$s)return [];
  $events=$this->db->all('SELECT type,status,message,metadata,occurred_at FROM operation_events WHERE subscription_id=? ORDER BY occurred_at DESC LIMIT 8',[$id]);
  $account=$this->db->one('SELECT state,last_synced_at,last_error FROM provisioning_accounts WHERE subscription_id=?',[$id]);
  $reasons=[]; foreach(array_reverse($events) as $e)$reasons[]=['at'=>$e['occurred_at'],'text'=>$e['message'],'status'=>$e['status']];
  if(!$reasons)$reasons[]=['at'=>$s['updated_at']??$s['created_at'],'text'=>'No recorded business event for this legacy subscription','status'=>'warning'];
  return ['status'=>$s['lifecycle_status']??$s['status'],'expires_at'=>$s['expires_at'],'reasons'=>$reasons,'provisioning'=>$account];
 }
}
