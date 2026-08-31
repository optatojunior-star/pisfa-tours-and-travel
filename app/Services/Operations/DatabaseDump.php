<?php

namespace App\Services\Operations;

use Generator;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Dumps the database using PDO alone.
 *
 * Deliberately does not shell out to `mysqldump`. Hostinger and most shared
 * hosts disable `exec()`, `proc_open()` and friends, and where they do not, the
 * binary is often absent from PATH for the PHP user. A backup that silently
 * fails to run is worse than no backup at all, because it is believed. Reading
 * rows through the connection the application already has works everywhere the
 * application itself works.
 *
 * The trade-off is honest: this is slower than `mysqldump` and holds each row
 * briefly in PHP memory. Rows are streamed in chunks and written straight out,
 * so peak memory stays flat regardless of table size.
 */
class DatabaseDump
{
    /** Rows read per query. Small enough to stay flat in memory on a 128M host. */
    private const CHUNK = 500;

    public function __construct(private readonly ?Connection $connection = null) {}

    private function connection(): Connection
    {
        return $this->connection ?? DB::connection();
    }

    /**
     * Streams the dump as SQL text.
     *
     * A generator rather than a string: a returned string would need the whole
     * dump in memory at once, which is the one thing this class exists to
     * avoid.
     *
     * @return Generator<int, string>
     */
    public function stream(): Generator
    {
        $driver = $this->connection()->getDriverName();

        if (! in_array($driver, ['mysql', 'mariadb', 'sqlite'], true)) {
            throw new RuntimeException("Database dumps are not supported for the {$driver} driver.");
        }

        yield $this->header($driver);

        foreach ($this->tables() as $table) {
            yield "\n-- Table: {$table}\n";
            yield $this->createStatement($table, $driver);

            foreach ($this->rows($table) as $statement) {
                yield $statement;
            }
        }

        yield $this->footer($driver);
    }

    /** @return list<string> */
    public function tables(): array
    {
        $connection = $this->connection();

        // Laravel's schema builder abstracts the driver difference, which
        // matters because tests run on SQLite and production on MariaDB.
        $tables = array_map(
            static fn (array $table): string => (string) $table['name'],
            $connection->getSchemaBuilder()->getTables(),
        );

        sort($tables);

        return array_values(array_filter(
            $tables,
            // Internal bookkeeping tables that a restore rebuilds anyway.
            static fn (string $table): bool => ! in_array($table, ['sqlite_sequence'], true),
        ));
    }

    private function header(string $driver): string
    {
        $stamp = now()->toIso8601String();

        $sql = "-- PISFA Tours and Travel database dump\n";
        $sql .= "-- Taken: {$stamp}\n";
        $sql .= "-- Driver: {$driver}\n";
        $sql .= "-- Restore into a scratch database first. See docs/BACKUP_AND_RESTORE.md\n\n";

        if ($driver === 'sqlite') {
            return $sql."PRAGMA foreign_keys=OFF;\nBEGIN TRANSACTION;\n";
        }

        // Constraint checks are suspended for the load because rows arrive in
        // alphabetical table order, not dependency order.
        return $sql
            ."SET NAMES utf8mb4;\n"
            ."SET FOREIGN_KEY_CHECKS=0;\n"
            ."SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n"
            ."START TRANSACTION;\n";
    }

    private function footer(string $driver): string
    {
        return $driver === 'sqlite'
            ? "\nCOMMIT;\nPRAGMA foreign_keys=ON;\n"
            : "\nCOMMIT;\nSET FOREIGN_KEY_CHECKS=1;\n";
    }

    private function createStatement(string $table, string $driver): string
    {
        $quoted = $this->quoteIdentifier($table, $driver);

        if ($driver === 'sqlite') {
            $definition = $this->connection()->selectOne(
                'select sql from sqlite_master where type = ? and name = ?',
                ['table', $table],
            );

            $sql = is_object($definition) ? (string) ($definition->sql ?? '') : '';

            return "DROP TABLE IF EXISTS {$quoted};\n".($sql === '' ? '' : $sql.";\n");
        }

        $row = $this->connection()->selectOne("SHOW CREATE TABLE {$quoted}");
        $create = is_object($row) ? (array) $row : [];
        $definition = (string) ($create['Create Table'] ?? '');

        return "DROP TABLE IF EXISTS {$quoted};\n".($definition === '' ? '' : $definition.";\n");
    }

    /**
     * @return Generator<int, string>
     */
    private function rows(string $table): Generator
    {
        $driver = $this->connection()->getDriverName();
        $quoted = $this->quoteIdentifier($table, $driver);
        $offset = 0;

        while (true) {
            $rows = $this->connection()
                ->table($table)
                ->offset($offset)
                ->limit(self::CHUNK)
                ->get();

            if ($rows->isEmpty()) {
                return;
            }

            $values = [];

            foreach ($rows as $row) {
                $columns = (array) $row;

                $values[] = '('.implode(', ', array_map(
                    fn (mixed $value): string => $this->literal($value),
                    array_values($columns),
                )).')';
            }

            $columnList = implode(', ', array_map(
                fn (string $column): string => $this->quoteIdentifier($column, $driver),
                array_keys((array) $rows->first()),
            ));

            yield "INSERT INTO {$quoted} ({$columnList}) VALUES\n".implode(",\n", $values).";\n";

            if ($rows->count() < self::CHUNK) {
                return;
            }

            $offset += self::CHUNK;
        }
    }

    /**
     * Renders one value as a SQL literal.
     *
     * Binary is hex-encoded rather than escaped, because a dump has to survive
     * being written to a text file and read back without a byte changing.
     */
    private function literal(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        $string = (string) $value;

        if ($string !== '' && ! mb_check_encoding($string, 'UTF-8')) {
            return '0x'.bin2hex($string);
        }

        return $this->connection()->getPdo()->quote($string);
    }

    private function quoteIdentifier(string $identifier, string $driver): string
    {
        $escaped = str_replace('`', '``', $identifier);

        return $driver === 'sqlite' ? '"'.str_replace('"', '""', $identifier).'"' : "`{$escaped}`";
    }
}
