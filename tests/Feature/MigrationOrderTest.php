<?php

/*
| Guard for the MySQL-only migration failure class: SQLite (dev/CI tests)
| IGNORES column-position clauses, so an ->after('x') pointing at a column
| that does not exist yet — added later in the sequence, or already dropped —
| only explodes on the production MySQL server. This test replays every
| migration's up() in filename order, tracking each table's column set, and
| fails on any ->after() target that is absent at that point in history.
| (The real-engine proof is CI's mysql-migrations job; this one pinpoints
| the offending line on every engine, instantly.)
*/

/** Column-adding Blueprint methods whose first argument is the column name. */
const MIGRATION_COLUMN_METHODS = 'id|foreignId|string|char|text|mediumText|longText|boolean|integer|tinyInteger'
    .'|smallInteger|bigInteger|unsignedInteger|unsignedTinyInteger|unsignedSmallInteger|unsignedBigInteger'
    .'|decimal|float|double|json|jsonb|date|dateTime|dateTimeTz|time|timestamp|timestampTz|uuid|ulid'
    .'|ipAddress|macAddress|enum|year|binary';

it('never positions a column after() one that does not exist at that point in migration history', function () {
    $files = glob(database_path('migrations/*.php')) ?: [];
    sort($files);

    /** @var array<string, array<string, true>> $columns table => column set */
    $columns = [];
    $violations = [];

    foreach ($files as $file) {
        $source = (string) file_get_contents($file);

        // up() only: down() runs against reverse history and is exercised by
        // rollback, not by a resuming `migrate --force`.
        $downAt = strpos($source, 'function down');
        $up = $downAt === false ? $source : substr($source, 0, $downAt);

        $table = null;

        foreach (preg_split('/\R/', $up) ?: [] as $line) {
            if (preg_match('/Schema::(create|table)\(\s*[\'"]([^\'"]+)[\'"]/', $line, $m) === 1) {
                $table = $m[2];
                if ($m[1] === 'create') {
                    $columns[$table] = [];
                }

                continue;
            }

            if ($table === null || ! str_contains($line, '$table->')) {
                continue;
            }

            // Check the position clause BEFORE registering this line's add.
            if (preg_match('/->after\(\s*[\'"]([^\'"]+)[\'"]\s*\)/', $line, $m) === 1
                && ! isset($columns[$table][$m[1]])) {
                $violations[] = basename($file).": {$table} ->after('{$m[1]}') — no such column at this point in the sequence";
            }

            // Register added columns.
            if (preg_match('/\$table->(?:'.MIGRATION_COLUMN_METHODS.')\(\s*[\'"]([^\'"]+)[\'"]/', $line, $m) === 1) {
                $columns[$table][$m[1]] = true;
            }
            if (preg_match('/\$table->id\(\s*\)/', $line) === 1) {
                $columns[$table]['id'] = true;
            }
            if (preg_match('/\$table->timestamps\(/', $line) === 1) {
                $columns[$table]['created_at'] = $columns[$table]['updated_at'] = true;
            }
            if (preg_match('/\$table->softDeletes\(/', $line) === 1) {
                $columns[$table]['deleted_at'] = true;
            }
            if (preg_match('/\$table->rememberToken\(/', $line) === 1) {
                $columns[$table]['remember_token'] = true;
            }
            if (preg_match('/\$table->(?:nullable)?[mM]orphs\(\s*[\'"]([^\'"]+)[\'"]/', $line, $m) === 1) {
                $columns[$table][$m[1].'_id'] = $columns[$table][$m[1].'_type'] = true;
            }

            // Register drops — a dropped column is gone for every later after().
            if (preg_match('/\$table->dropColumn\(\s*(\[[^\]]*\]|[\'"][^\'"]+[\'"])/', $line, $m) === 1) {
                preg_match_all('/[\'"]([^\'"]+)[\'"]/', $m[1], $dropped);
                foreach ($dropped[1] as $column) {
                    unset($columns[$table][$column]);
                }
            }
            if (preg_match('/\$table->renameColumn\(\s*[\'"]([^\'"]+)[\'"]\s*,\s*[\'"]([^\'"]+)[\'"]/', $line, $m) === 1) {
                unset($columns[$table][$m[1]]);
                $columns[$table][$m[2]] = true;
            }
        }
    }

    expect($violations)->toBe([]);
});
