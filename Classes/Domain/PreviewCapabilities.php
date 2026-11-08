<?php

declare(strict_types=1);

namespace Sitegeist\SlopMachine\Domain;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\ToolAnnotations;
use Neos\ContentRepository\Domain\Model\Node;
use Neos\ContentRepository\Domain\Utility\NodePaths;
use Neos\Flow\Annotations as Flow;

/**
 * The preview of a document: the rendered page, not the content of the document.
 *
 * The preview is a frontend request, and it is answered with the page of the workspace the
 * context path names - so a document of the own workspace is previewed with the changes of the
 * agent, and a document of the workspace it hands its work over to is previewed with the state a
 * reviewer sees.
 *
 * The request has to be authenticated: the agent sends its token, the same one it uses for the
 * MCP connection. Without it the frontend answers with the login page instead of the page.
 */
#[Flow\Proxy(false)]
class PreviewCapabilities
{
    public function __construct(
        protected MCPContentContextFactory $contentContextFactory,
        protected AgentWorkspaceResolver $agentWorkspaceResolver,
        protected PreviewUriBuilder $previewUriBuilder,
    ) {
    }

    /**
     * The URL of the preview of a document - the page as it is rendered, not the content of the
     * document.
     *
     * The preview of the own workspace shows the changes of the agent, the preview of the
     * workspace the agent hands its work over to shows the state a reviewer sees.
     *
     * The request for the URL has to carry the token of the agent: the frontend renders the
     * page of the workspace for an authenticated backend user, and answers with the login page
     * for everybody else.
     *
     * @param string|array<string,string> $dimensionSpacePoint
     * @return array<string,mixed>
     */
    #[McpTool(
        name: 'PreviewDocument',
        description: 'The URL of the preview of a document - the page as it is rendered, not the content of the document.
            The preview of the own workspace shows the changes of the agent, the preview of the workspace the agent hands
            its work over to shows the state a reviewer sees.
            Fetch the URL with the same token you use for this connection, otherwise the frontend answers with the login
            page instead of the page: curl -H "Authorization: Bearer <your token>" "<the url>".
            This tool does not fetch anything itself, it only tells you the URL.',
        annotations: new ToolAnnotations(
            readOnlyHint: true,
            destructiveHint: false,
            idempotentHint: true,
            openWorldHint: false,
        )
    )]
    #[Schema(properties: McpSchemaLibrary::DIMENSION_SPACE_POINT_TOOL_SCHEMA['properties'])]
    public function previewDocument(
        #[Schema(
            type: 'string',
            description: 'The node aggregate id of the document to preview.',
        )]
        string $nodeAggregateId,
        string|array $dimensionSpacePoint = [],
    ): array {
        try {
            $contentContext = $this->contentContextFactory->getReadingContentContext(
                MCPContentContextFactory::decodeDimensionSpacePoint($dimensionSpacePoint)
            );

            $node = $contentContext->getNodeByIdentifier($nodeAggregateId);
            if (!$node instanceof Node) {
                throw new \RuntimeException(
                    \sprintf('No node found for nodeAggregateId %s.', $nodeAggregateId)
                );
            }

            $previewUrl = $this->previewUriBuilder->uriFor($node->getPath(), $node->getContextPath());
            if ($previewUrl === null) {
                throw new \RuntimeException(
                    'The site of the document has no domain it could be reached on, so it cannot be previewed.'
                );
            }

            $result = [
                'success' => true,
                'previewUrl' => $previewUrl,
                'workspace' => $contentContext->getWorkspaceName(),
                'contextPath' => $node->getContextPath(),
                'nodeAggregateId' => $node->getIdentifier(),
                'nodeTypeName' => $node->getNodeTypeName(),
            ];

            $reviewWorkspaceUrl = $this->previewUrlInReviewWorkspace($node, $contentContext->getDimensions());
            if ($reviewWorkspaceUrl !== null) {
                $result['previewUrlInReviewWorkspace'] = $reviewWorkspaceUrl;
            }

            return $result;
        } catch (\Throwable $throwable) {
            return [
                'success' => false,
                'message' => $throwable->getMessage(),
            ];
        }
    }

    /**
     * The preview of the document in the workspace the agent hands its work over to - the
     * shared review workspace, or the topic workspace while the agent works on a topic.
     *
     * Null when there is no such workspace.
     *
     * @param array<string,list<string>> $dimensions
     */
    private function previewUrlInReviewWorkspace(Node $node, array $dimensions): ?string
    {
        $baseWorkspace = $this->agentWorkspaceResolver->getWorkspace()->getBaseWorkspace();
        if ($baseWorkspace === null) {
            return null;
        }

        $contextPath = NodePaths::generateContextPath($node->getPath(), $baseWorkspace->getName(), $dimensions);

        return $this->previewUriBuilder->uriFor($node->getPath(), $contextPath);
    }
}
