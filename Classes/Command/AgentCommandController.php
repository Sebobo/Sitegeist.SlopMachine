<?php

declare(strict_types=1);

namespace Sitegeist\SlopMachine\Command;

use Neos\ContentRepository\Domain\Model\Workspace;
use Neos\ContentRepository\Domain\Repository\WorkspaceRepository;
use Neos\Flow\Cli\CommandController;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Flow\Security\Exception\NoSuchRoleException;
use Neos\Neos\Domain\Model\User;
use Neos\Neos\Domain\Service\UserService;
use Neos\Neos\Utility\User as UserUtility;
use Sitegeist\SlopMachine\Domain\Model\AgentAssignment;
use Sitegeist\SlopMachine\Domain\Repository\AgentAssignmentRepository;
use Sitegeist\SlopMachine\Domain\SharedWorkspaceContextFactory;

class AgentCommandController extends CommandController
{
    /**
     * The role of the AI agent, defined in the Policy.yaml of this package: a restricted editor
     * that may read the content repository over MCP, and whose backend login is denied.
     */
    public const AGENT_ROLE_IDENTIFIER = 'Sitegeist.SlopMachine:McpAgent';

    /**
     * The name of the AI agent account, unless another one is passed to the command.
     */
    public const DEFAULT_AGENT_USERNAME = 'mcp-agent';

    public function __construct(
        private readonly UserService $userService,
        private readonly AgentAssignmentRepository $agentAssignmentRepository,
        private readonly WorkspaceRepository $workspaceRepository,
        private readonly SharedWorkspaceContextFactory $sharedWorkspaceContextFactory,
        private readonly PersistenceManagerInterface $persistenceManager,
    ) {
        parent::__construct();
    }

    public function assignToUserCommand(string $agentId, string $username): void
    {
        $agentAssignment = $this->agentAssignmentRepository->findOneByAgentId($agentId);
        if ($agentAssignment) {
            $this->outputLine('Agent ' . $agentId . ' is already assigned.');
            $this->sendAndExit();
        }

        $user = $this->userService->getUser($username);
        if (!$user) {
            $this->outputLine('Unknown user ' . $username);
            $this->sendAndExit();
        }

        $agentAssignment = new AgentAssignment($agentId, $user);
        $this->agentAssignmentRepository->add($agentAssignment);

        $this->outputLine('Agent successfully assigned.');
    }

    /**
     * Creates the shared review workspace the agent hands its work over to, if it does not exist yet.
     *
     * The name is the one configured in Sitegeist.SlopMachine.agent.sharedWorkspace - the very
     * workspace the agent's tools address - so the command cannot set up a workspace the agent
     * would not use. Pass a name explicitly to create a different one.
     *
     * The workspace is based on "live", because a human reviews and publishes it: the agent never
     * publishes anything itself.
     *
     * @param string|null $workspace Name of the workspace, the configured shared workspace by default
     * @param string $baseWorkspace Name of the base workspace, "live" by default
     */
    public function createSharedWorkspaceCommand(?string $workspace = null, string $baseWorkspace = 'live'): void
    {
        if ($workspace === null) {
            try {
                $workspace = $this->sharedWorkspaceContextFactory->getWorkspaceName();
            } catch (\RuntimeException $exception) {
                $this->outputLine('<error>%s</error>', [$exception->getMessage()]);
                $this->quit(1);
            }
        }

        $existingWorkspace = $this->workspaceRepository->findOneByName($workspace);
        if ($existingWorkspace instanceof Workspace) {
            $this->outputLine('The shared workspace "%s" already exists.', [$workspace]);
            $this->outputLine('Humans review and publish this workspace - the agent never publishes anything itself.');

            return;
        }

        $base = $this->workspaceRepository->findOneByName($baseWorkspace);
        if (!$base instanceof Workspace) {
            $this->outputLine('<error>The base workspace "%s" does not exist.</error>', [$baseWorkspace]);
            $this->quit(1);
        }

        $sharedWorkspace = new Workspace($workspace, $base);
        $sharedWorkspace->setDescription('Shared review workspace for the work of the AI agent, published by hand.');
        $this->workspaceRepository->add($sharedWorkspace);

        $this->outputLine('Created the shared workspace "%s", based on "%s".', [$workspace, $baseWorkspace]);
        $this->outputLine('Humans review and publish this workspace - the agent never publishes anything itself.');
    }

