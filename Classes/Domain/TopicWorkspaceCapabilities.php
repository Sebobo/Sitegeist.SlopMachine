<?php

declare(strict_types=1);

namespace Sitegeist\SlopMachine\Domain;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\ToolAnnotations;
use Neos\ContentRepository\Domain\Model\Workspace;
use Neos\ContentRepository\Domain\Repository\WorkspaceRepository;
use Neos\ContentRepository\Domain\Service\PublishingServiceInterface;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Security\Context as SecurityContext;

/**
 * Topic workspaces: one workspace per topic an agent works on, and the rebase of the agent's own
 * workspace onto it.
 *
 * The problem they solve: every agent hands its work over to the same shared review workspace, so
 * two agents working on different topics write over each other there. A topic workspace is a
 * branch of the shared review workspace - the agent works in it, a human reviews it and publishes
 * it into the shared review workspace when it is ready.
 *
 * The workspace the agent reviews against and hands its work over to follows the base of its own
 * workspace, which is what SharedWorkspaceContextFactory resolves. The agent can therefore only
 * ever address the shared review workspace or one of its topic workspaces - the rebase refuses
 * every other workspace.
 */
#[Flow\Proxy(false)]
class TopicWorkspaceCapabilities
{
    public function __construct(
        protected TopicWorkspaceService $topicWorkspaceService,
        protected SharedWorkspaceContextFactory $sharedWorkspaceContextFactory,
        protected AgentWorkspaceResolver $agentWorkspaceResolver,
        protected WorkspaceRepository $workspaceRepository,
        protected PublishingServiceInterface $publishingService,
        protected SecurityContext $securityContext,
    ) {
    }

