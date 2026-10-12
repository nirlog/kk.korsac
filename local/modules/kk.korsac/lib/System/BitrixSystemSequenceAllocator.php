<?php

declare(strict_types=1);

namespace KK\Korsac\System;
use Bitrix\Main\Config\Configuration;
use Bitrix\Main\DB\MysqliConnection;
use Throwable;
use Closure;
use KK\Korsac\Install\SystemSchemaDefinition;
/** Own nonpersistent connection: a caller's transaction cannot reclaim an allocated ID. */
final class BitrixSystemSequenceAllocator implements SystemSequenceAllocatorInterface
{
    /** Factory is a narrow test seam; normal runtime always opens its own D7 connection. */
    public function __construct(private readonly ?Closure $connectionFactory = null) {}

    public function allocate(string $period): int
    {
        if (!preg_match('/\A[0-9]{2}(?:0[1-9]|1[0-2])\z/D', $period)) throw new SystemException('system_sequence_invalid');
        $connection = null;
        $started = false;
        try {
            $connection = $this->connectionFactory !== null ? ($this->connectionFactory)() : $this->createConnection();
            $connection->startTransaction();
            $started = true;
            $table = SystemSchemaDefinition::SEQUENCE;
            $connection->queryExecute("INSERT INTO `{$table}` (`PERIOD`,`LAST_VALUE`,`UPDATED_AT`) VALUES ('{$period}',0,NOW()) ON DUPLICATE KEY UPDATE `PERIOD`=`PERIOD`");
            $row = $connection->query("SELECT `LAST_VALUE` FROM `{$table}` WHERE `PERIOD`='{$period}' FOR UPDATE")->fetch();
            if (!$row || (int)$row['LAST_VALUE'] < 0) throw new SystemException('system_sequence_failed');
            if ((int)$row['LAST_VALUE'] >= 9999) throw new SystemException('system_sequence_exhausted');
            $next = (int)$row['LAST_VALUE'] + 1;
            $connection->queryExecute("UPDATE `{$table}` SET `LAST_VALUE`={$next},`UPDATED_AT`=NOW() WHERE `PERIOD`='{$period}'");
            $connection->commitTransaction();
            $started = false;
            return $next;
        } catch (Throwable $error) {
            if ($started) { try { $connection->rollbackTransaction(); } catch (Throwable) {} }
            if ($error instanceof SystemException) throw $error;
            throw new SystemException('system_sequence_failed');
        } finally {
            if ($connection !== null) { try { $connection->disconnect(); } catch (Throwable) {} }
        }
    }
    private function createConnection(): MysqliConnection
    {
        $configuration = Configuration::getValue('connections')['default'] ?? null;
        if (!is_array($configuration) || ltrim((string)($configuration['className'] ?? ''), '\\') !== MysqliConnection::class) {
            throw new SystemException('system_sequence_failed');
        }
        // options=0 disables persistent connections for this dedicated allocator.
        $configuration['options'] = 0;
        return new MysqliConnection($configuration);
    }

}
