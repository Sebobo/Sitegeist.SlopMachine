<?php

declare(strict_types=1);

namespace Sitegeist\SlopMachine\Domain;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\ToolAnnotations;
use Neos\ContentRepository\Domain\Model\Node;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Model\NodeData;
use Neos\ContentRepository\Domain\Model\Workspace;
use Neos\ContentRepository\Domain\Repository\NodeDataRepository;
use Neos\ContentRepository\Domain\Service\PublishingServiceInterface;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Security\Context as SecurityContext;

/**
 * Removal and change management for the agent's own personal workspace.
 *
 * Every context comes from Sitegeist.SlopMachine's MCPContentContextFactory, which always
 * resolves the workspace of the Neos user the agent is assigned to. No argument can select a
 * workspace, so these tools cannot address the live workspace or any other workspace.
 *
 * Tool results are returned as the payload array itself: the MCP SDK turns that into a single
 * text content block and uses the very same array as structuredContent, which is the shape
 * clients and models consume best.
 */
#[Flow\Proxy(false)]
class AgentWorkspaceCapabilities
{
    public function __construct(
        protected MCPContentContextFactory $contentContextFactory,
        protected AgentWorkspaceResolver $agentWorkspaceResolver,
        protected PublishingServiceInterface $publishingService,
        protected NodeDataRepository $nodeDataRepository,
        protected SecurityContext $securityContext,
    ) {
    }

