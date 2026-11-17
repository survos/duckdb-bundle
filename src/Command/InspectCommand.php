<?php

declare(strict_types=1);

namespace Survos\DuckDbBundle\Command;

use Survos\DuckDbBundle\ConnectionFactory;
use Survos\DuckDbBundle\Inspection\LegacyProfileExporter;
use Survos\DuckDbBundle\NamedSources;
use Survos\DuckDbBundle\SnapshotInspector;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('duckdb:inspect', 'Browse snapshot fields or count facet values without a sidecar')]
final class InspectCommand
{
    public function __construct(
        private readonly ConnectionFactory $factory,
        private readonly NamedSources $sources,
        private readonly SnapshotInspector $inspector,
        private readonly LegacyProfileExporter $exporter,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Snapshot file/URI, or configured source name with --named')]
        string $source,
        #[Argument('Record table')]
        string $table = 'records',
        #[Option('Interpret source as a configured named source')]
        bool $named = false,
        #[Option('VARIANT record column')]
        string $column = 'body',
        #[Option('Literal object key; repeat in order to select a nested path')]
        array $path = [],
        #[Option('Return value counts at the selected path instead of child fields')]
        bool $distribution = false,
        #[Option('Maximum returned fields or value buckets (1–10000)')]
        int $limit = 20,
        #[Option('Run the exhaustive optional JSONL-compatible profile export')]
        bool $legacyProfile = false,
    ): int {
        if ($legacyProfile && ($distribution || [] !== $path)) {
            throw new \InvalidArgumentException('Legacy profile export cannot be combined with a field path or distribution.');
        }
        $db = $named ? $this->sources->open($source) : $this->factory->open($source);
        $result = $legacyProfile
            ? $this->exporter->export($db, $source, $table, $column)
            : ($distribution
                ? $this->inspector->distribution($db, $table, $path, $column, $limit)
                : $this->inspector->fields($db, $table, $path, $column, $limit));
        $io->writeln(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);

        return Command::SUCCESS;
    }
}
