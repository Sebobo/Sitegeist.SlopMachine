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
 * Removal, publishing and change management for the agent's own personal workspace.
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
     * Publishes your own workspace into the workspace it is based on.
     *
     * This is the hand-over: it is what an editor does when they are done. The content leaves
     * your workspace and appears in the workspace you are based on - the shared review workspace,
     * or the topic workspace you have rebased onto. A human reviews it there and publishes it
     * further.
     *
     * Because it is a real publish and not a copy, your workspace is empty afterwards for what
     * has been published: ListUnpublishedChanges reports what is left. To correct something you
     * have already published, change it in your own workspace and publish again - the publish
     * replaces what is already there.
     *
     * @param string[] $nodeAggregateIds The nodes to publish; all unpublished changes by default
     * @param bool $dryRun Only report what would be published, without publishing anything
     * @return array<string,mixed>
     */
    #[McpTool(
        name: 'PublishOwnWorkspace',
        description: 'Publishes your own workspace into the workspace it is based on - the shared review workspace, or the
            topic workspace you have rebased onto. This is the hand-over: it is exactly what an editor does when they are
            done, so the content leaves your workspace and appears in the workspace you are based on, where a human reviews
            it and publishes it further.
            Your workspace is empty afterwards for what has been published - ListUnpublishedChanges reports what is left.
            To correct something you have already published, change it in your own workspace and publish again: the
            publish replaces what is already there.
            Pass nodeAggregateIds to publish only some of your changes, or nothing to publish all of them. With dryRun set
            to true nothing is published and you get the list of what would be.
            The workspace you are based on is never the live workspace: the agent does not publish to live, a human does.',
        annotations: new ToolAnnotations(
            readOnlyHint: false,
            destructiveHint: false,
            idempotentHint: false,
            openWorldHint: false,
        )
    )]
    public function publishOwnWorkspace(
        #[Schema(
            type: 'array',
            description: 'The node aggregate ids to publish, without any prefix. May be empty to publish every unpublished change.',
            items: ['type' => 'string'],
        )]
        array $nodeAggregateIds = [],
        #[Schema(type: 'boolean', description: 'Only report what would be published, without publishing anything.')]
        bool $dryRun = false,
    ): array {
        $result = [];
        $this->securityContext->withoutAuthorizationChecks(
            function () use ($nodeAggregateIds, $dryRun, &$result) {
                try {
                    $personalWorkspace = $this->agentWorkspaceResolver->getWorkspace();
                    $targetWorkspace = $personalWorkspace->getBaseWorkspace();
                    if (!$targetWorkspace instanceof Workspace) {
                        throw new \RuntimeException(
                            'Your workspace has no base workspace, so there is nothing to publish into.'
                        );
                    }
                    if (!$targetWorkspace->isInternalWorkspace()) {
                        throw new \RuntimeException(\sprintf(
                            'Your workspace is based on "%s", which is not an internal workspace. The agent publishes into'
                            . ' the shared review workspace or into a topic workspace of it - never into "live" and never'
                            . ' into a personal workspace.',
                            $targetWorkspace->getName()
                        ));
                    }

                    $unpublishedNodes = $this->publishingService->getUnpublishedNodes($personalWorkspace);
                    $selectedNodes = $this->selectNodes($unpublishedNodes, $nodeAggregateIds);

                    if (!$dryRun) {
                        foreach ($selectedNodes as $node) {
                            $this->publishingService->publishNode($node, $targetWorkspace);
                        }
                    }

                    $result = [
                        'success' => true,
                        'published' => \count($selectedNodes),
                        'dryRun' => $dryRun,
                        'workspace' => $personalWorkspace->getName(),
                        'targetWorkspace' => $targetWorkspace->getName(),
                        'remainingUnpublishedChanges' => $dryRun
                            ? \count($unpublishedNodes)
                            : $this->publishingService->getUnpublishedNodesCount($personalWorkspace),
                        'nodes' => $this->describeNodes($selectedNodes),
                    ];
                } catch (\Throwable $exception) {
                    $result = [
                        'success' => false,
                        'message' => $exception->getMessage(),
                    ];
                }
            }
        );

        return $result;
    }

    /**
     * Picks the nodes to publish: the given ones, or all of them if none are given.
     *
     * @param NodeInterface[] $unpublishedNodes
     * @param string[] $nodeAggregateIds
     * @return NodeInterface[]
     */
    private function selectNodes(array $unpublishedNodes, array $nodeAggregateIds): array
    {
        if ($nodeAggregateIds === []) {
            return $unpublishedNodes;
        }

        $selected = [];
        foreach ($unpublishedNodes as $node) {
            if (\in_array($node->getIdentifier(), $nodeAggregateIds, true)) {
                $selected[] = $node;
            }
        }

        return $selected;
    }

    /**
     * Describes the published nodes for the result.
     *
     * @param NodeInterface[] $nodes
     * @return array<int,array<string,mixed>>
     */
    private function describeNodes(array $nodes): array
    {
        $described = [];
        foreach ($nodes as $node) {
            $described[] = [
                'nodeAggregateId' => $node->getIdentifier(),
                'path' => $node->getPath(),
                'nodeTypeName' => $node instanceof Node ? $node->getNodeTypeName() : null,
            ];
        }

        return $described;
    }

    /**
     * Removes one or more nodes - including their whole subtree - from your own workspace.
     *
     * A node that was created in this workspace is gone with its whole subtree: the rows of
     * its children are removed with it, so that no child is left behind below a parent that
     * does not exist any more. The reviewer does not see it at all.
     *
     * A node that exists in the base workspace is only marked as removed: the deletion stays
     * a change that a reviewer has to publish.
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
            A node that you created in this workspace is deleted with its whole subtree - it is not a soft removal that a reviewer would see as pending.
            Use it only for content you created yourself that must not end up in the review workspace.
            A node that exists in the base workspace is refused: removing inherited content is not what removal is for.
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
                        $this->removeNodeFromWorkspace($node);
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
                        $this->discardNodeWithItsChildren($node);
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

    /**
     * Removes a node from the workspace, without leaving its children behind.
     *
     * A node that was created in the workspace is gone with its whole subtree: the rows of
     * the children are removed with it, so that no child is left below a parent that does
     * not exist any more.
     *
     * A node that exists in the base workspace is only marked as removed: the deletion has
     * to be published by a reviewer.
     */
    private function removeNodeFromWorkspace(NodeInterface $node): void
    {
        if ($this->nodeExistsInBaseWorkspace($node)) {
            $node->remove();

            return;
        }

        $this->discardNodeWithItsChildren($node);
    }

    /**
     * Discards a node without leaving its children behind.
     *
     * A node that was created in the workspace is removed when it is discarded, and its
     * children are removed with it - a child whose parent is gone is an orphan that the
     * content repository cannot do anything with any more.
     *
     * A node that exists in the base workspace is only reverted: its children stay where
     * they are.
     */
    private function discardNodeWithItsChildren(NodeInterface $node): void
    {
        if ($this->nodeExistsInBaseWorkspace($node)) {
            $this->publishingService->discardNode($node);

            return;
        }

        $nodes = [];
        $pathPrefix = $node->getPath() . '/';
        foreach ($this->publishingService->getUnpublishedNodes($node->getWorkspace()) as $candidate) {
            if (!str_starts_with($candidate->getPath(), $pathPrefix)) {
                continue;
            }
            $nodes[] = $candidate;
        }
        // The deepest node first, so that a node is never removed before its children.
        usort(
            $nodes,
            static fn (NodeInterface $left, NodeInterface $right): int => substr_count($right->getPath(), '/') <=> substr_count($left->getPath(), '/')
        );
        $nodes[] = $node;

        $this->publishingService->discardNodes($nodes);
    }

    /**
     * Whether the node exists in the workspace the workspace of the node is based on.
     */
    private function nodeExistsInBaseWorkspace(NodeInterface $node): bool
    {
        $baseWorkspace = $node->getWorkspace()->getBaseWorkspace();
        if (!$baseWorkspace instanceof Workspace) {
            return false;
        }

        return $this->nodeDataRepository->findOneByIdentifier(
            $node->getIdentifier(),
            $baseWorkspace,
            $node->getContext()->getDimensions(),
            false
        ) instanceof NodeData;
    }
}
