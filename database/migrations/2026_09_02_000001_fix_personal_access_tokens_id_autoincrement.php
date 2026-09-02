<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Restore AUTO_INCREMENT on personal_access_tokens.id.
 *
 * The original migration declares $table->id(), but the production table lost
 * the AUTO_INCREMENT attribute (a SQL dump / phpMyAdmin import will strip it).
 * Sanctum's createToken() inserts without an id and relies on MySQL to generate
 * one, so every login failed with:
 *
 *   SQLSTATE[HY000]: 1364 Field 'id' doesn't have a default value
 *
 * This migration is idempotent — it inspects the live column first and does
 * nothing when the schema is already correct.
 */
return new class extends Migration
{
    private const TABLE = 'personal_access_tokens';

    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql' || !Schema::hasTable(self::TABLE)) {
            return;
        }

        $column = DB::selectOne(
            'SELECT COLUMN_KEY, EXTRA
               FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = ?
                AND TABLE_NAME   = ?
                AND COLUMN_NAME  = ?',
            [DB::getDatabaseName(), self::TABLE, 'id']
        );

        // Column missing entirely, or already correct — nothing to repair.
        if (!$column || str_contains(strtolower($column->EXTRA ?? ''), 'auto_increment')) {
            return;
        }

        // A stray id = 0 row (possible while the column had no default) would
        // collide with the first generated key. The id is unique, so at most
        // one such row can exist.
        DB::table(self::TABLE)->where('id', 0)->delete();

        // MySQL only allows AUTO_INCREMENT on a column that is a key.
        if (strtoupper($column->COLUMN_KEY ?? '') !== 'PRI') {
            $hasPrimaryKey = DB::selectOne(
                'SELECT 1 AS found
                   FROM information_schema.TABLE_CONSTRAINTS
                  WHERE TABLE_SCHEMA    = ?
                    AND TABLE_NAME      = ?
                    AND CONSTRAINT_TYPE = ?',
                [DB::getDatabaseName(), self::TABLE, 'PRIMARY KEY']
            );

            // Only claim the primary key if the table has none; otherwise a
            // plain index is enough to satisfy the AUTO_INCREMENT requirement.
            DB::statement($hasPrimaryKey
                ? 'ALTER TABLE `' . self::TABLE . '` ADD INDEX `pat_id_index` (`id`)'
                : 'ALTER TABLE `' . self::TABLE . '` ADD PRIMARY KEY (`id`)');
        }

        DB::statement(
            'ALTER TABLE `' . self::TABLE . '` MODIFY `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT'
        );
    }

    public function down(): void
    {
        // Deliberately irreversible: dropping AUTO_INCREMENT would reintroduce
        // the login failure this migration exists to fix.
    }
};
