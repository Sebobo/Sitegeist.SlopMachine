<?php

declare(strict_types=1);

namespace Sitegeist\SlopMachine\Domain\Repository;

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Persistence\Doctrine\Repository;
use Neos\Flow\Security\Context;
use Sitegeist\SlopMachine\Domain\Model\AgentAssignment;

/**
 * @method findOneByAgentId(string $agentId): ?AgentAssignment
 */
#[Flow\Scope('singleton')]
class AgentAssignmentRepository extends Repository
{
    #[Flow\Inject]
    protected Context $securityContext;

    public function findOneByCurrentAccount(): ?AgentAssignment
    {
        $account = $this->securityContext->getAccount();

        return $account ? $this->findOneByAgentId($account->getAccountIdentifier()) : null;
    }
}
