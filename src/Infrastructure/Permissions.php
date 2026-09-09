<?php
declare(strict_types=1);
namespace App\Infrastructure;
final class Permissions
{
    public static function grant(Database $db,string $role='billing'):void
    {
        if(!$db->postgres() || !preg_match('/^[a-z_][a-z0-9_]{0,62}$/D',$role))throw new \RuntimeException('PostgreSQL and a valid runtime role required');
        $db->transaction(function()use($db,$role){
            $db->execute("GRANT USAGE ON SCHEMA public TO $role");
            $db->execute("GRANT SELECT,INSERT,UPDATE,DELETE ON ALL TABLES IN SCHEMA public TO $role");
            $db->execute("REVOKE UPDATE,DELETE,TRUNCATE ON ledger_entries,payment_receipts,audit_log FROM $role");
            $db->execute("REVOKE INSERT,UPDATE,DELETE,TRUNCATE ON migrations FROM $role");
            $db->execute("REVOKE CREATE ON SCHEMA public FROM $role");
        });
    }
    public static function safe(Database $db):bool
    {
        if(!$db->postgres())return false;
        $row=$db->one("SELECT CASE WHEN r.rolsuper OR r.rolcreatedb OR r.rolcreaterole OR has_table_privilege(current_user,'ledger_entries','UPDATE') OR has_table_privilege(current_user,'audit_log','DELETE') OR has_schema_privilege(current_user,'public','CREATE') THEN 0 ELSE 1 END AS safe FROM pg_roles r WHERE r.rolname=current_user");
        return (int)$row['safe']===1;
    }
}
