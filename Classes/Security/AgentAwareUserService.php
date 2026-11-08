<?php

declare(strict_types=1);

namespace Sitegeist\SlopMachine\Security;

use Neos\Flow\Annotations as Flow;
use Neos\Neos\Domain\Model\User;
use Neos\Neos\Service\UserService;
use Sitegeist\SlopMachine\Domain\Repository\AgentAssignmentRepository;

/**
 * The user information of the policy expressions, with the Neos user of the agent for a token account.
 *
 * The policies of Neos compare the owner of a workspace with "current.userInformation.backendUser",
 * the Neos user of the authenticated account. The account a hash token authenticates is a machine
 * account: it has no Neos user attached, so the comparison treats the agent's own personal
 * workspace as the workspace of somebody else - and the workspace is closed, because the rule that
 * protects the personal workspaces of other users is granted to nobody.
 *
 * This service answers with the Neos user the agent is assigned to whenever the current account is
 * such a token account. Everything else is answered by the Neos user service as before: the roles
 * of the account are not touched, only the identity the policies compare with.
 */
#[Flow\Scope('singleton')]
class AgentAwareUserService extends UserService
{
    public function __construct(
        private readonly AgentAssignmentRepository $agentAssignmentRepository,
    ) {
    }

    /**
     * The Neos user of the current account - or the user the agent is assigned to, if the current
     * account is a token account without a Neos user of its own.
     */
    public function getBackendUser()
    {
        $user = parent::getBackendUser();
        if ($user instanceof User) {
            return $user;
        }

        return $this->assignedUser();
    }

    /**
     * The Neos user the calling agent is assigned to, or null when the current account is not an
     * agent account.
     */
    private function assignedUser(): ?User
    {
        $agentAssignment = $this->agentAssignmentRepository->findOneByCurrentAccount();

        return $agentAssignment?->user;
    }
}
