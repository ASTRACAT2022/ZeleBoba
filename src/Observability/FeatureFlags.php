<?php
declare(strict_types=1);
namespace App\Observability;
use App\Infrastructure\Database;
final class FeatureFlags {
 public function __construct(private Database $db){}
 public function all():array{return $this->db->all('SELECT * FROM feature_flags ORDER BY name');}
 public function enabled(string $name):bool{$r=$this->db->one('SELECT enabled FROM feature_flags WHERE name=?',[$name]);return $r===null||$r['enabled']==1;}
 public function set(string $name,bool $enabled,int $rollout,string $actor):void{if(!preg_match('/^[a-z][a-z0-9_.-]{2,79}$/',$name)||$rollout<0||$rollout>100)throw new \InvalidArgumentException('Invalid feature flag');$this->db->execute('INSERT INTO feature_flags(name,enabled,rollout_percent,updated_by,updated_at) VALUES(?,?,?,?,?) ON CONFLICT(name) DO UPDATE SET enabled=excluded.enabled,rollout_percent=excluded.rollout_percent,updated_by=excluded.updated_by,updated_at=excluded.updated_at',[$name,$enabled?1:0,$rollout,$actor,time()]);}
}
