<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Restore the primary key and AUTO_INCREMENT on personal_access_tokens.id.
 *
 * The original migration declares $table->id(), but the production table lost
 * both the primary key and the AUTO_INCREMENT attribute (a SQL dump / phpMyAdmin
 * import will strip them). Sanctum's createToken() inserts without an id and
 * relies on MySQL to generate one, so every login failed with:
 *
 *   SQLSTATE[HY000]: 1364 Field 'id' doesn't have a default value
 *
 * MySQL refuses AUTO_INCREMENT on a column that is not a key (error 1075), so
 * the key and the attribute must be applied in the same statement. Existing
 * rows are renumbered first when their ids cannot support a primary key —
 * nothing references personal_access_tokens.id, so renumbering is safe.
 *
 * Idempotent: inspects the live column first and does nothing when correct.
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

        $this->normaliseExistingIds();

        $table = '`' . self::TABLE . '`';

        // AUTO_INCREMENT requires the column to be a key, and the key has to be
        // in place by the time the attribute is applied — hence one statement.
        if (strtoupper($column->COLUMN_KEY ?? '') === 'PRI') {
            DB::statement("ALTER TABLE {$table} MODIFY `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT");
            return;
        }

        $hasPrimaryKey = DB::selectOne(
            'SELECT 1 AS found
               FROM information_schema.TABLE_CONSTRAINTS
              WHERE TABLE_SCHEMA    = ?
                AND TABLE_NAME      = ?
                AND CONSTRAINT_TYPE = ?',
            [DB::getDatabaseName(), self::TABLE, 'PRIMARY KEY']
        );

        // Claim the primary key when the table has none; otherwise a plain
        // index satisfies MySQL's "must be defined as a key" requirement.
        DB::statement($hasPrimaryKey
            ? "ALTER TABLE {$table} MODIFY `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, ADD INDEX `pat_id_index` (`id`)"
            : "ALTER TABLE {$table} MODIFY `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, ADD PRIMARY KEY (`id`)");
    }

    /**
     * Ensure every row carries a unique, non-zero id so the key can be added.
     *
     * Without a primary key the table may have accumulated NULL, zero, or
     * duplicate ids, any of which would abort the ALTER.
     */
    private function normaliseExistingIds(): void
    {
        $stats = DB::selectOne(
            'SELECT COUNT(*) AS total,
                    COUNT(DISTINCT `id`) AS distinct_ids,
                    SUM(`id` IS NULL OR `id` = 0) AS unusable
               FROM `' . self::TABLE . '`'
        );

        if (!$stats || (int) $stats->total === 0) {
            return;
        }

        $needsRenumber = (int) $stats->distinct_ids !== (int) $stats->total
            || (int) $stats->unusable > 0;

        if (!$needsRenumber) {
            return;
        }

        DB::statement('SET @pat_row = 0');
        DB::statement(
            'UPDATE `' . self::TABLE . '` SET `id` = (@pat_row := @pat_row + 1) ORDER BY `created_at`, `token`'
        );
    }

    public function down(): void
    {
        // Deliberately irreversible: dropping the key or AUTO_INCREMENT would
        // reintroduce the login failure this migration exists to fix.
    }
};
