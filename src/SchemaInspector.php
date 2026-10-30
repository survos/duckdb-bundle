<?php

declare(strict_types=1);

namespace Survos\DuckDbBundle;

/** Metadata only, for the currently selected database. Never opens another source. */
final readonly class SchemaInspector
{
    public function inspect(\PDO $connection): array
    {
        $columns = $connection->query('SELECT t.table_schema AS schema, t.table_name AS name, t.table_type AS type, c.column_name AS columnName, c.data_type AS dataType, c.is_nullable AS nullable FROM information_schema.tables t JOIN information_schema.columns c ON c.table_catalog=t.table_catalog AND c.table_schema=t.table_schema AND c.table_name=t.table_name WHERE t.table_catalog=current_database() ORDER BY t.table_schema, t.table_name, c.ordinal_position LIMIT 2001')->fetchAll(\PDO::FETCH_ASSOC);

        return ['columns' => array_slice($columns, 0, 2000), 'truncated' => count($columns) > 2000];
    }
}
