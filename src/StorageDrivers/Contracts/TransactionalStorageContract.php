<?php

namespace Crumbls\Importer\StorageDrivers\Contracts;

interface TransactionalStorageContract
{
    // Transaction Support (optional for drivers that support it)
    public function transaction(callable $callback): mixed;
    public function beginTransaction(): static;
    public function commit(): static;
    public function rollback(): static;
}