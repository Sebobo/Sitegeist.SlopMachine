<?php

declare(strict_types=1);

namespace Sitegeist\SlopMachine\Domain;

use Neos\ContentRepository\Domain\Model\Workspace;
use Neos\ContentRepository\Domain\Repository\WorkspaceRepository;
use Neos\ContentRepository\Domain\Service\ContentDimensionPresetSourceInterface;
use Neos\Flow\Annotations as Flow;
use Neos\Neos\Domain\Service\ContentContext;
use Neos\Neos\Domain\Service\ContentContextFactory;

/**
 * Builds content contexts for the shared review workspace the agent is allowed to hand work to.
 *
 * The workspace name comes from settings only and is never taken from a tool argument, so no
 * capability can be talked into addressing an arbitrary workspace. The guards below are the
 * second line of defence behind that: they refuse the live workspace, the agent's own personal
 * workspace, and any workspace that is not part of the live base chain.
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
        protected ContentDimensionPresetSourceInterface $contentDimensionPresetSource,
        protected WorkspaceRepository $workspaceRepository,
        protected AgentWorkspaceResolver $agentWorkspaceResolver,
        protected array $agentSettings,
    ) {
    }

    public function getWorkspaceName(): string
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

    public function getWorkspace(): Workspace
    {
        $workspaceName = $this->getWorkspaceName();
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

        $dimensions = [];
        foreach ($dimensionSpacePoint as $dimensionName => $dimensionValue) {
            $dimensions[$dimensionName] = $this->contentDimensionPresetSource
                ->getAllPresets()[$dimensionName]['presets'][$dimensionValue]['values'];
        }

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
                    'The configured shared workspace "%s" is the agent\'s own personal workspace.',
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
                    'The configured shared workspace "%s" is not based on "%s", so content written there could'
                    . ' never be reviewed against the live site.',
                    $workspace->getName(),
                    self::LIVE_WORKSPACE
                ),
                1758250005
            );
        }
    }
}
