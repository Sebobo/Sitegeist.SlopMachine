<?php

declare(strict_types=1);

namespace Sitegeist\SlopMachine\Domain;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\ToolAnnotations;
use Neos\ContentRepository\Domain\Model\Node;
use Neos\ContentRepository\Domain\Service\NodeTypeManager;
use Neos\ContentRepository\Domain\Utility\NodePaths;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Property\PropertyMapper;
use Neos\Flow\Security\Context as SecurityContext;

/**
 * Write and removal access to the shared review workspace.
 *
 * This is the hand-over path: content created here is content humans review and publish, so it is
 * the place where mistakes are most expensive. The workspace is fixed in settings
 * (@see SharedWorkspaceContextFactory) and can never be chosen by the agent.
 *
 * The commands are intentionally identical to BulkWriting - same schema, same semantics - so
 * that the agent does not have to learn a second writing dialect. A command
 * that only exists in the agent's own workspace cannot be written here though: node aggregate ids
 * that the agent created itself are not visible in the review workspace.
 */
#[Flow\Proxy(false)]
class SharedWorkspaceWritingCapabilities
{
    use PropertyValueConversionTrait;

    public function __construct(
        protected SharedWorkspaceContextFactory $sharedWorkspaceContextFactory,
        protected NodeTypeManager $nodeTypeManager,
        protected SecurityContext $securityContext,
        protected PropertyMapper $propertyMapper,
    ) {
    }

