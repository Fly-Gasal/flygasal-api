<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Restore the primary key and AUTO_INCREMENT on every `id` column that lost them.
 *
 * A SQL dump / phpMyAdmin import re-created this database's tables without the
 * AUTO_INCREMENT attribute (and in places without the primary key). Any insert
 * that omits `id` then fails with:
 *
 *   SQLSTATE[HY000]: 1364 Field 'id' doesn't have a default value
 *
 * which broke login (personal_access_tokens) and the migration log itself.
 * This sweeps the whole schema rather than patching one table at a time.
 *
 * The `migrations` table cannot be repaired here — recording this migration
 * needs that table's insert to already work — so fix it with raw SQL first:
 *
 *   ALTER TABLE `migrations`
 *     MODIFY `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
 *     ADD PRIMARY KEY (`id`);
 *
 * Idempotent: only tables that are actually broken are touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $database = DB::getDatabaseName();

        $targets = DB::select(
            "SELECT c.TABLE_NAME, c.COLUMN_TYPE, c.COLUMN_KEY
               FROM information_schema.COLUMNS c
               JOIN information_schema.TABLES t
                 ON t.TABLE_SCHEMA = c.TABLE_SCHEMA
                AND t.TABLE_NAME   = c.TABLE_NAME
              WHERE c.TABLE_SCHEMA = ?
                AND c.COLUMN_NAME  = 'id'
                AND c.EXTRA NOT LIKE '%auto_increment%'
                AND t.TABLE_TYPE   = 'BASE TABLE'
              ORDER BY c.TABLE_NAME",
            [$database]
        );

        $repaired = [];
        $skipped  = [];

        foreach ($targets as $target) {
            $table = $target->TABLE_NAME;

            // AUTO_INCREMENT only applies to integer columns.
            if (!preg_match('/^(tiny|small|medium|big)?int\b/i', $target->COLUMN_TYPE)) {
                $skipped[$table] = "`id` is {$target->COLUMN_TYPE}, not an integer type";
                continue;
            }

            if ($reason = $this->blockingDataIssue($table)) {
                $skipped[$table] = $reason;
                continue;
            }

            // Preserve the declared width and signedness — `migrations` uses
            // INT UNSIGNED while everything else uses BIGINT UNSIGNED.
            $clauses = ["MODIFY `id` {$target->COLUMN_TYPE} NOT NULL AUTO_INCREMENT"];

            if (strtoupper($target->COLUMN_KEY ?? '') !== 'PRI') {
                // UNIQUE, never a plain index: Eloquent looks rows up by id,
                // so a duplicate would silently resolve to the wrong record.
                $clauses[] = $this->tableHasPrimaryKey($database, $table)
                    ? "ADD UNIQUE INDEX `{$table}_id_unique` (`id`)"
                    : 'ADD PRIMARY KEY (`id`)';
            }

            // The key must be in place by the time AUTO_INCREMENT is applied,
            // so both clauses go in one statement (MySQL error 1075 otherwise).
            DB::statement("ALTER TABLE `{$table}` " . implode(', ', $clauses));
            $repaired[] = $table;
        }

        foreach ($repaired as $table) {
            Log::info("Restored AUTO_INCREMENT on {$table}.id");
        }

        foreach ($skipped as $table => $reason) {
            // Not fatal: the remaining tables are still worth repairing, and
            // these need a human to decide what the correct ids are.
            Log::warning("Could not restore AUTO_INCREMENT on {$table}.id — {$reason}");
        }
    }

    /**
     * Report why a table's existing ids cannot support a key, if they cannot.
     *
     * Renumbering is deliberately not attempted here: other tables may hold
     * foreign keys pointing at these ids, and rewriting them would silently
     * orphan those rows.
     */
    private function blockingDataIssue(string $table): ?string
    {
        $stats = DB::selectOne(
            "SELECT COUNT(*) AS total,
                    COUNT(DISTINCT `id`) AS distinct_ids,
                    SUM(`id` IS NULL OR `id` = 0) AS unusable
               FROM `{$table}`"
        );

        if (!$stats || (int) $stats->total === 0) {
            return null;
        }

        if ((int) $stats->unusable > 0) {
            return "{$stats->unusable} row(s) have a NULL or zero id";
        }

        if ((int) $stats->distinct_ids !== (int) $stats->total) {
            return 'duplicate ids present';
        }

        return null;
    }

    private function tableHasPrimaryKey(string $database, string $table): bool
    {
        return DB::selectOne(
            'SELECT 1 AS found
               FROM information_schema.TABLE_CONSTRAINTS
              WHERE TABLE_SCHEMA    = ?
                AND TABLE_NAME      = ?
                AND CONSTRAINT_TYPE = ?',
            [$database, $table, 'PRIMARY KEY']
        ) !== null;
    }

    public function down(): void
    {
        // Deliberately irreversible: dropping these keys would reintroduce the
        // insert failures this migration exists to fix.
    }
};
