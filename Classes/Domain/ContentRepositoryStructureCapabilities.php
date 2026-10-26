<?php

declare(strict_types=1);

namespace Sitegeist\SlopMachine\Domain;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Neos\ContentRepository\Domain\Model\Node;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Security\Context as SecurityContext;
use Neos\Neos\Domain\Service\ContentContext;

/**
 * Moving and renaming nodes.
 *
 * The write tool creates a node below a given parent, but it cannot move an
 * existing node. That is needed whenever a tree is copied into another
 * workspace: the copy arrives with the aggregate ids of the source and has to
 * be attached below the right parents.
 *
 * There is deliberately no rename tool. The name of a node is what its path is
 * built from, but it does not decide the URL - the uriPathSegment property
 * does, and the frontend routing matches that property. Node names are
 * deprecated in Neos 9, so nothing should depend on them.
 */
#[Flow\Proxy(false)]
class ContentRepositoryStructureCapabilities
{
    public function __construct(
        protected MCPContentContextFactory $contentContextFactory,
        protected SecurityContext $securityContext,
    ) {
    }

    /**
     * Moves a node below another node.
     */
    #[McpTool(
        name: 'MoveNode',
        description: 'Moves a node below another node, keeping its properties and its children.
            Use this when a page was created in the wrong place, for example a product page that should sit below
            its product area page but was created as a sibling of it. The node keeps its identity, so references and
            node:// links to it stay valid.',
    )]
    public function moveNode(
        #[Schema(
            type: SchemaLibrary::MOVE_NODE_SCHEMA['type'],
            description: SchemaLibrary::MOVE_NODE_SCHEMA['description'],
            properties: SchemaLibrary::MOVE_NODE_SCHEMA['properties'],
            required: SchemaLibrary::MOVE_NODE_SCHEMA['required'],
            additionalProperties: SchemaLibrary::MOVE_NODE_SCHEMA['additionalProperties'],
        )]
        array $command,
    ): array {
        $contentContext = $this->contentContextFactory->getWritingContentContext(
            $command['originDimensionSpacePoint']
        );
        $result = [];
        $this->securityContext->withoutAuthorizationChecks(
            function () use ($contentContext, $command, &$result): void {
                try {
                    $node = $this->requireNode($contentContext, $command['nodeAggregateId']);
                    $newParent = $this->requireNode($contentContext, $command['newParentNodeAggregateId']);

                    if ($node->getParentPath() === $newParent->getPath()) {
                        $result = [
                            'success' => true,
                            'message' => 'The node is already below the given parent.',
                            'nodeAggregateId' => $node->getIdentifier(),
                            'path' => $node->getPath(),
                        ];
                        return;
                    }

                    $node->moveInto($newParent);

                    $result = [
                        'success' => true,
                        'nodeAggregateId' => $node->getIdentifier(),
                        'path' => $node->getPath(),
                    ];
                } catch (\Throwable $exception) {
                    $result = [
                        'success' => false,
                        'message' => $exception->getMessage(),
                    ];
                }
            }
        );

        return $this->payload($result);
    }

    private function requireNode(ContentContext $contentContext, string $nodeAggregateId): Node
    {
        $node = $contentContext->getNodeByIdentifier($nodeAggregateId);
        if (!$node instanceof Node) {
            throw new \RuntimeException('No node found for nodeAggregateId ' . $nodeAggregateId . '.');
        }
        return $node;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function payload(array $payload): array
    {
        return [
            'content' => [
                'type' => 'text',
                'text' => \json_encode($payload),
            ],
            'structuredContent' => $payload,
        ];
    }
}
