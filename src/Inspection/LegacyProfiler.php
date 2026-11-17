<?php

declare(strict_types=1);

namespace Survos\DuckDbBundle\Inspection;

/** Optional exhaustive field_stats compatibility scan for JSON-origin VARIANT records. */
final class LegacyProfiler
{
    public function profile(\PDO $db, string $table, string $column = 'body', int $topN = 20, int $maxDepth = 6, int $maxFields = 2000): array
    {
        if ($topN < 1 || $maxDepth < 1 || $maxFields < 1) {
            throw new \InvalidArgumentException('Profile limits must be positive.');
        }
        $table = self::ident($table);
        $root = self::ident($column);
        $queue = [[$root, '', 0]];
        $fields = [];
        while ($queue) {
            [$parent, $prefix, $depth] = array_shift($queue);
            if ($depth >= $maxDepth) {
                continue;
            }
            $keys = $db->query("SELECT k, count(*) AS present FROM (SELECT unnest(variant_keys(CASE WHEN variant_type($parent)='OBJECT' THEN $parent END)) AS k FROM $table) GROUP BY k ORDER BY k")->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($keys as $keyInfo) {
                $key = $keyInfo['k'];
                // Match json_tree path spelling for ordinary and dotted keys.
                $part = preg_match('/^[A-Za-z][A-Za-z0-9]*$/D', $key) ? $key : json_encode($key, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $path = '' === $prefix ? $part : $prefix.'.'.$part;
                // Existing profiler excludes paths containing '[' and counts literal dots for depth.
                if (str_contains($path, '[') || substr_count($path, '.') + 1 > $maxDepth) {
                    continue;
                }
                $v = $parent.'.'.self::ident($key);
                $types = [];
                $nonNullIncludingObjects = 0;
                foreach ($db->query("SELECT variant_type($v) t, count(*) c FROM $table WHERE $v IS NOT NULL GROUP BY t") as $r) {
                    $nonNullIncludingObjects += (int) $r['c'];
                    $type = self::jsonType($r['t']);
                    if ('object' === $type) {
                        $queue[] = [$v, $path, $depth + 1];
                    } else {
                        $types[$type] = ($types[$type] ?? 0) + (int) $r['c'];
                    }
                }
                $nulls = (int) $keyInfo['present'] - $nonNullIncludingObjects;
                if ($nulls > 0) {
                    $types['null'] = $nulls;
                }
                if (!$types) {
                    continue;
                }
                ksort($types);
                $scalar = "variant_type($v) NOT IN ('OBJECT','ARRAY','NULL')";
                $value = self::sqliteValue($v);
                // Normalize booleans to SQLite atoms (0/1); VARIANT groups equal numeric values together.
                $source = "SELECT $value AS val FROM $table WHERE $scalar";
                $hasScalar = [] !== array_diff(array_keys($types), ['array', 'null']);
                $a = $hasScalar ? $db->query("SELECT count(DISTINCT variant_normalize(val)) distinct_n, CAST(CAST(min(val) AS JSON) AS VARCHAR) min_j, CAST(CAST(max(val) AS JSON) AS VARCHAR) max_j,
                    min(length(CAST(val AS VARCHAR))) FILTER (WHERE variant_type(val)='VARCHAR') len_min,
                    max(length(CAST(val AS VARCHAR))) FILTER (WHERE variant_type(val)='VARCHAR') len_max,
                    avg(length(CAST(val AS VARCHAR))) FILTER (WHERE variant_type(val)='VARCHAR') len_avg FROM ($source)")->fetch(\PDO::FETCH_ASSOC) : ['distinct_n' => 0, 'min_j' => null, 'max_j' => null, 'len_min' => null, 'len_max' => null, 'len_avg' => null];
                $top = $hasScalar ? $this->top($db, $source, $topN) : [];
                $fields[$path] = [
                    'path' => $path, 'json_types' => $types, 'present' => array_sum($types),
                    'non_null' => array_sum($types) - ($types['null'] ?? 0),
                    'distinct_n' => (int) $a['distinct_n'], 'min_v' => self::stringValue($a['min_j']), 'max_v' => self::stringValue($a['max_j']),
                    'len_min' => null === $a['len_min'] ? null : (int) $a['len_min'],
                    'len_max' => null === $a['len_max'] ? null : (int) $a['len_max'],
                    'len_avg' => null === $a['len_avg'] ? null : round((float) $a['len_avg'], 2),
                    'top_values' => $top, 'is_array' => isset($types['array']) ? 1 : 0,
                    'heuristics' => $this->heuristics($top), 'elements' => null,
                ];
                if (isset($types['array'])) {
                    $elements = "SELECT unnest(CASE WHEN variant_type($v)='ARRAY' THEN CAST($v AS VARIANT[]) END) AS e FROM $table";
                    $ev = self::sqliteValue('e');
                    $sql = "WITH elements AS ($elements), classified AS (
                        SELECT coalesce(variant_type(e),'NULL') NOT IN ('OBJECT','ARRAY') AS scalar, e FROM elements
                    ), groups AS MATERIALIZED (
                        SELECT scalar, CASE WHEN scalar THEN $ev END val, count(*) c FROM classified GROUP BY scalar, val
                    ), summary AS (
                        SELECT coalesce(sum(c),0) total, count(*) FILTER (WHERE scalar AND val IS NOT NULL) distinct_n FROM groups
                    ), top AS (
                        SELECT val, c FROM groups WHERE scalar ORDER BY c DESC, val ASC NULLS FIRST LIMIT $topN
                    ) SELECT total, distinct_n, CAST(CAST(val AS JSON) AS VARCHAR) j, c FROM summary LEFT JOIN top ON true ORDER BY c DESC, val ASC NULLS FIRST";
                    $top = [];
                    foreach ($db->query($sql) as $r) {
                        $a = (int) $r['total'];
                        $n = (int) $r['distinct_n'];
                        if (null !== $r['c']) {
                            $top[] = ['value' => null === $r['j'] ? null : json_decode($r['j'], true, flags: JSON_THROW_ON_ERROR), 'count' => (int) $r['c']];
                        }
                    }
                    $fields[$path]['elements'] = ['count' => $a, 'distinct' => $n, 'avgPerRow' => round($a / $types['array'], 2), 'top' => $top];
                }
            }
        }
        $truncated = max(0, count($fields) - $maxFields);
        uasort($fields, static fn ($a, $b) => ($b['present'] <=> $a['present']) ?: strcmp($a['path'], $b['path']));
        $fields = array_slice($fields, 0, $maxFields, true);

        return ['rows' => (int) $db->query("SELECT count(*) FROM $table")->fetchColumn(), 'fields' => array_values($fields), 'invalid' => 0, 'truncated' => $truncated];
    }

    public static function ident(string $s): string
    {
        return '"'.str_replace('"', '""', $s).'"';
    }

    private static function jsonType(?string $type): string
    {
        return match ($type) {
            null, 'NULL' => 'null', 'VARCHAR' => 'text', 'OBJECT' => 'object', 'ARRAY' => 'array',
            'BOOL_TRUE' => 'true', 'BOOL_FALSE' => 'false',
            'FLOAT', 'DOUBLE', 'DECIMAL' => 'real',
            'INT8','INT16','INT32','INT64','INT128','UINT8','UINT16','UINT32','UINT64','UINT128' => 'integer',
            default => throw new \RuntimeException('Non-JSON VARIANT type requires an explicit profile mapping: '.$type),
        };
    }

    public static function sqliteValue(string $v): string
    {
        return "CASE WHEN variant_type($v)='BOOL_TRUE' THEN CAST(1 AS BIGINT)::VARIANT
            WHEN variant_type($v)='BOOL_FALSE' THEN CAST(0 AS BIGINT)::VARIANT
            WHEN variant_type($v) LIKE '%INT%' THEN coalesce(try_cast($v AS BIGINT)::VARIANT, TRY_CAST($v AS DOUBLE)::VARIANT)
            WHEN variant_type($v) IN ('FLOAT','DOUBLE','DECIMAL') THEN
                CASE WHEN CAST(try_cast($v AS BIGINT) AS DOUBLE)=TRY_CAST($v AS DOUBLE)
                THEN TRY_CAST($v AS BIGINT)::VARIANT ELSE TRY_CAST($v AS DOUBLE)::VARIANT END
            ELSE $v END";
    }

    private static function stringValue(?string $json): ?string
    {
        return null === $json ? null : (string) json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    }

    private function top(\PDO $db, string $source, int $n): array
    {
        $out = [];
        foreach ($db->query("SELECT CAST(CAST(val AS JSON) AS VARCHAR) j, count(*) c FROM ($source) GROUP BY val ORDER BY c DESC, val ASC NULLS FIRST LIMIT $n") as $r) {
            $out[] = ['value' => null === $r['j'] ? null : json_decode($r['j'], true, flags: JSON_THROW_ON_ERROR), 'count' => (int) $r['c']];
        }

        return $out;
    }

    private function heuristics(array $topValues): array
    {
        $strings = array_values(array_filter(array_column($topValues, 'value'), static fn ($s) => is_string($s) && '' !== $s));
        if (!$strings) {
            return [];
        }
        $url = $img = $nl = 0;
        foreach ($strings as $s) {
            if ((str_starts_with($s, 'http://') || str_starts_with($s, 'https://')) && false !== filter_var($s, FILTER_VALIDATE_URL)) {
                ++$url;
                if (in_array(strtolower(pathinfo((string) parse_url($s, PHP_URL_PATH), PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg', 'bmp'], true)) {
                    ++$img;
                }
            }
            if (substr_count(trim($s), ' ') >= 2) {
                ++$nl;
            }
        }
        $h = [];
        if ($url / count($strings) >= .6) {
            $h['urlLike'] = true;
        }
        if ($img / count($strings) >= .5) {
            $h['imageLike'] = true;
        }
        if ($nl / count($strings) >= .5) {
            $h['naturalLanguageLike'] = true;
        }

        return $h;
    }
}
