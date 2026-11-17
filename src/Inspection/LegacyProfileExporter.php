<?php

declare(strict_types=1);

namespace Survos\DuckDbBundle\Inspection;

use Survos\JsonlBundle\Sqlite\LegacyProfile;

/** Compatibility export only; ordinary snapshot inspection never invokes this scan. */
final class LegacyProfileExporter
{
    public function export(\PDO $db, string $input, string $table = 'records', string $column = 'body'): array
    {
        if (!class_exists(LegacyProfile::class)) {
            throw new \LogicException('Legacy profile export requires survos/jsonl-bundle with Sqlite\\LegacyProfile support.');
        }
        $result = (new LegacyProfiler())->profile($db, $table, $column);

        return LegacyProfile::full($input, $result['rows'], $result['fields']);
    }
}
