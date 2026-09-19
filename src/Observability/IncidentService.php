<?php
declare(strict_types=1);
namespace App\Observability;
use App\Infrastructure\Database;
final class IncidentService {
 public function __construct(private Database $db){}
 public function create(string $title,string $actor):array{$id=Database::id();$code='INC-'.gmdate('Y').'-'.str_pad((string)((int)($this->db->one('SELECT count(*) n FROM incidents')['n']??0)+1),3,'0',STR_PAD_LEFT);$this->db->execute("INSERT INTO incidents(id,code,title,status,created_by,created_at) VALUES(?,?,?,'open',?,?)",[$id,$code,mb_substr(trim($title),0,200),$actor,time()]);return $this->db->one('SELECT * FROM incidents WHERE id=?',[$id]);}
 public function list():array{return $this->db->all('SELECT i.*,COUNT(io.operation_id) affected FROM incidents i LEFT JOIN incident_operations io ON io.incident_id=i.id GROUP BY i.id ORDER BY i.created_at DESC');}
}
