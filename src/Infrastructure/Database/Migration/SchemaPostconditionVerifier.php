<?php

declare(strict_types=1);

namespace Veyra\Infrastructure\Database\Migration;

// Internal migration exceptions are never rendered; adapters escape at the output boundary.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped

/** Verifies the material table, column, index, and engine postconditions described by CREATE SQL. */
final class SchemaPostconditionVerifier
{
    /** @param list<string> $statements */
    public static function verifyCreateStatements(\wpdb $database, array $statements): void
    {
        foreach ($statements as $statement) {
            $definition = self::parseCreateStatement($statement);
            $table = $definition['table'];

            $actualColumns = $database->get_col("SHOW COLUMNS FROM `{$table}`", 0);
            if (!is_array($actualColumns)) {
                throw new \RuntimeException('Migration table postcondition could not be read: ' . $table);
            }
            $actualColumns = array_values(array_filter($actualColumns, 'is_string'));
            $missing = array_values(array_diff($definition['columns'], $actualColumns));
            if ($missing !== []) {
                throw new \RuntimeException(
                    'Migration column postcondition failed for ' . $table . ': ' . implode(',', $missing)
                );
            }

            self::verifyIndexes($database, $table, $definition['indexes']);
            self::verifyEngine($database, $table, $definition['engine']);
        }
    }

    /**
     * @return array{
     *     table:string,
     *     columns:list<string>,
     *     indexes:array<string,array{unique:bool,columns:list<string>}>,
     *     engine:string
     * }
     */
    private static function parseCreateStatement(string $statement): array
    {
        if (!preg_match(
            '/^\s*CREATE\s+TABLE\s+([^\s(]+)\s*\((.*)\)\s*ENGINE\s*=\s*([A-Za-z0-9_]+)\b/is',
            $statement,
            $matches
        )) {
            throw new \InvalidArgumentException('Unsupported migration CREATE statement.');
        }
        $table = trim($matches[1], "` \t\r\n");
        if ($table === '' || !preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            throw new \InvalidArgumentException('Unsafe migration table identifier.');
        }
        $engine = $matches[3];
        if (strcasecmp($engine, 'InnoDB') !== 0) {
            throw new \InvalidArgumentException('Migration CREATE statement must require the InnoDB engine.');
        }

        $columns = [];
        $indexes = [];
        foreach (preg_split('/,\s*(?:\r?\n|$)/', trim($matches[2])) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $index = self::parseIndexDefinition($line);
            if ($index !== null) {
                if (isset($indexes[$index['name']])) {
                    throw new \InvalidArgumentException('Duplicate migration index definition: ' . $index['name']);
                }
                $indexes[$index['name']] = [
                    'unique' => $index['unique'],
                    'columns' => $index['columns'],
                ];
                continue;
            }
            if (preg_match('/^(?:PRIMARY|UNIQUE|KEY|FULLTEXT|SPATIAL|CONSTRAINT|FOREIGN)\b/i', $line)) {
                throw new \InvalidArgumentException('Unsupported migration index or constraint definition.');
            }
            if (!preg_match('/^`?([A-Za-z0-9_]+)`?\s+/', $line, $column)) {
                throw new \InvalidArgumentException('Unsupported migration column definition.');
            }
            $columns[] = $column[1];
        }
        if ($columns === []) {
            throw new \InvalidArgumentException('Migration CREATE statement has no columns.');
        }
        if (!isset($indexes['PRIMARY'])) {
            throw new \InvalidArgumentException('Migration CREATE statement has no primary key.');
        }

        return [
            'table' => $table,
            'columns' => array_values(array_unique($columns)),
            'indexes' => $indexes,
            'engine' => $engine,
        ];
    }

    /** @return array{name:string,unique:bool,columns:list<string>}|null */
    private static function parseIndexDefinition(string $line): ?array
    {
        $name = null;
        $unique = false;
        $columnList = null;

        if (preg_match('/^PRIMARY\s+KEY\s*\((.+)\)$/i', $line, $matches)) {
            $name = 'PRIMARY';
            $unique = true;
            $columnList = $matches[1];
        } elseif (preg_match('/^UNIQUE\s+(?:KEY|INDEX)\s+`?([A-Za-z0-9_]+)`?\s*\((.+)\)$/i', $line, $matches)) {
            $name = $matches[1];
            $unique = true;
            $columnList = $matches[2];
        } elseif (preg_match('/^(?:KEY|INDEX)\s+`?([A-Za-z0-9_]+)`?\s*\((.+)\)$/i', $line, $matches)) {
            $name = $matches[1];
            $columnList = $matches[2];
        } else {
            return null;
        }

        $columns = [];
        foreach (explode(',', $columnList) as $column) {
            $column = trim($column);
            // Prefix length is intentionally not required to match exactly;
            // index uniqueness and ordered column membership are material.
            if (!preg_match('/^`?([A-Za-z0-9_]+)`?(?:\s*\(\s*\d+\s*\))?(?:\s+(?:ASC|DESC))?$/i', $column, $matches)) {
                throw new \InvalidArgumentException('Unsupported migration index column definition.');
            }
            $columns[] = $matches[1];
        }
        if ($columns === []) {
            throw new \InvalidArgumentException('Migration index definition has no columns.');
        }

        return ['name' => $name, 'unique' => $unique, 'columns' => $columns];
    }

