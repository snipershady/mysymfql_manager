<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Operations recorded in the audit log (AuditLogEntry::$action): changes made on a managed MySQL server and
 * management of the panel itself. Put on a controller action with #[\App\Attribute\Audited].
 */
enum AuditActionEnum: string
{
    // Managed MySQL servers
    case DATABASE_CREATE = 'database.create';
    case DATABASE_DROP = 'database.drop';
    case TABLE_EMPTY = 'table.empty';
    case TABLE_DROP = 'table.drop';
    case TABLE_OPTIMIZE = 'table.optimize';
    case QUERY_EXECUTE = 'query.execute';
    case MYSQL_USER_CREATE = 'mysql_user.create';
    case MYSQL_USER_DROP = 'mysql_user.drop';
    case MYSQL_USER_PASSWORD = 'mysql_user.password';
    case MYSQL_USER_GRANTS = 'mysql_user.grants';
    case PROCESS_KILL = 'process.kill';
    case BACKUP_RESTORE = 'backup.restore';
    case BACKUP_DELETE = 'backup.delete';

    // Panel
    case APP_USER_CREATE = 'app_user.create';
    case APP_USER_UPDATE = 'app_user.update';
    case APP_USER_DELETE = 'app_user.delete';
    case SQL_CLIENT_CREATE = 'sql_client.create';
    case SQL_CLIENT_UPDATE = 'sql_client.update';
    case SQL_CLIENT_DELETE = 'sql_client.delete';
    case DATABASE_OWNER_CREATE = 'database_owner.create';
    case DATABASE_OWNER_UPDATE = 'database_owner.update';
    case DATABASE_OWNER_DELETE = 'database_owner.delete';

    public function label(): string
    {
        return match ($this) {
            self::DATABASE_CREATE => 'Creazione database',
            self::DATABASE_DROP => 'Eliminazione database',
            self::TABLE_EMPTY => 'Svuotamento tabella',
            self::TABLE_DROP => 'Eliminazione tabella',
            self::TABLE_OPTIMIZE => 'Optimize tabella',
            self::QUERY_EXECUTE => 'Query console',
            self::MYSQL_USER_CREATE => 'Creazione utente MySQL',
            self::MYSQL_USER_DROP => 'Eliminazione utente MySQL',
            self::MYSQL_USER_PASSWORD => 'Cambio password utente MySQL',
            self::MYSQL_USER_GRANTS => 'Modifica grant utente MySQL',
            self::PROCESS_KILL => 'Kill processo',
            self::BACKUP_RESTORE => 'Ripristino backup',
            self::BACKUP_DELETE => 'Eliminazione backup',
            self::APP_USER_CREATE => 'Creazione utente applicazione',
            self::APP_USER_UPDATE => 'Modifica utente applicazione',
            self::APP_USER_DELETE => 'Eliminazione utente applicazione',
            self::SQL_CLIENT_CREATE => 'Creazione server',
            self::SQL_CLIENT_UPDATE => 'Modifica server',
            self::SQL_CLIENT_DELETE => 'Eliminazione server',
            self::DATABASE_OWNER_CREATE => 'Creazione assegnazione database',
            self::DATABASE_OWNER_UPDATE => 'Modifica assegnazione database',
            self::DATABASE_OWNER_DELETE => 'Eliminazione assegnazione database',
        };
    }
}
