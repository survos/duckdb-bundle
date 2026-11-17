<?php

declare(strict_types=1);

namespace Survos\DuckDbBundle;

/** On-demand SELECTs over a VARIANT column; no staging, sidecar or full profile. */
final class SnapshotInspector
{
    /**
     * Immediate object children at a path. Repeat with a returned path to drill down.
     * Presence includes explicit nulls. Arrays are leaves, not implicitly exploded.
     *
     * @param list<string> $path literal object keys (dots are not separators)
     *
     * @return array{fields: list<array{key: string, path: list<string>, present: int}>, truncated: bool}
     */
    public function fields(\PDO $db, string $table = 'records', array $path = [], string $column = 'body', int $limit = 2000): array
    {
        $this->validateLimit($limit);
        $value = $this->expression($column, $path);
        $table = $this->identifier($table);
        $rows = $db->query("SELECT k, count(*) present FROM (
            SELECT unnest(variant_keys(CASE WHEN variant_type($value) = 'OBJECT' THEN $value END)) k FROM $table
        ) GROUP BY k ORDER BY present DESC, k LIMIT ".($limit + 1))->fetchAll(\PDO::FETCH_ASSOC);
        $fields = [];
        foreach (array_slice($rows, 0, $limit) as $row) {
            $fields[] = ['key' => $row['k'], 'path' => [...$path, $row['k']], 'present' => (int) $row['present']];
        }

        return ['fields' => $fields, 'truncated' => count($rows) > $limit];
    }

    /**
     * Exact counts, bounded values. Missing and null share one bucket; counts are
     * per record. Arrays/objects are grouped as whole values, never as their elements.
     * valueJson retains JSON scalar types and large numeric values without PHP coercion.
     *
     * @param non-empty-list<string> $path literal object keys
     *
     * @return array{values: list<array{valueJson: string, count: int}>, truncated: bool}
     */
    public function distribution(\PDO $db, string $table, array $path, string $column = 'body', int $limit = 20): array
    {
        $this->validateLimit($limit);
        if ([] === $path) {
            throw new \InvalidArgumentException('Choose a field path for its distribution.');
        }
        $value = $this->expression($column, $path);
        $table = $this->identifier($table);
        $rows = $db->query("SELECT CAST(CAST(v AS JSON) AS VARCHAR) value_json, count(*) n
            FROM (SELECT $value v FROM $table) GROUP BY v ORDER BY n DESC, v ASC NULLS FIRST LIMIT ".($limit + 1))->fetchAll(\PDO::FETCH_ASSOC);
        $values = [];
        foreach (array_slice($rows, 0, $limit) as $row) {
            $values[] = ['valueJson' => $row['value_json'] ?? 'null', 'count' => (int) $row['n']];
        }

        return ['values' => $values, 'truncated' => count($rows) > $limit];
    }

    private function expression(string $column, array $path): string
    {
        if (!array_is_list($path)) {
            throw new \InvalidArgumentException('A field path must be a list of literal object keys.');
        }
        $expression = $this->identifier($column);
        foreach ($path as $key) {
            if (!is_string($key)) {
                throw new \InvalidArgumentException('Field path segments must be strings.');
            }
            $expression .= '.'.$this->identifier($key);
        }

        return $expression;
    }

    private function identifier(string $identifier): string
    {
        if (str_contains($identifier, "\0")) {
            throw new \InvalidArgumentException('Identifiers cannot contain NUL.');
        }

        return '"'.str_replace('"', '""', $identifier).'"';
    }

    private function validateLimit(int $limit): void
    {
        if ($limit < 1 || $limit > 10000) {
            throw new \InvalidArgumentException('Limit must be between 1 and 10000.');
        }
    }
}