    /**
     * Creates the workspace for a topic, based on the shared review workspace.
     *
     * Use it when you work on a topic that deserves its own branch: two agents - or an agent and
     * a human - working on different topics then do not write over each other in the shared review
     * workspace. The topic workspace is what a human reviews afterwards: they publish it into the
     * shared review workspace when they are happy with it.
     *
     * The name of the workspace is derived from the topic, so the same topic always maps to the
     * same workspace: calling it again for a topic that has a workspace already returns that
     * workspace instead of creating a second one.
     *
     * @param string $topic The topic the workspace is for, for example "Zahnzusatzversicherung"
     * @param string|null $title The title of the workspace, the topic by default
     * @return array<string,mixed>
     */
    #[McpTool(
        name: 'CreateTopicWorkspace',
        description: 'Creates the workspace for a topic, based on the shared review workspace.
            Use it when you work on a topic that deserves its own branch: two agents - or an agent and a human -
            working on different topics then do not write over each other in the shared review workspace.
            The topic workspace is what a human reviews afterwards: they publish it into the shared review workspace
            when they are happy with it.
            The name of the workspace is derived from the topic, so the same topic always maps to the same workspace:
            calling it again for a topic that has a workspace already returns that workspace instead of creating a
            second one.
            The workspace is not active after this call - call RebaseOwnWorkspace with its name to work on the topic.
            From then on your publish (PublishOwnWorkspace) and the shared workspace read tools address the
            topic workspace instead of the shared review workspace.',
        annotations: new ToolAnnotations(
            readOnlyHint: false,
            destructiveHint: false,
            idempotentHint: true,
            openWorldHint: false,
        )
    )]
    public function createTopicWorkspace(
        #[Schema(
            type: 'string',
            description: 'The topic the workspace is for, for example "Zahnzusatzversicherung". The same topic always maps to the same workspace.',
        )]
        string $topic,
        #[Schema(
            type: 'string',
            description: 'The title of the workspace, the topic by default. A human sees this title in the workspace list.',
        )]
        ?string $title = null,
    ): array {
        $result = [];
        $this->securityContext->withoutAuthorizationChecks(
            function () use ($topic, $title, &$result) {
                try {
                    $outcome = $this->topicWorkspaceService->createWorkspace($topic, $title);
                    $workspace = $outcome['workspace'];

                    $result = [
                        'success' => true,
                        'created' => $outcome['created'],
                        'topic' => $topic,
                        'workspace' => $workspace->getName(),
                        'title' => $workspace->getTitle(),
                        'baseWorkspace' => $workspace->getBaseWorkspace()?->getName(),
                        'message' => $outcome['created']
                            ? \sprintf(
                                'Created the topic workspace "%s". Call RebaseOwnWorkspace with this name to work on the topic.',
                                $workspace->getName()
                            )
                            : \sprintf(
                                'The topic workspace "%s" exists already. Call RebaseOwnWorkspace with this name to work'
                                . ' on the topic.',
                                $workspace->getName()
                            ),
                    ];
                } catch (\Throwable $exception) {
                    $result = [
                        'success' => false,
                        'message' => $exception->getMessage(),
                    ];
                }
            }
        );

        return $result;
    }

    /**
     * Rebases your own workspace onto another workspace.
     *
     * Pass the name of a topic workspace - as returned by CreateTopicWorkspace - to work on that
     * topic, or the name of the shared review workspace to go back to it.
     *
     * Rebasing changes what your workspace is based on. Your own unpublished changes stay in your
     * workspace, but they are relative to the new base from now on, and content that only existed
     * in the previous base is no longer part of your view. Rebase before you start working on the
     * topic, not in the middle of it.
     *
     * The workspace you hand over to follows: while your workspace is based on a topic workspace,
     * PublishOwnWorkspace and the shared workspace read tools address that topic
     * workspace.
     *
     * @param string $workspace The name of the workspace to base your own workspace on
     * @return array<string,mixed>
     */
    #[McpTool(
        name: 'RebaseOwnWorkspace',
        description: 'Rebases your own workspace onto another workspace.
            Pass the name of a topic workspace - as returned by CreateTopicWorkspace - to work on that topic, or the
            name of the shared review workspace to go back to it. No other workspace is accepted: your own workspace can
            only be based on the shared review workspace or on one of its topic workspaces.
            Rebasing changes what your workspace is based on. Your own unpublished changes stay in your workspace, but
            they are relative to the new base from now on, and content that only existed in the previous base is no
            longer part of your view. Rebase before you start working on the topic, not in the middle of it.
            The workspace you hand over to follows: while your workspace is based on a topic workspace,
            PublishOwnWorkspace and the shared workspace read tools address that topic workspace.',
        annotations: new ToolAnnotations(
            readOnlyHint: false,
            destructiveHint: false,
            idempotentHint: true,
            openWorldHint: false,
        )
    )]
    public function rebaseOwnWorkspace(
        #[Schema(
            type: 'string',
            description: 'The name of the workspace to base your own workspace on: the name of a topic workspace (as returned by CreateTopicWorkspace) or the name of the shared review workspace.',
        )]
        string $workspace,
    ): array {
        $result = [];
        $this->securityContext->withoutAuthorizationChecks(
            function () use ($workspace, &$result) {
                try {
                    $result = $this->rebase($workspace);
                } catch (\Throwable $exception) {
                    $result = [
                        'success' => false,
                        'message' => $exception->getMessage(),
                    ];
                }
            }
        );

        return $result;
    }

    /**
     * Rebases the personal workspace of the agent onto the given workspace.
     *
     * @return array<string,mixed>
     */
    private function rebase(string $workspaceName): array
    {
        $personalWorkspace = $this->agentWorkspaceResolver->getWorkspace();

        $targetWorkspace = $this->workspaceRepository->findOneByName($workspaceName);
        if (!$targetWorkspace instanceof Workspace) {
            throw new \RuntimeException(\sprintf('There is no workspace named "%s".', $workspaceName));
        }

        if ($targetWorkspace->getName() === $personalWorkspace->getName()) {
            throw new \RuntimeException('Your own workspace cannot be based on itself.');
        }

        if ($targetWorkspace->getName() === SharedWorkspaceContextFactory::LIVE_WORKSPACE) {
            throw new \RuntimeException(
                'Your own workspace cannot be based on "live": the agent never works on the live workspace, a human'
                . ' publishes it.'
            );
        }

        $sharedWorkspaceName = $this->sharedWorkspaceContextFactory->getSharedWorkspaceName();
        if ($targetWorkspace->getName() !== $sharedWorkspaceName
            && !$this->sharedWorkspaceContextFactory->isTopicWorkspace($targetWorkspace)) {
            throw new \RuntimeException(\sprintf(
                'Your own workspace can only be based on the shared review workspace "%s" or on one of its topic'
                . ' workspaces - "%s" is neither.',
                $sharedWorkspaceName,
                $targetWorkspace->getName()
            ));
        }

        $previousBaseWorkspace = $personalWorkspace->getBaseWorkspace();
        if ($previousBaseWorkspace instanceof Workspace
            && $previousBaseWorkspace->getName() === $targetWorkspace->getName()) {
            return [
                'success' => true,
                'changed' => false,
                'workspace' => $personalWorkspace->getName(),
                'previousBaseWorkspace' => $previousBaseWorkspace->getName(),
                'baseWorkspace' => $targetWorkspace->getName(),
                'reviewWorkspace' => $this->sharedWorkspaceContextFactory->getWorkspaceName(),
                'message' => \sprintf('Your workspace is already based on "%s".', $targetWorkspace->getName()),
            ];
        }

        $unpublishedChanges = $this->publishingService->getUnpublishedNodesCount($personalWorkspace);

        $personalWorkspace->setBaseWorkspace($targetWorkspace);
        $this->workspaceRepository->update($personalWorkspace);

        return [
            'success' => true,
            'changed' => true,
            'workspace' => $personalWorkspace->getName(),
            'previousBaseWorkspace' => $previousBaseWorkspace?->getName(),
            'baseWorkspace' => $targetWorkspace->getName(),
            'reviewWorkspace' => $this->sharedWorkspaceContextFactory->getWorkspaceName(),
            'unpublishedChanges' => $unpublishedChanges,
            'message' => \sprintf(
                'Your workspace "%s" is now based on "%s". You hand your work over to "%s" from now on.',
                $personalWorkspace->getName(),
                $targetWorkspace->getName(),
                $this->sharedWorkspaceContextFactory->getWorkspaceName()
            ),
        ];
    }
}