    /**
     * Creates the Neos user the AI agent works as, if it does not exist yet.
     *
     * The account exists for the content repository: the agent works in the personal workspace of
     * this user and hands its work over to the shared review workspace. It is a machine identity,
     * so the account gets a random password that is never printed - the agent authenticates with
     * a bearer token, never with a password. The role of the agent denies the backend login on
     * top of that.
     *
     * The role is assigned to the account as well, whether the account was just created or
     * existed before.
     *
     * @param string $username Name of the user, "mcp-agent" by default
     */
    public function createUserCommand(string $username = self::DEFAULT_AGENT_USERNAME): void
    {
        try {
            $user = $this->userService->getUser($username);
            if ($user instanceof User) {
                $this->outputLine('The user "%s" already exists.', [$username]);
            } else {
                $user = $this->userService->createUser(
                    $username,
                    $this->generateUnusablePassword(),
                    'MCP',
                    'Agent',
                    [self::AGENT_ROLE_IDENTIFIER]
                );
                $this->outputLine('Created the user "%s".', [$username]);
                $this->outputLine('The account has a random password that is not printed: the agent authenticates with a bearer token, never with a password.');
            }

            $this->assignAgentRole($user, $username);
        } catch (NoSuchRoleException) {
            $this->outputLine(
                '<error>The role "%s" does not exist: it is defined in the Policy.yaml of the Sitegeist.SlopMachine package,'
                . ' so the policy has to be reloaded first ("flow:cache:flush").</error>',
                [self::AGENT_ROLE_IDENTIFIER]
            );
            $this->quit(1);
        }

        $this->basePersonalWorkspaceOnSharedWorkspace($username);

        $this->outputLine('Next: create a token for the role "%s" and assign it to this user:', [self::AGENT_ROLE_IDENTIFIER]);
        $this->outputLine('  ./flow hashtoken:createhashtoken --role-names %s --label "..." --expires-at "..."', [self::AGENT_ROLE_IDENTIFIER]);
        $this->outputLine('  ./flow agent:assigntouser --agent-id <the token> --username %s', [$username]);
    }

    /**
     * Assigns the role of the agent to the given user, if it is not assigned yet.
     */
    private function assignAgentRole(User $user, string $username): void
    {
        $assignedCount = $this->userService->addRoleToUser($user, self::AGENT_ROLE_IDENTIFIER);

        if ($assignedCount > 0) {
            $this->outputLine('Assigned the role "%s" to "%s".', [self::AGENT_ROLE_IDENTIFIER, $username]);
        }
    }

    /**
     * Bases the personal workspace of the agent on the shared review workspace.
     *
     * The agent works in its personal workspace and hands its work over to the shared one, so the
     * personal workspace has to be based on the shared workspace: only then does the agent see
     * what the humans are reviewing, and its own diff is exactly the handover diff.
     */
    private function basePersonalWorkspaceOnSharedWorkspace(string $username): void
    {
        $personalWorkspaceName = UserUtility::getPersonalWorkspaceNameForUsername($username);

        $sharedWorkspaceName = $this->resolveSharedWorkspaceName();
        if ($sharedWorkspaceName === null) {
            $this->outputLine(
                '<comment>No shared workspace is configured, so the personal workspace "%s" stays based on "live".</comment>',
                [$personalWorkspaceName]
            );

            return;
        }

        $sharedWorkspace = $this->workspaceRepository->findOneByName($sharedWorkspaceName);
        if (!$sharedWorkspace instanceof Workspace) {
            $this->outputLine(
                '<comment>The shared workspace "%s" does not exist yet, so the personal workspace "%s" stays based on "live".'
                . ' Run "agent:createsharedworkspace" and rebase it afterwards.</comment>',
                [$sharedWorkspaceName, $personalWorkspaceName]
            );

            return;
        }

        $personalWorkspace = $this->findPersonalWorkspace($personalWorkspaceName);
        if (!$personalWorkspace instanceof Workspace) {
            $this->outputLine('<error>The personal workspace "%s" does not exist.</error>', [$personalWorkspaceName]);

            return;
        }

        $currentBaseWorkspace = $personalWorkspace->getBaseWorkspace();
        if ($currentBaseWorkspace instanceof Workspace && $currentBaseWorkspace->getName() === $sharedWorkspace->getName()) {
            $this->outputLine('The personal workspace "%s" is already based on "%s".', [$personalWorkspaceName, $sharedWorkspace->getName()]);

            return;
        }

        $personalWorkspace->setBaseWorkspace($sharedWorkspace);
        $this->workspaceRepository->update($personalWorkspace);

        $this->outputLine('Based the personal workspace "%s" on "%s".', [$personalWorkspaceName, $sharedWorkspace->getName()]);
    }

    /**
     * Finds the personal workspace of the agent.
     *
     * The workspace is created together with the user, so it is not persisted yet when the user
     * was just created - the query does not find it before the persistence manager has written it.
     */
    private function findPersonalWorkspace(string $personalWorkspaceName): ?Workspace
    {
        $personalWorkspace = $this->workspaceRepository->findOneByName($personalWorkspaceName);
        if ($personalWorkspace instanceof Workspace) {
            return $personalWorkspace;
        }

        $this->persistenceManager->persistAll();

        $personalWorkspace = $this->workspaceRepository->findOneByName($personalWorkspaceName);

        return $personalWorkspace instanceof Workspace ? $personalWorkspace : null;
    }

    /**
     * The name of the configured shared workspace, or NULL if none is configured.
     */
    private function resolveSharedWorkspaceName(): ?string
    {
        try {
            return $this->sharedWorkspaceContextFactory->getWorkspaceName();
        } catch (\RuntimeException) {
            return null;
        }
    }

    /**
     * A password nobody knows: the account of the agent is authenticated by a bearer token.
     */
    private function generateUnusablePassword(): string
    {
        return bin2hex(random_bytes(24));
    }
}
