<?php

declare(strict_types=1);

namespace Sitegeist\SlopMachine\Domain;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\ToolAnnotations;
use Neos\ContentRepository\Domain\Model\Node;
use Neos\Flow\Annotations as Flow;

/**
 * Removal access to the workspace the agent hands its work over to.
 *
 * The workspace is the shared review workspace from settings, or the topic workspace the agent
 * is working on - it can never be chosen by the agent in a tool argument
 * (@see SharedWorkspaceContextFactory).
 *
 * The hand-over itself is a publish from the agent's own workspace
 * (@see AgentWorkspaceCapabilities::publishOwnWorkspace()), exactly like an editor does it.
 */
#[Flow\Proxy(false)]
class SharedWorkspaceRemovalCapabilities
{
    public function __construct(
        protected SharedWorkspaceContextFactory $sharedWorkspaceContextFactory,
    ) {
    }

    /**
     * Removes one or more nodes, including all of their descendants, from the workspace the agent
     * hands over to.
     *
     * This is a permanent deletion in a workspace humans work in - it is not a soft removal that a
     * reviewer would see as a pending marker. Read the workspace first with
     * find-children-in-shared-workspace or find-descendants-in-shared-workspace and remove the
     * smallest possible node: removing a page removes everything below it.
     *
     * To undo your own unpublished edits in your own workspace, use DiscardChanges instead - that
     * restores content, it does not delete it from a shared workspace.
     *
     * @param string[] $nodeAggregateIds Node aggregate ids, without any prefix
     * @param array<string,string> $dimensionSpacePoint Dimension space point to remove in; a point this installation allows
     * @return array<string,mixed>
     */
    #[Schema(properties: McpSchemaLibrary::DIMENSION_SPACE_POINT_TOOL_SCHEMA['properties'])]
    #[McpTool(
        name: 'RemoveFromSharedWorkspace',
        description: 'Removes one or more nodes, including all of their descendants, from the workspace you hand over to -
            the shared review workspace, or the topic workspace you are working on.
            This permanently deletes content in a workspace that humans review, so check the workspace first with
            find-children-in-shared-workspace or find-descendants-in-shared-workspace and remove the smallest possible
            node - removing a page removes everything below it. If more than one node is affected, pass all
            nodeAggregateIds in a single call. To undo your own edits in your own workspace, use DiscardChanges instead.
            Returns one result entry per requested nodeAggregateId, in the requested order.',
        annotations: new ToolAnnotations(
            readOnlyHint: false,
            destructiveHint: true,
            idempotentHint: false,
            openWorldHint: false,
        )
    )]
    public function removeFromSharedWorkspace(
        #[Schema(
            type: 'array',
            description: 'The node aggregate ids to remove from the workspace you hand over to, without any prefix. Removing a node also removes its whole subtree.',
            items: McpSchemaLibrary::NODE_AGGREGATE_IDS['items'],
            minItems: 1,
        )]
        array $nodeAggregateIds,
        #[Schema(
            description: 'The dimension space point to write in. Pass a point this installation allows: call the dimensionspace tool to get the valid points, and use the empty object {} only if it reports that this installation has no content dimensions.',
        )]
        array $dimensionSpacePoint = [],
    ): array {
        $result = [];
        $workspaceName = $this->sharedWorkspaceContextFactory->getWorkspaceName();
        $workspace = $this->sharedWorkspaceContextFactory->getWorkspace();

        $contentContext = $this->sharedWorkspaceContextFactory
            ->getWritingContentContext($dimensionSpacePoint);

        foreach ($nodeAggregateIds as $nodeAggregateId) {
            try {
                $node = $contentContext->getNodeByIdentifier($nodeAggregateId);
                if (!$node instanceof Node) {
                    throw new \RuntimeException(
                        \sprintf(
                            'No node found for nodeAggregateId %s in the shared review workspace. Nodes you'
                            . ' created in your own workspace do not exist there.',
                            $nodeAggregateId
                        )
                    );
                }
                NodeRemoval::assertRemovable($node, $workspace);
                $path = $node->getPath();
                $nodeTypeName = $node->getNodeTypeName();
                $node->remove();
                $result[] = [
                    'nodeAggregateId' => $nodeAggregateId,
                    'path' => $path,
                    'nodeTypeName' => $nodeTypeName,
                    'success' => true,
                ];
            } catch (\Throwable $exception) {
                $result[] = [
                    'nodeAggregateId' => $nodeAggregateId,
                    'success' => false,
                    'message' => $exception->getMessage(),
                ];
            }
        }

        return [
            'workspace' => $workspaceName,
            'removedCount' => \count(\array_filter($result, static fn (array $entry): bool => $entry['success'])),
            'results' => $result,
        ];
    }
}
