<?php

declare(strict_types=1);

namespace Sitegeist\SlopMachine\Domain;

use Neos\ContentRepository\Domain\Model\Workspace;
use Neos\ContentRepository\Domain\Repository\WorkspaceRepository;
use Neos\Flow\Annotations as Flow;
use Neos\Neos\Domain\Service\UserService;
use Neos\Neos\Utility\User as UserUtility;
use Sitegeist\SlopMachine\Domain\Repository\AgentAssignmentRepository;

/**
 * Resolves the personal workspace of the Neos user the calling agent is assigned to.
 *
 * MCPContentContextFactory resolves the same workspace, but does so
 * privately - it can only be consumed by building a content context, which is not enough for the
 * workspace-wide operations in AgentWorkspaceCapabilities (listing and discarding unpublished
 * changes both need the Workspace itself). The lookup mirrors the assignment rules of
 * AgentCommandController::agentAssignToUserCommand().
 */
#[Flow\Scope('singleton')]
class AgentWorkspaceResolver
{
    public function __construct(
        protected WorkspaceRepository $workspaceRepository,
        protected AgentAssignmentRepository $agentAssignmentRepository,
        protected UserService $userService,
    ) {
    }

    public function getWorkspaceName(): string
    {
        $agentAssignment = $this->agentAssignmentRepository->findOneByCurrentAccount();
        if (!$agentAssignment) {
            throw new \RuntimeException('Agent is not assigned to a user', 1758250011);
        }

        $username = $this->userService->getUsername($agentAssignment->user);

        return $username === null
            ? throw new \RuntimeException('Failed to resolve user name for assigned user', 1758250012)
            : UserUtility::getPersonalWorkspaceNameForUsername($username);
    }

    /**
     * The name of the Neos user the calling agent is assigned to, or NULL if the agent is
     * not assigned to a user.
     *
     * The account identifier of the agent is the bearer token it authenticates with, so it
     * must not be written down anywhere - in a tag label for example. The name of the
     * assigned user identifies the agent without exposing the token.
     */
    public function getAssignedUsername(): ?string
    {
        $agentAssignment = $this->agentAssignmentRepository->findOneByCurrentAccount();
        if (!$agentAssignment) {
            return null;
        }

        return $this->userService->getUsername($agentAssignment->user);
    }

    public function getWorkspace(): Workspace
    {
        $workspaceName = $this->getWorkspaceName();
        $workspace = $this->workspaceRepository->findOneByName($workspaceName);
        if (!$workspace instanceof Workspace) {
            throw new \RuntimeException(
                \sprintf('The personal workspace "%s" of the assigned user does not exist.', $workspaceName),
                1758250013
            );
        }

        return $workspace;
    }
}
