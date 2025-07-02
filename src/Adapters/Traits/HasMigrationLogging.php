<?php

namespace Crumbls\Importer\Adapters\Traits;

use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Database\Schema\Blueprint;

trait HasMigrationLogging
{
    protected function createMigrationLogTable(): void
    {
        $db = $this->getDatabase();
        $schema = $db->schema();
        
        if (!$schema->hasTable('migration_log')) {
            $schema->create('migration_log', function (Blueprint $table) {
                $table->id();
                $table->string('migration_id')->unique();
                $table->json('operations');
                $table->json('metadata');
                $table->enum('status', ['started', 'completed', 'failed', 'rolled_back'])->default('started');
                $table->timestamps();
                $table->timestamp('completed_at')->nullable();
                $table->timestamp('rolled_back_at')->nullable();
                
                $table->index('migration_id');
                $table->index('status');
            });
        }
    }
    
    protected function logMigration(string $migrationId, array $operations, array $metadata): void
    {
        try {
            $db = $this->getDatabase();
            $this->createMigrationLogTable();
            
            $db->table('migration_log')->updateOrInsert(
                ['migration_id' => $migrationId],
                [
                    'operations' => json_encode($operations),
                    'metadata' => json_encode($metadata),
                    'status' => 'completed',
                    'completed_at' => now(),
                    'updated_at' => now(),
                ]
            );
            
        } catch (\Exception $e) {
            // Log but don't fail the migration
            error_log("Failed to log migration: " . $e->getMessage());
        }
    }
    
    protected function getMigrationLog(string $migrationId): ?array
    {
        try {
            $db = $this->getDatabase();
            $this->createMigrationLogTable();
            
            $log = $db->table('migration_log')
                ->where('migration_id', $migrationId)
                ->where('status', 'completed')
                ->first();
                
            if (!$log) {
                return null;
            }
            
            return [
                'id' => $log->id,
                'migration_id' => $log->migration_id,
                'operations' => json_decode($log->operations, true),
                'metadata' => json_decode($log->metadata, true)
            ];
            
        } catch (\Exception $e) {
            return null;
        }
    }
    
    protected function markMigrationRolledBack(string $migrationId): void
    {
        $db = $this->getDatabase();
        
        $db->table('migration_log')
            ->where('migration_id', $migrationId)
            ->update([
                'status' => 'rolled_back',
                'rolled_back_at' => now(),
                'updated_at' => now(),
            ]);
    }
    
    protected function markMigrationFailed(string $migrationId, string $error): void
    {
        $db = $this->getDatabase();
        
        $db->table('migration_log')
            ->updateOrInsert(
                ['migration_id' => $migrationId],
                [
                    'status' => 'failed',
                    'metadata' => json_encode(['error' => $error]),
                    'updated_at' => now(),
                ]
            );
    }
    
    protected function markMigrationStarted(string $migrationId, array $metadata = []): void
    {
        $db = $this->getDatabase();
        $this->createMigrationLogTable();
        
        $db->table('migration_log')->updateOrInsert(
            ['migration_id' => $migrationId],
            [
                'status' => 'started',
                'metadata' => json_encode($metadata),
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }
}