    /** @param array<string,array{unique:bool,columns:list<string>}> $requiredIndexes */
    private static function verifyIndexes(\wpdb $database, string $table, array $requiredIndexes): void
    {
        $rows = $database->get_results("SHOW INDEX FROM `{$table}`");
        if (!is_array($rows)) {
            throw new \RuntimeException('Migration index postcondition could not be read: ' . $table);
        }

        $requiredNames = [];
        foreach (array_keys($requiredIndexes) as $name) {
            $requiredNames[$name === 'PRIMARY' ? 'PRIMARY' : strtolower($name)] = true;
        }

        /** @var array<string,array{unique:bool,columns:array<int,string>}> $actualIndexes */
        $actualIndexes = [];
        foreach ($rows as $row) {
            $values = self::normalizeDatabaseRow($row);
            $name = self::rowValue($values, 'Key_name');
            if (!is_string($name) || $name === '') {
                throw new \RuntimeException('Migration index postcondition returned malformed state: ' . $table);
            }
            $normalizedName = strcasecmp($name, 'PRIMARY') === 0 ? 'PRIMARY' : strtolower($name);
            // Merchant- or extension-added indexes are not migration
            // postconditions. Ignore their implementation details.
            if (!isset($requiredNames[$normalizedName])) {
                continue;
            }

            $nonUnique = self::rowValue($values, 'Non_unique');
            $sequence = self::rowValue($values, 'Seq_in_index');
            $column = self::rowValue($values, 'Column_name');
            if (!is_numeric($nonUnique)
                || !is_numeric($sequence)
                || (int) $sequence < 1
                || !is_string($column)
                || $column === ''
            ) {
                throw new \RuntimeException('Migration index postcondition returned malformed state: ' . $table);
            }

            $unique = (int) $nonUnique === 0;
            if (isset($actualIndexes[$normalizedName]) && $actualIndexes[$normalizedName]['unique'] !== $unique) {
                throw new \RuntimeException('Migration index postcondition returned inconsistent state: ' . $table);
            }
            $actualIndexes[$normalizedName] ??= ['unique' => $unique, 'columns' => []];
            if (isset($actualIndexes[$normalizedName]['columns'][(int) $sequence])) {
                throw new \RuntimeException('Migration index postcondition returned duplicate sequence state: ' . $table);
            }
            $actualIndexes[$normalizedName]['columns'][(int) $sequence] = $column;
        }

        foreach ($requiredIndexes as $name => $required) {
            $normalizedName = $name === 'PRIMARY' ? 'PRIMARY' : strtolower($name);
            if (!isset($actualIndexes[$normalizedName])) {
                throw new \RuntimeException('Migration index postcondition failed for ' . $table . ': ' . $name);
            }
            $actual = $actualIndexes[$normalizedName];
            ksort($actual['columns']);
            if (array_keys($actual['columns']) !== range(1, count($actual['columns']))) {
                throw new \RuntimeException('Migration index postcondition returned non-contiguous state: ' . $table);
            }
            $actualColumns = array_values($actual['columns']);
            if ($actual['unique'] !== $required['unique'] || $actualColumns !== $required['columns']) {
                throw new \RuntimeException('Migration index postcondition failed for ' . $table . ': ' . $name);
            }
        }
    }

    private static function verifyEngine(\wpdb $database, string $table, string $requiredEngine): void
    {
        $query = $database->prepare('SHOW TABLE STATUS WHERE Name = %s', $table);
        $row = $database->get_row($query);
        if (!is_object($row) && !is_array($row)) {
            throw new \RuntimeException('Migration engine postcondition could not be read: ' . $table);
        }
        $engine = self::rowValue(self::normalizeDatabaseRow($row), 'Engine');
        if (!is_string($engine) || $engine === '' || strcasecmp($engine, $requiredEngine) !== 0) {
            throw new \RuntimeException('Migration engine postcondition failed for ' . $table . ': ' . $requiredEngine);
        }
    }

    /** @return array<string,mixed> */
    private static function normalizeDatabaseRow(mixed $row): array
    {
        if (!is_array($row) && !is_object($row)) {
            return [];
        }

        return (array) $row;
    }

    /** @param array<string,mixed> $row */
    private static function rowValue(array $row, string $name): mixed
    {
        foreach ($row as $key => $value) {
            if (is_string($key) && strcasecmp($key, $name) === 0) {
                return $value;
            }
        }

        return null;
    }
}
