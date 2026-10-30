<?php

declare(strict_types=1);

namespace Survos\DuckDbBundle\Debug;

final class TraceableStatement extends \PDOStatement
{
    private ?int $profileEntry = null;

    protected function __construct(private readonly QueryRecorder $recorder, private readonly string $source, private readonly int $connectionId)
    {
    }

    public function execute(?array $params = null): bool
    {
        return $this->recorder->measure($this->source, 'execute', $this->queryString, fn () => parent::execute($params), $this->connectionId, $this);
    }

    public function setProfileEntry(int $entryId): void
    {
        $this->profileEntry = $entryId;
    }

    private function recordFetched(int $count): void
    {
        if (null !== $this->profileEntry) {
            $this->recorder->recordResult($this->profileEntry, $count);
        }
    }

    public function fetch(int $mode = \PDO::FETCH_DEFAULT, int $cursorOrientation = \PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        $row = parent::fetch($mode, $cursorOrientation, $cursorOffset);
        $this->recordFetched(false === $row ? 0 : 1);

        return $row;
    }

    public function fetchAll(int $mode = \PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        $rows = parent::fetchAll($mode, ...$args);
        $this->recordFetched(count($rows));

        return $rows;
    }

    public function fetchColumn(int $column = 0): mixed
    {
        $value = parent::fetchColumn($column);
        $this->recordFetched(false === $value ? 0 : 1);

        return $value;
    }

    public function fetchObject(?string $class = 'stdClass', array $constructorArgs = []): object|false
    {
        $row = parent::fetchObject($class, $constructorArgs);
        $this->recordFetched(false === $row ? 0 : 1);

        return $row;
    }

    public function getIterator(): \Iterator
    {
        $this->recordFetched(0);
        foreach (parent::getIterator() as $key => $row) {
            $this->recordFetched(1);
            yield $key => $row;
        }
    }
}
