<?php

declare(strict_types=1);

namespace Survos\DuckDbBundle\Debug;

/** A result that can associate later fetches with its recorded execution. */
interface ProfiledResult
{
    public function setProfileEntry(int $entryId): void;
}