    /**
     * Removes one or more nodes - including their whole subtree - from your own workspace.
     *
     * This is a permanent deletion in your own workspace: it is not a removal that a human would
     * see as a pending "remove" marker, the nodes are gone. If you only want to get rid of your
     * own edits and keep the content as it was in the base workspace, use DiscardChanges
     * instead - that reverts instead of deleting.
     *
     * Node types of tethered children do not have to be passed, removing a parent removes them.
     *
     * @param string[] $nodeAggregateIds Node aggregate ids, without any prefix
     * @param array<string,string> $dimensionSpacePoint Dimension space point to remove in; a point this installation allows
     * @return array<string,mixed>
     */
    #[Schema(properties: McpSchemaLibrary::DIMENSION_SPACE_POINT_TOOL_SCHEMA['properties'])]
    #[McpTool(
        name: 'RemoveNode',
        description: 'Removes one or more nodes, including all of their descendants, from your own workspace.
            This deletes content - it is not a soft removal that a reviewer would see as pending.
            Use it only for content you created yourself that must not end up in the review workspace.
            To undo your own edits and keep the content as it was in the base workspace, use DiscardChanges instead.
            If more than one node is affected, pass all nodeAggregateIds in a single call.
            Returns one result entry per requested nodeAggregateId, in the requested order.',
        annotations: new ToolAnnotations(
            readOnlyHint: false,
            destructiveHint: true,
            idempotentHint: false,
            openWorldHint: false,
        )
    )]
    public function removeNode(
        #[Schema(
            type: McpSchemaLibrary::NODE_AGGREGATE_IDS['type'],
            description: 'The node aggregate ids to remove, without any prefix. Removing a node also removes its whole subtree.',
            items: McpSchemaLibrary::NODE_AGGREGATE_IDS['items'],
            minItems: McpSchemaLibrary::NODE_AGGREGATE_IDS['minItems'],
        )]
        array $nodeAggregateIds,
        #[Schema(
            description: 'The dimension space point to write in. Pass a point this installation allows: call the dimensionspace tool to get the valid points, and use the empty object {} only if it reports that this installation has no content dimensions. Both the object form and the URL-encoded JSON string form are accepted.',
        )]
        string|array $dimensionSpacePoint = [],
    ): array {
        $contentContext = $this->contentContextFactory->getWritingContentContext(MCPContentContextFactory::decodeDimensionSpacePoint($dimensionSpacePoint));
        $workspace = $this->agentWorkspaceResolver->getWorkspace();
        $result = [];

        $this->securityContext->withoutAuthorizationChecks(
            function () use ($contentContext, $workspace, $nodeAggregateIds, &$result) {
                foreach ($nodeAggregateIds as $nodeAggregateId) {
                    try {
                        $node = $contentContext->getNodeByIdentifier($nodeAggregateId);
                        if (!$node instanceof Node) {
                            throw new \RuntimeException(
                                \sprintf('No node found for nodeAggregateId %s.', $nodeAggregateId)
                            );
                        }
                        NodeRemoval::assertRemovable($node, $workspace);
                        $path = $node->getPath();
                        $node->remove();
                        $result[] = [
                            'nodeAggregateId' => $nodeAggregateId,
                            'path' => $path,
                            'nodeTypeName' => $node->getNodeTypeName(),
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
            }
        );

        return [
            'workspace' => $this->agentWorkspaceResolver->getWorkspaceName(),
            'removedCount' => \count(\array_filter($result, static fn (array $entry): bool => $entry['success'])),
            'results' => $result,
        ];
    }

    /**
     * Lists every unpublished change in your own workspace, i.e. every difference between your
     * workspace and its base workspace.
     *
     * Because your workspace is based on the shared review workspace, this is also the exact list
     * of what you would hand over if the work were published - it is how you check your work
     * before moving it, and how you decide what to revert.
     *
     * Changes are returned in publishing order (parents before children). Each entry is labelled
     * with the change type: added, removed, moved or modified. "removed" and "moved" refer to the
     * node as it exists in your workspace, not to a deletion of the row.
     *
     * Use DiscardChanges to revert entries, and RemoveNode for content you created yourself.
     *
     * @param int $limit Upper bound on the number of returned changes
     * @return array<string,mixed>
     */
    #[McpTool(
        name: 'ListUnpublishedChanges',
        description: 'Lists every unpublished change in your own workspace, that is every difference between your
            workspace and the workspace it is based on, labelled as added, removed, moved or modified.
            Use it to review what you have changed before handing work over, and to decide which changes to
            revert with DiscardChanges. Changes are returned in publishing order, parents before children.
            The report covers the whole workspace and takes no dimension space point, because it describes
            workspace state rather than a rendered page.
            This tool is read-only and does not modify anything.',
        annotations: new ToolAnnotations(
            readOnlyHint: true,
            destructiveHint: false,
            idempotentHint: true,
            openWorldHint: false,
        )
    )]
    public function listUnpublishedChanges(
        #[Schema(type: 'integer', description: 'Upper bound on the number of returned changes.', minimum: 1)]
        int $limit = 200,
    ): array {
        $workspace = $this->agentWorkspaceResolver->getWorkspace();
        $baseWorkspace = $workspace->getBaseWorkspace();
        $changes = [];

        $this->securityContext->withoutAuthorizationChecks(
            function () use ($workspace, $baseWorkspace, $limit, &$changes) {
                foreach ($this->publishingService->getUnpublishedNodes($workspace) as $node) {
                    if (\count($changes) >= $limit) {
                        break;
                    }
                    if (!$node instanceof Node) {
                        continue;
                    }
                    $changes[] = $this->describeChange($node, $baseWorkspace);
                }
            }
        );

        $totalCount = $this->publishingService->getUnpublishedNodesCount($workspace);

        return [
            'workspace' => $workspace->getName(),
            'baseWorkspace' => $baseWorkspace?->getName(),
            'changes' => $changes,
            'returnedCount' => \count($changes),
            'totalCount' => $totalCount,
            'truncated' => $totalCount > \count($changes),
        ];
    }

    /**
     * Reverts changes in your own workspace, restoring the content to its state in the base
     * workspace.
     *
     * This is the counterpart to ListUnpublishedChanges and is what you want when you changed
     * something by mistake: the content is restored, not deleted. Nodes you created in your own
     * workspace disappear with it, because reverting them means "this never happened".
     *
     * Pass either an explicit list of nodeAggregateIds, or discardAll to revert every change in
     * the workspace at once. A single nodeAggregateId reverts that node and its subtree.
     *
     * @param string[] $nodeAggregateIds Node aggregate ids to revert, without any prefix
     * @param bool $discardAll Revert every change in the workspace instead of a selection
     * @param array<string,string> $dimensionSpacePoint Dimension space point to work in; a point this installation allows
     * @return array<string,mixed>
     */
    #[Schema(properties: McpSchemaLibrary::DIMENSION_SPACE_POINT_TOOL_SCHEMA['properties'])]
    #[McpTool(
        name: 'DiscardChanges',
        description: 'Reverts changes in your own workspace, restoring content to its state in the base workspace.
            This is the safe way to undo your own edits: content is restored, not deleted, and nothing is published.
            Nodes you created in your own workspace are removed by this, because reverting them means they never happened.
            Call ListUnpublishedChanges first to see what you would revert, then pass the nodeAggregateIds you want
            to keep, or set discardAll to revert everything at once. Prefer an explicit list over discardAll.
            Returns one result entry per reverted nodeAggregateId, in the requested order.',
        annotations: new ToolAnnotations(
            readOnlyHint: false,
            destructiveHint: true,
            idempotentHint: false,
            openWorldHint: false,
        )
    )]
    public function discardChanges(
        #[Schema(
            type: McpSchemaLibrary::NODE_AGGREGATE_IDS['type'],
            description: 'The node aggregate ids to revert, without any prefix. May be empty if discardAll is true.',
            items: McpSchemaLibrary::NODE_AGGREGATE_IDS['items'],
        )]
        array $nodeAggregateIds = [],
        #[Schema(type: 'boolean', description: 'Revert every change in the workspace instead of the given selection.')]
        bool $discardAll = false,
        #[Schema(
            description: 'The dimension space point to write in. Pass a point this installation allows: call the dimensionspace tool to get the valid points, and use the empty object {} only if it reports that this installation has no content dimensions. Both the object form and the URL-encoded JSON string form are accepted.',
        )]
        string|array $dimensionSpacePoint = [],
    ): array {
        $workspace = $this->agentWorkspaceResolver->getWorkspace();
        $result = [];

        $this->securityContext->withoutAuthorizationChecks(
            function () use ($workspace, $nodeAggregateIds, $discardAll, $dimensionSpacePoint, &$result) {
                if ($discardAll) {
                    $this->publishingService->discardAllNodes($workspace);
                    $result[] = [
                        'discardAll' => true,
                        'success' => true,
                    ];

                    return;
                }

                $contentContext = $this->contentContextFactory->getWritingContentContext(MCPContentContextFactory::decodeDimensionSpacePoint($dimensionSpacePoint));
                foreach ($nodeAggregateIds as $nodeAggregateId) {
                    try {
                        $node = $contentContext->getNodeByIdentifier($nodeAggregateId);
                        if (!$node instanceof Node) {
                            throw new \RuntimeException(
                                \sprintf('No node found for nodeAggregateId %s.', $nodeAggregateId)
                            );
                        }
                        $this->publishingService->discardNodes([$node]);
                        $result[] = [
                            'nodeAggregateId' => $nodeAggregateId,
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
            }
        );

        return [
            'workspace' => $workspace->getName(),
            'results' => $result,
        ];
    }

    /**
     * Classifies a single unpublished node by comparing it against the base workspace.
     *
     * getUnpublishedNodes() returns every node that has a row in the workspace, so the only
     * question left is how it differs from the base: it may be new, removed, moved to another
     * path, or merely modified.
     *
     * @return array<string,mixed>
     */
    private function describeChange(Node $node, ?Workspace $baseWorkspace): array
    {
        $baseNodeData = null;
        if ($baseWorkspace instanceof Workspace) {
            $baseNodeData = $this->nodeDataRepository->findOneByIdentifier(
                $node->getIdentifier(),
                $baseWorkspace,
                $node->getContext()->getDimensions(),
                false
            );
        }

        if (!$baseNodeData instanceof NodeData) {
            $changeType = 'added';
        } elseif ($node->isRemoved()) {
            $changeType = 'removed';
        } elseif ($baseNodeData->getPath() !== $node->getPath()) {
            $changeType = 'moved';
        } else {
            $changeType = 'modified';
        }

        $lastModificationDateTime = $node->getLastModificationDateTime();

        return [
            'nodeAggregateId' => $node->getIdentifier(),
            'nodeTypeName' => $node->getNodeTypeName(),
            'path' => $node->getPath(),
            'change' => $changeType,
            'lastModificationDateTime' => $lastModificationDateTime->format(\DateTimeInterface::ATOM),
        ];
    }
}
