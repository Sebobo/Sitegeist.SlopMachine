<?php

declare(strict_types=1);

namespace Sitegeist\SlopMachine\Tests\Functional;

use Neos\ContentRepository\Domain\Model\NodeInterface;
use Sitegeist\Pandora\Infrastructure\CapabilityHandlerContainer;
use Sitegeist\SlopMachine\Domain\PreviewCapabilities;

/**
 * The preview of a document: the URL the agent fetches to see the rendered page.
 *
 * The URL names the workspace it renders - the workspace of the agent and the workspace it
 * hands its work over to. It never carries a credential: the agent sends its token with the
 * request, not in the URL.
 */
class PreviewCapabilitiesTest extends AgentTestCase
{
    public function testThePreviewUrlPointsAtTheWorkspaceOfTheAgent(): void
    {
        $result = $this->preview();

        self::assertTrue($result['success'] ?? false, 'The agent gets the preview of its own document.');
        self::assertStringContainsString(
            '@' . $this->agentWorkspace->getName(),
            (string)$result['contextPath'],
            'The preview shows the workspace of the agent.'
        );
        self::assertStringContainsString(
            urlencode('@' . $this->agentWorkspace->getName()),
            (string)$result['previewUrl'],
            'The URL of the preview names the workspace of the agent.'
        );
        self::assertStringContainsString('/neos/preview', (string)$result['previewUrl']);
    }

    public function testThePreviewOfTheReviewWorkspaceIsOffered(): void
    {
        $result = $this->preview();

        self::assertArrayHasKey('previewUrlInReviewWorkspace', $result);
        self::assertStringContainsString(
            urlencode('@' . $this->reviewWorkspace->getName()),
            (string)$result['previewUrlInReviewWorkspace'],
            'The preview shows the workspace the agent hands its work over to.'
        );
    }

    public function testThePreviewUrlCarriesNoCredential(): void
    {
        $result = $this->preview();

        self::assertStringNotContainsString(self::AGENT_ID, (string)$result['previewUrl']);
        self::assertStringNotContainsString(self::AGENT_ID, (string)$result['previewUrlInReviewWorkspace']);
        self::assertStringNotContainsString('Bearer', (string)$result['previewUrl']);
    }

    public function testThePreviewOfADocumentOfAnotherWorkspaceIsNotOffered(): void
    {
        // The document is in the personal workspace of the other user, so the agent cannot
        // resolve it: the tool looks the node up in the workspace of the agent.
        self::assertNotNull($this->otherUserDocumentId);

        $result = $this->preview($this->otherUserDocumentId);

        self::assertFalse($result['success'] ?? true, 'The agent does not get a preview of a document it cannot see.');
        self::assertArrayNotHasKey('previewUrl', $result);
    }

    /**
     * The preview of a document of the agent.
     *
     * @return array<string,mixed>
     */
    protected function preview(?string $nodeAggregateId = null): array
    {
        $document = $this->documentIn($this->agentWorkspace);
        self::assertInstanceOf(NodeInterface::class, $document);

        $capabilities = $this->getObject(CapabilityHandlerContainer::class)
            ->get(PreviewCapabilities::class);
        self::assertInstanceOf(PreviewCapabilities::class, $capabilities);

        return $capabilities->previewDocument(
            $nodeAggregateId ?? $document->getIdentifier(),
            $this->dimensionSpacePoint()
        );
    }
}