    /**
     * Issues multiple write commands against the shared review workspace at once.
     *
     * This is the tool that hands work over for review: the commands are the same
     * CreateNodeAggregateWithNode, SetNodeProperties and SetNodeReferences commands that BulkWriting
     * issues against your own workspace, with the same properties and the same meaning.
     *
     * Remember that tethered children do not need to be created explicitly, they are created
     * together with their parent, and that references must be set via the references property of a
     * command, not as properties.
     *
     * The commands are unserialized along with the `commands` parameter and must not be double
     * encoded. An example command would be
     * {"type": "SetNodeProperties", "nodeAggregateId": "8a887309-5803-458d-b30a-40736f5a5d82", "originDimensionSpacePoint": {}, "propertyValues": {"title": "My Title"}}
     *
     * This tool does not work transactionally, commands that succeeded have been applied and can be
     * considered as much for the current content graph state. Failed individual commands must be
     * corrected and retried. If more than one write operation is required, always prefer this tool.
     *
     * @param array<int,array<string,mixed>> $commands List of write commands, each item a command object, not a JSON string
     * @return array<int,array<string,mixed>>
     */
    #[McpTool(
        name: 'BulkWriteToSharedWorkspace',
        description: 'Issues multiple write commands against the shared review workspace at once.
            Use this to hand your work over for review - content created here is content a human will review and publish.
            The commands are the same CreateNodeAggregateWithNode, SetNodeProperties and SetNodeReferences commands
            that BulkWriting issues against your own workspace, with the same properties and the same meaning.
            Commands must be sent as objects, not as JSON strings. An example command would be
            {"type": "SetNodeProperties", "nodeAggregateId": "8a887309-5803-458d-b30a-40736f5a5d82", "originDimensionSpacePoint": {}, "propertyValues": {"title": "My Title"}}
            The commands are unserialized along with the `commands` parameter and must not be double encoded.
            Returns a list of command results, one per issued command in correct order.
            This tool does not work transactionally, commands that succeeded have been applied and can be considered
            as much for the current content graph state.
            - Error handling:
                - failed individual commands must be corrected and retried via this tool.
            - Decision rule:
                - Default = this tool
                - your own BulkWriting = for work that stays in your own workspace',
        annotations: new ToolAnnotations(
            readOnlyHint: false,
            destructiveHint: true,
            idempotentHint: false,
            openWorldHint: false,
        )
    )]
    public function bulkWriteToSharedWorkspace(
        #[Schema(
            type: 'array',
            description: 'List of write commands against the shared review workspace. Each item must be one valid command object, not a JSON string.',
            items: SchemaLibrary::COMMANDS_SCHEMA,
            minItems: 1,
        )]
        array $commands,
    ): array {
        $result = [];
        foreach ($commands as $command) {
            $result[] = match ($command['type'] ?? null) {
                'CreateNodeAggregateWithNode' => $this->createNode(
                    nodeTypeName: $command['nodeTypeName'],
                    originDimensionSpacePoint: $command['originDimensionSpacePoint'],
                    parentNodeAggregateId: $command['parentNodeAggregateId'],
                    initialPropertyValues: $command['initialPropertyValues'] ?? [],
                    nodeAggregateId: $command['nodeAggregateId'] ?? null,
                    succeedingSiblingNodeAggregateId: $command['succeedingSiblingNodeAggregateId'] ?? null,
                    nodeName: $command['nodeName'] ?? null,
                    references: $command['references'] ?? [],
                ),
                'SetNodeProperties' => $this->setNodeProperties(
                    nodeAggregateId: $command['nodeAggregateId'],
                    originDimensionSpacePoint: $command['originDimensionSpacePoint'],
                    propertyValues: $command['propertyValues'],
                ),
                'SetNodeReferences' => $this->setNodeReferences(
                    nodeAggregateId: $command['nodeAggregateId'],
                    originDimensionSpacePoint: $command['originDimensionSpacePoint'],
                    references: $command['references'],
                ),
                null => [
                    'success' => false,
                    'message' => 'The command type must be given',
                ],
                default => [
                    'success' => false,
                    'message' => 'Invalid command type ' . $command['type'],
                ]
            };
        }

        return $result;
    }

    /**
     * Removes one or more nodes, including their whole subtree, from the shared review workspace.
     *
     * This is a permanent deletion in a workspace humans work in - it is not a soft removal that a
     * reviewer would see as a pending marker. Read the review workspace with
     * find-children-in-shared-workspace or find-descendants-in-shared-workspace first, and remove the
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
        description: 'Removes one or more nodes, including all of their descendants, from the shared review workspace.
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
            type: McpSchemaLibrary::NODE_AGGREGATE_IDS['type'],
            description: 'The node aggregate ids to remove from the shared review workspace, without any prefix. Removing a node also removes its whole subtree.',
            items: McpSchemaLibrary::NODE_AGGREGATE_IDS['items'],
            minItems: McpSchemaLibrary::NODE_AGGREGATE_IDS['minItems'],
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

        $this->securityContext->withoutAuthorizationChecks(
            function () use ($nodeAggregateIds, $dimensionSpacePoint, $workspace, &$result) {
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
            }
        );

        return [
            'workspace' => $workspaceName,
            'removedCount' => \count(\array_filter($result, static fn (array $entry): bool => $entry['success'])),
            'results' => $result,
        ];
    }

    /**
     * @param array<string,string> $originDimensionSpacePoint
     * @param array<string,mixed> $initialPropertyValues
     * @param array<string,mixed> $references
     * @return array<string,mixed>
     */
    private function createNode(
        string $nodeTypeName,
        array $originDimensionSpacePoint,
        string $parentNodeAggregateId,
        array $initialPropertyValues,
        ?string $nodeAggregateId = null,
        ?string $succeedingSiblingNodeAggregateId = null,
        ?string $nodeName = null,
        array $references = [],
    ): array {
        $result = [];
        $this->securityContext->withoutAuthorizationChecks(
            function () use (
                $nodeTypeName,
                $originDimensionSpacePoint,
                $parentNodeAggregateId,
                $initialPropertyValues,
                $nodeAggregateId,
                $succeedingSiblingNodeAggregateId,
                $nodeName,
                $references,
                &$result,
            ) {
                try {
                    $contentContext = $this->sharedWorkspaceContextFactory
                        ->getWritingContentContext($originDimensionSpacePoint);
                    $nodeType = $this->nodeTypeManager->getNodeType($nodeTypeName);

                    $parentNode = $contentContext->getNodeByIdentifier($parentNodeAggregateId);
                    if (!$parentNode instanceof Node) {
                        throw new \RuntimeException(
                            \sprintf('No parent node found for nodeAggregateId %s.', $parentNodeAggregateId)
                        );
                    }

                    /** @var Node $createdNode */
                    $createdNode = $parentNode->createNode(
                        $nodeName ?: NodePaths::generateRandomNodeName(),
                        $nodeType,
                        $nodeAggregateId,
                    );
                    if ($initialPropertyValues !== []) {
                        $this->setProperties($createdNode, $initialPropertyValues);
                    }
                    if ($references !== []) {
                        $this->setReferences($createdNode, $references);
                    }
                    if ($succeedingSibling = $succeedingSiblingNodeAggregateId ? $contentContext->getNodeByIdentifier($succeedingSiblingNodeAggregateId) : null) {
                        $createdNode->moveBefore($succeedingSibling);
                    }

                    $tetheredDescendantIds = [];
                    foreach ($nodeType->getAutoCreatedChildNodes() as $autoCreatedNodeName => $autoCreatedNodeType) {
                        $tetheredChildNode = $createdNode->getNode($autoCreatedNodeName);
                        if ($tetheredChildNode !== null) {
                            $tetheredDescendantIds[$autoCreatedNodeName] = $tetheredChildNode->getIdentifier();
                        }
                    }

                    $result = [
                        'success' => true,
                        'nodeAggregateId' => $createdNode->getIdentifier(),
                        'nodeName' => $createdNode->getName(),
                        'tetheredDescendantAggregateIds' => $tetheredDescendantIds,
                    ];
                    $this->reportEffectivePropertyValues($createdNode, $initialPropertyValues, $result);
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
     * @param array<string,string> $originDimensionSpacePoint
     * @param array<string,mixed> $propertyValues
     * @return array<string,mixed>
     */
    private function setNodeProperties(
        string $nodeAggregateId,
        array $originDimensionSpacePoint,
        array $propertyValues,
    ): array {
        $result = [];
        $this->securityContext->withoutAuthorizationChecks(
            function () use ($nodeAggregateId, $originDimensionSpacePoint, $propertyValues, &$result) {
                try {
                    $contentContext = $this->sharedWorkspaceContextFactory
                        ->getWritingContentContext($originDimensionSpacePoint);
                    $node = $contentContext->getNodeByIdentifier($nodeAggregateId);
                    if (!$node instanceof Node) {
                        throw new \RuntimeException(
                            \sprintf('No node found for nodeAggregateId %s.', $nodeAggregateId)
                        );
                    }
                    $this->setProperties($node, $propertyValues);

                    $result = [
                        'success' => true,
                        'message' => null,
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
     * @param array<string,string> $originDimensionSpacePoint
     * @param array<string,mixed> $references
     * @return array<string,mixed>
     */
    private function setNodeReferences(
        string $nodeAggregateId,
        array $originDimensionSpacePoint,
        array $references,
    ): array {
        $result = [];
        $this->securityContext->withoutAuthorizationChecks(
            function () use ($nodeAggregateId, $originDimensionSpacePoint, $references, &$result) {
                try {
                    $contentContext = $this->sharedWorkspaceContextFactory
                        ->getWritingContentContext($originDimensionSpacePoint);
                    $node = $contentContext->getNodeByIdentifier($nodeAggregateId);
                    if (!$node instanceof Node) {
                        throw new \RuntimeException(
                            \sprintf('No node found for nodeAggregateId %s.', $nodeAggregateId)
                        );
                    }
                    $this->setReferences($node, $references);

                    $result = [
                        'success' => true,
                        'message' => null,
                        'nodeAggregateId' => $nodeAggregateId,
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
     * @param array<string,mixed> $properties
     */
    private function setProperties(Node $node, array $properties): void
    {
        $nodeType = $node->getNodeType();
        foreach ($properties as $propertyName => $propertyValue) {
            $expectedType = $nodeType->getPropertyType($propertyName);
            if ($expectedType === 'reference' || $expectedType === 'references') {
                throw new \Exception('References must be set as references, not properties.');
            }
            $propertyValue = $this->convertPropertyValue($propertyName, $expectedType, $propertyValue);
            $node->setProperty($propertyName, $propertyValue);
        }
    }

    /**
     * Property values can be stored differently than requested: a node type or a Neos
     * signal may adjust a value while it is being set, and values can be cast by the
     * property mapper. The best known case is the uriPathSegment of a document, which
     * Neos makes unique among its sibling documents - counting removed siblings as well -
     * so a requested value can come back with a numeric suffix. It is not the only case,
     * so the comparison is made for every scalar value.
     *
     * The message states the difference without naming a cause, because the caller cannot
     * know one; the tool description names the known cases. The result array is only
     * extended when a value actually differs, so responses stay unchanged in the regular
     * case.
     *
     * @param array<string,mixed> $requestedValues
     * @param array<string,mixed> $result the payload to extend, passed by reference
     */
    private function reportEffectivePropertyValues(Node $node, array $requestedValues, array &$result): void
    {
        $differences = [];
        $effectiveValues = [];
        foreach ($requestedValues as $propertyName => $requestedValue) {
            $effectiveValue = $node->getProperty($propertyName);
            // Assets, dates and other objects can neither be compared here nor serialised
            // into the response, so only scalar values take part in the comparison.
            if (!is_scalar($effectiveValue) && $effectiveValue !== null) {
                continue;
            }
            if (!is_scalar($requestedValue) && $requestedValue !== null) {
                continue;
            }
            $effectiveValues[$propertyName] = $effectiveValue;
            if ($effectiveValue !== $requestedValue) {
                $differences[] = \sprintf(
                    '%s: requested "%s", stored "%s"',
                    $propertyName,
                    $requestedValue,
                    $effectiveValue
                );
            }
        }

        if ($differences === []) {
            return;
        }

        $result['message'] = 'The node stored different values than requested: '
            . implode('; ', $differences) . '.';
        $result['effectivePropertyValues'] = $effectiveValues;
    }

    /**
     * @param array<string,mixed> $references
     */
    private function setReferences(Node $node, array $references): void
    {
        $nodeType = $node->getNodeType();
        foreach ($references as $propertyName => $propertyValue) {
            $expectedType = $nodeType->getPropertyType($propertyName);
            if ($expectedType === 'reference' && \is_array($propertyValue)) {
                $propertyValue = reset($propertyValue);
            }
            if ($expectedType === 'references' && \is_string($propertyValue)) {
                $propertyValue = [$propertyValue];
            }
            $node->setProperty($propertyName, $propertyValue);
        }
    }
}
