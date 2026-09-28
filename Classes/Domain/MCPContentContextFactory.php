<?php

declare(strict_types=1);

namespace Sitegeist\SlopMachine\Domain;

use Neos\ContentRepository\Domain\Service\ContentDimensionPresetSourceInterface;
use Neos\Flow\Annotations as Flow;
use Neos\Neos\Domain\Service\ContentContext;
use Neos\Neos\Domain\Service\ContentContextFactory;
use Neos\Neos\Domain\Service\UserService;
use Neos\Neos\Utility\User as UserUtility;
use Sitegeist\SlopMachine\Domain\Repository\AgentAssignmentRepository;

#[Flow\Scope('singleton')]
class MCPContentContextFactory
{
    public function __construct(
        protected ContentContextFactory $contentContextFactory,
        protected ContentDimensionPresetSourceInterface $contentDimensionPresetSource,
        protected AgentAssignmentRepository $agentAssignmentRepository,
        protected UserService $userService,
    ) {
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
        $dimensions = $this->resolveDimensions($dimensionSpacePoint);

        /** @var ContentContext $contentContext */
        $contentContext = $this->contentContextFactory->create([
            'workspaceName' => $this->requireWorkspaceName(),
            'dimensions' => $dimensions,
            'targetDimensions' => $dimensionSpacePoint,
            'invisibleContentShown' => $invisibleContentShown,
        ]);

        return $contentContext;
    }

    /**
     * Resolves the dimension space point against the presets this installation actually defines.
     *
     * The point is resolved up front instead of being indexed blindly, so a caller that asks for a
     * dimension or a dimension value this installation does not have gets told what is available
     * rather than a low level failure. As soon as an installation configures content dimensions,
     * an empty point stops being a neutral "no dimensions" and would quietly resolve to the first
     * variant of every node instead of the default preset, so a point that does not cover all
     * configured dimensions is rejected as well. The dimension space points that are valid are
     * exactly the ones the dimensionspace tool reports.
     *
     * @param array<string,string> $dimensionSpacePoint
     * @return array<string,array<int,string>>
     */
    private function resolveDimensions(array $dimensionSpacePoint): array
    {
        $presets = $this->contentDimensionPresetSource->getAllPresets();
        $dimensions = [];

        foreach ($dimensionSpacePoint as $dimensionName => $dimensionValue) {
            if (!isset($presets[$dimensionName])) {
                throw new \RuntimeException(\sprintf(
                    'This installation has no content dimension "%s". %s Call the dimensionspace tool to get the'
                    . ' dimension space points that can be used.',
                    $dimensionName,
                    $presets === []
                        ? 'It defines no content dimensions at all, so the only valid dimension space point is an'
                        . ' empty object ({}).'
                        : 'The dimensions it defines are: ' . \implode(', ', \array_keys($presets)) . '.'
                ), 1421329001);
            }
            if (!\is_string($dimensionValue) || !isset($presets[$dimensionName]['presets'][$dimensionValue])) {
                throw new \RuntimeException(\sprintf(
                    'The content dimension "%s" has no value "%s". The values it allows are: %s.'
                    . ' Call the dimensionspace tool to get the dimension space points that can be used.',
                    $dimensionName,
                    \is_string($dimensionValue) ? $dimensionValue : \gettype($dimensionValue),
                    \implode(', ', \array_keys($presets[$dimensionName]['presets'] ?? []))
                ), 1421329002);
            }
            $dimensions[$dimensionName] = $presets[$dimensionName]['presets'][$dimensionValue]['values'];
        }

        $missingDimensions = \array_diff(\array_keys($presets), \array_keys($dimensionSpacePoint));
        if ($missingDimensions !== []) {
            throw new \RuntimeException(\sprintf(
                'The dimension space point is incomplete, it does not cover %s.'
                . ' Call the dimensionspace tool to get the dimension space points that can be used.',
                \implode(', ', $missingDimensions)
            ), 1421329003);
        }

        return $dimensions;
    }

    private function requireWorkspaceName(): string
    {
        $agentAssignment = $this->agentAssignmentRepository->findOneByCurrentAccount();
        if (!$agentAssignment) {
            throw new \RuntimeException('Agent is not assigned to a user');
        }

        $username = $this->userService->getUsername($agentAssignment->user);

        return $username === null
            ? throw new \RuntimeException('Failed to resolve user name for assigned user')
            : UserUtility::getPersonalWorkspaceNameForUsername($username);
    }
}
