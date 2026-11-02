<?php

declare(strict_types=1);

namespace Sitegeist\SlopMachine\Domain;

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
        protected DimensionSpacePointResolver $dimensionSpacePointResolver,
        protected AgentAssignmentRepository $agentAssignmentRepository,
        protected UserService $userService,
    ) {
    }


    /**
     * Decodes a dimension space point that may arrive as either an object (the shape the
     * factory expects internally) or a URL-encoded JSON string (the form read and site tools
     * historically accepted). An empty object {} arrives as an empty PHP array after JSON
     * decoding, so callers do not have to distinguish empty-object from empty-array input.
     *
     * @param string|array<string,string> $dimensionSpacePoint
     * @return array<string,string>
     */
    public static function decodeDimensionSpacePoint(string|array $dimensionSpacePoint): array
    {
        if (\is_string($dimensionSpacePoint)) {
            $decoded = \json_decode(\urldecode($dimensionSpacePoint), true, 512, JSON_THROW_ON_ERROR);
            return \is_array($decoded) ? $decoded : [];
        }
        return $dimensionSpacePoint;
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
        $dimensions = $this->dimensionSpacePointResolver->resolve($dimensionSpacePoint);

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
