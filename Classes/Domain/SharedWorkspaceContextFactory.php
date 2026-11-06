<?php

declare(strict_types=1);

namespace Sitegeist\SlopMachine\Domain;

use Neos\ContentRepository\Domain\Model\Workspace;
use Neos\ContentRepository\Domain\Repository\WorkspaceRepository;
use Neos\Flow\Annotations as Flow;
use Neos\Neos\Domain\Service\ContentContext;
use Neos\Neos\Domain\Service\ContentContextFactory;

/**
 * Builds content contexts for the workspace the agent is allowed to hand work to.
 *
 * That workspace is the shared review workspace as long as the agent does not work on a topic.
 * As soon as it rebases its own workspace onto a topic workspace, the topic workspace takes over:
 * the agent reviews against it and hands its work over to it, so that two agents working on
 * different topics do not write over each other in the shared review workspace.
 *
 * The topic workspace is never taken from a tool argument - it is the workspace the agent's own
 * workspace is based on, and the only thing that can change that is the rebase capability. The
 * guards below are the second line of defence behind that: they refuse the live workspace, the
 * agent's own personal workspace, and any workspace that is not part of the live base chain.
 *
 * This class is a regular (proxied) Flow singleton rather than a #[Flow\Proxy(false)]
 * capability, because settings and other scalars cannot be injected into non-proxied classes.
 * @see \Sitegeist\Pandora\Infrastructure\CapabilityHandlerContainer
 */
#[Flow\Scope('singleton')]
class SharedWorkspaceContextFactory
{
    public const LIVE_WORKSPACE = 'live';

    /**
     * @param array<string,mixed> $agentSettings Sitegeist:SlopMachine:agent
     */
    public function __construct(
        protected ContentContextFactory $contentContextFactory,
        protected DimensionSpacePointResolver $dimensionSpacePointResolver,
        protected WorkspaceRepository $workspaceRepository,
        protected AgentWorkspaceResolver $agentWorkspaceResolver,
        protected array $agentSettings,
    ) {
    }

    /**
     * The name of the shared review workspace, as configured.
     *
     * It is the root of the review chain: every topic workspace is based on it, and it is the
     * workspace the agent hands its work over to while it does not work on a topic.
     */
    public function getSharedWorkspaceName(): string
    {
        $workspaceName = $this->agentSettings['sharedWorkspace'] ?? null;
        if (!\is_string($workspaceName) || $workspaceName === '') {
            throw new \RuntimeException(
                'No shared workspace is configured. Set Sitegeist:SlopMachine:agent:sharedWorkspace to the name'
                . ' of the workspace the agent may hand its work over to.',
                1758250001
            );
        }

        return $workspaceName;
    }

    /**
     * The name of the workspace the agent reviews against and hands its work over to.
     *
     * That is the topic workspace while the agent works on one, and the shared review workspace
     * otherwise.
     */
    public function getWorkspaceName(): string
    {
        return $this->getTopicWorkspace()?->getName() ?? $this->getSharedWorkspaceName();
    }

    /**
     * The shared review workspace itself, as configured.
     */
    public function getSharedWorkspace(): Workspace
    {
        $workspaceName = $this->getSharedWorkspaceName();
        $workspace = $this->workspaceRepository->findOneByName($workspaceName);
        if (!$workspace instanceof Workspace) {
            throw new \RuntimeException(
                \sprintf('The configured shared workspace "%s" does not exist.', $workspaceName),
                1758250002
            );
        }
        $this->assertUsable($workspace);

        return $workspace;
    }

    public function getWorkspace(): Workspace
    {
        $topicWorkspace = $this->getTopicWorkspace();
        if ($topicWorkspace instanceof Workspace) {
            $this->assertUsable($topicWorkspace);

            return $topicWorkspace;
        }

        return $this->getSharedWorkspace();
    }

    /**
     * The topic workspace the agent currently works on, or NULL if it does not work on one.
     *
     * A topic workspace is a direct child of the shared review workspace: the agent creates one
     * for a topic, rebases its own workspace onto it, and from then on that is the workspace it
     * reviews against and hands its work over to.
     */
    public function getTopicWorkspace(): ?Workspace
    {
        try {
            $personalWorkspace = $this->agentWorkspaceResolver->getWorkspace();
            $sharedWorkspaceName = $this->getSharedWorkspaceName();
        } catch (\Throwable) {
            return null;
        }

        $baseWorkspace = $personalWorkspace->getBaseWorkspace();
        if (!$baseWorkspace instanceof Workspace) {
            return null;
        }

        if ($baseWorkspace->getName() === $sharedWorkspaceName) {
            return null;
        }

        if ($baseWorkspace->getBaseWorkspace()?->getName() !== $sharedWorkspaceName) {
            return null;
        }

        return $baseWorkspace;
    }

    /**
     * Whether the given workspace is a topic workspace of the shared review workspace.
     */
    public function isTopicWorkspace(Workspace $workspace): bool
    {
        return $workspace->getBaseWorkspace()?->getName() === $this->getSharedWorkspaceName();
    }

    /**
     * @param array<string,string> $dimensionSpacePoint
     */
    public function getReadingContentContext(array $dimensionSpacePoint): ContentContext
    {
        return $this->getContentContext($dimensionSpacePoint, false);
    }

    /**
     * @param array<string,string> $originDimensionSpacePoint
     */
    public function getWritingContentContext(array $originDimensionSpacePoint): ContentContext
    {
        return $this->getContentContext($originDimensionSpacePoint, true);
    }

    /**
     * @param array<string,string> $dimensionSpacePoint
     */
    private function getContentContext(array $dimensionSpacePoint, bool $invisibleContentShown): ContentContext
    {
        $workspace = $this->getWorkspace();

        $dimensions = $this->dimensionSpacePointResolver->resolve($dimensionSpacePoint);

        /** @var ContentContext $contentContext */
        $contentContext = $this->contentContextFactory->create([
            'workspaceName' => $workspace->getName(),
            'dimensions' => $dimensions,
            'targetDimensions' => $dimensionSpacePoint,
            'invisibleContentShown' => $invisibleContentShown,
        ]);

        return $contentContext;
    }

    private function assertUsable(Workspace $workspace): void
    {
        if ($workspace->getName() === self::LIVE_WORKSPACE) {
            throw new \RuntimeException(
                'Refusing to hand out a content context for "live". The agent must never write to the live'
                . ' workspace; humans publish the shared workspace.',
                1758250003
            );
        }

        if ($workspace->getName() === $this->agentWorkspaceResolver->getWorkspaceName()) {
            throw new \RuntimeException(
                \sprintf(
                    'The workspace "%s" is the agent\'s own personal workspace.',
                    $workspace->getName()
                ),
                1758250004
            );
        }

        $baseWorkspaceNames = \array_map(
            static fn (Workspace $baseWorkspace): string => $baseWorkspace->getName(),
            $workspace->getBaseWorkspaces()
        );
        if (!\in_array(self::LIVE_WORKSPACE, $baseWorkspaceNames, true)) {
            throw new \RuntimeException(
                \sprintf(
                    'The workspace "%s" is not based on "%s", so content written there could'
                    . ' never be reviewed against the live site.',
                    $workspace->getName(),
                    self::LIVE_WORKSPACE
                ),
                1758250005
            );
        }
    }
}
