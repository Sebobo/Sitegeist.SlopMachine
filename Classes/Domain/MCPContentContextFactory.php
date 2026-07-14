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
        $dimensions = [];
        foreach ($dimensionSpacePoint as $dimensionName => $dimensionValue) {
            $dimensions[$dimensionName] = $this->contentDimensionPresetSource->getAllPresets()[$dimensionName]['presets'][$dimensionValue]['values'];
        }
        /** @var ContentContext $contentContext */
        $contentContext = $this->contentContextFactory->create([
            'workspaceName' => $this->requireWorkspaceName(),
            'dimensions' => $dimensions,
            'targetDimensions' => $dimensionSpacePoint,
            'invisibleContentShown' => $invisibleContentShown,
        ]);

        return $contentContext;
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
