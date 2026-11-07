<?php

declare(strict_types=1);

namespace Sitegeist\SlopMachine\Domain\Repository;

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Persistence\QueryInterface;
use Neos\Flow\Persistence\Repository;

/**
 * The instructions the administrators wrote.
 */
#[Flow\Scope('singleton')]
class InstructionRepository extends Repository
{
    /**
     * The entries are ordered by their position - the order they are sent to the agent in.
     *
     * @var array<string, string>
     */
    protected $defaultOrderings = [
        'position' => QueryInterface::ORDER_ASCENDING,
    ];
}
