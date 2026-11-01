<?php

declare(strict_types=1);

namespace Survos\DuckDbBundle\Debug\Doctrine;

use Doctrine\DBAL\Driver\Middleware\AbstractResultMiddleware;
use Doctrine\DBAL\Driver\Result;
use Survos\DuckDbBundle\Debug\ProfiledResult;
use Survos\DuckDbBundle\Debug\QueryRecorder;

final class TraceableResult extends AbstractResultMiddleware implements ProfiledResult
{
    private ?int $entryId = null;

    public function __construct(Result $result, private readonly QueryRecorder $recorder)
    {
        parent::__construct($result);
    }

    public function setProfileEntry(int $entryId): void
    {
        $this->entryId = $entryId;
    }

    private function observed(mixed $result, bool $all = false): mixed
    {
        if (null !== $this->entryId) {
            $this->recorder->recordResult($this->entryId, $all ? count($result) : (int) (false !== $result));
        }

        return $result;
    }

    public function fetchNumeric(): array|false
    {
        return $this->observed(parent::fetchNumeric());
    }

    public function fetchAssociative(): array|false
    {
        return $this->observed(parent::fetchAssociative());
    }

    public function fetchOne(): mixed
    {
        return $this->observed(parent::fetchOne());
    }

    public function fetchAllNumeric(): array
    {
        return $this->observed(parent::fetchAllNumeric(), true);
    }

    public function fetchAllAssociative(): array
    {
        return $this->observed(parent::fetchAllAssociative(), true);
    }

    public function fetchFirstColumn(): array
    {
        return $this->observed(parent::fetchFirstColumn(), true);
    }
}
