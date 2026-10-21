<?php

declare(strict_types=1);

namespace Sitegeist\SlopMachine\Domain;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\ToolAnnotations;
use Neos\ContentRepository\Domain\Model\Node;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Repository\NodeDataRepository;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Security\Context as SecurityContext;

/**
 * Read access to the shared review workspace.
 *
 * Your own read tools (find-children, find-descendants, find-subtree) only ever see your own
 * workspace. Before you hand work over you need to know what is already in the review workspace,
 * otherwise a second run creates the same page twice.
 *
 * Note that the content of the review workspace and of your own workspace overlap: your workspace
 * is based on the review workspace, so nodes that exist there are visible here under the same
 * node aggregate ids. Only your own unpublished changes are missing from this view - use
 * ListUnpublishedChanges for those.
 */
#[Flow\Proxy(false)]
class SharedWorkspaceReadingCapabilities
{
    public function __construct(
        protected SharedWorkspaceContextFactory $sharedWorkspaceContextFactory,
        protected NodeDataRepository $nodeDataRepository,
        protected SecurityContext $securityContext,
    ) {
    }

    /**
     * @param array<string,string> $dimensionSpacePoint
     * @return array<string,mixed>
     */
    #[Schema(properties: McpSchemaLibrary::DIMENSION_SPACE_POINT_TOOL_SCHEMA['properties'])]
    #[McpTool(
        name: 'find-children-in-shared-workspace',
        description: 'A list of all child nodes of a given parent in the shared review workspace, optionally filtered by node type.
            This is your view of the workspace humans review, and the way to find out what already exists there
            before you create anything - creating content that is already there produces duplicates.
            The nodeTypeNames parameter accepts a single node type or a comma separated list, for example
            "Neos.Neos:Document,Neos.Neos:Content". A node type also matches everything that extends it, so
            "Neos.Neos:Document" returns document types like pages as well. To reduce response size, limit the
            returned properties to the list of given names in limitToPropertyNames. To skip a parameter,
            provide an asterisk (*) as value. If the result is cut off at limit, truncated tells you that
            there are more children than you received - narrow the query instead of assuming you saw them all.
            This tool is read-only and does not modify anything.',
        annotations: new ToolAnnotations(
            readOnlyHint: true,
            destructiveHint: false,
            idempotentHint: true,
            openWorldHint: false,
        )
    )]
    public function findChildrenInSharedWorkspace(
        string $parentNodeAggregateId,
        #[Schema(
            description: 'The dimension space point to read in. Pass a point this installation allows: call the dimensionspace tool to get the valid points, and use the empty object {} only if it reports that this installation has no content dimensions.',
        )]
        array $dimensionSpacePoint = [],
        #[Schema(type: 'string', description: 'A single node type, a comma separated list, or * for any node type.')]
        string $nodeTypeNames = '*',
        #[Schema(type: 'string', description: 'Comma separated list of property names to return, or * for all properties.')]
        string $limitToPropertyNames = '*',
        #[Schema(type: 'integer', description: 'Upper bound on the number of returned nodes.', minimum: 1)]
        int $limit = 200,
    ): array {
        return $this->read(
            function ($contentContext) use ($parentNodeAggregateId, $nodeTypeNames, $limitToPropertyNames, $limit): array {
                $parentNode = $contentContext->getNodeByIdentifier($parentNodeAggregateId);
                if (!$parentNode instanceof Node) {
                    throw new \RuntimeException(\sprintf('No node found for nodeAggregateId %s.', $parentNodeAggregateId));
                }
                $nodes = $this->nodeDataRepository->findByParentAndNodeTypeInContext(
                    $parentNode->getPath(),
                    $this->buildNodeTypeFilter($nodeTypeNames) ?? '',
                    $contentContext,
                    false
                );

                return $this->serializeNodes($nodes, $limitToPropertyNames, $limit);
            },
            $dimensionSpacePoint
        );
    }

    /**
     * @param array<string,string> $dimensionSpacePoint
     * @return array<string,mixed>
     */
    #[Schema(properties: McpSchemaLibrary::DIMENSION_SPACE_POINT_TOOL_SCHEMA['properties'])]
    #[McpTool(
        name: 'find-descendants-in-shared-workspace',
        description: 'A list of all descendant nodes of a given ancestor in the shared review workspace, optionally
            filtered by node type. Use this when you do not yet know the structure of the review workspace, or when
            you want to see how a whole subtree looks there. A descendant is any node below the given one, not the
            node itself. It is an expensive query - if you already know the parent to look at, prefer
            find-children-in-shared-workspace.
            The nodeTypeNames parameter accepts a single node type or a comma separated list, for example
            "Neos.Neos:Document,Neos.Neos:Content". A node type also matches everything that extends it, so
            "Neos.Neos:Document" returns document types like pages as well. To reduce response size, limit the
            returned properties to the list of given names in limitToPropertyNames. To skip a parameter,
            provide an asterisk (*) as value. If the result is cut off at limit, truncated tells you that
            there are more descendants than you received - narrow the query instead of assuming you saw them all.
            This tool is read-only and does not modify anything.',
        annotations: new ToolAnnotations(
            readOnlyHint: true,
            destructiveHint: false,
            idempotentHint: true,
            openWorldHint: false,
        )
    )]
    public function findDescendantsInSharedWorkspace(
        string $ancestorNodeAggregateId,
        #[Schema(
            description: 'The dimension space point to read in. Pass a point this installation allows: call the dimensionspace tool to get the valid points, and use the empty object {} only if it reports that this installation has no content dimensions.',
        )]
        array $dimensionSpacePoint = [],
        #[Schema(type: 'string', description: 'A single node type, a comma separated list, or * for any node type.')]
        string $nodeTypeNames = '*',
        #[Schema(type: 'string', description: 'Comma separated list of property names to return, or * for all properties.')]
        string $limitToPropertyNames = '*',
        #[Schema(type: 'integer', description: 'Upper bound on the number of returned nodes.', minimum: 1)]
        int $limit = 200,
    ): array {
        return $this->read(
            function ($contentContext) use ($ancestorNodeAggregateId, $nodeTypeNames, $limitToPropertyNames, $limit): array {
                $ancestorNode = $contentContext->getNodeByIdentifier($ancestorNodeAggregateId);
                if (!$ancestorNode instanceof Node) {
                    throw new \RuntimeException(\sprintf('No node found for nodeAggregateId %s.', $ancestorNodeAggregateId));
                }
                $nodes = $this->nodeDataRepository->findByParentAndNodeTypeInContext(
                    $ancestorNode->getPath(),
                    $this->buildNodeTypeFilter($nodeTypeNames) ?? '',
                    $contentContext,
                    true
                );

                return $this->serializeNodes($nodes, $limitToPropertyNames, $limit);
            },
            $dimensionSpacePoint
        );
    }

    /**
     * Runs a read against the shared workspace and returns a plain payload.
     *
     * The error shape matches the read tools of this package (success/message) so that an
     * agent which has learned those can handle failures here the same way.
     *
     * @param callable(\Neos\Neos\Domain\Service\ContentContext):array<string,mixed> $reader
     * @param array<string,string> $dimensionSpacePoint
     * @return array<string,mixed>
     */
    private function read(callable $reader, array $dimensionSpacePoint): array
    {
        $result = [];
        $this->securityContext->withoutAuthorizationChecks(
            function () use ($reader, $dimensionSpacePoint, &$result) {
                try {
                    $contentContext = $this->sharedWorkspaceContextFactory
                        ->getReadingContentContext($dimensionSpacePoint);
                    $result = [
                        'workspace' => $this->sharedWorkspaceContextFactory->getWorkspaceName(),
                    ] + $reader($contentContext);
                } catch (\Throwable $throwable) {
                    $result = [
                        'success' => false,
                        'message' => $throwable->getMessage(),
                    ];
                }
            }
        );

        return $result;
    }

    /**
     * Serializes a result set, keeping every node exactly once and honouring the result limit.
     *
     * The limit is reported back as truncated, so an agent can tell "that is everything" apart
     * from "that is the first part of something larger" instead of silently working with a list
     * that was cut off.
     *
     * @param iterable<Node> $nodes
     * @return array<string,mixed>
     */
    /**
     * @param iterable<NodeInterface> $nodes
     * @param string $limitToPropertyNames
     * @param int $limit
     * @return array{returnedCount: int, truncated: bool, nodes: array<int,array<string,mixed>>}
     */
    private function serializeNodes(iterable $nodes, string $limitToPropertyNames, int $limit): array
    {
        $result = [];
        $seen = [];
        $truncated = false;

        foreach ($nodes as $node) {
            if (!$node instanceof Node) {
                continue;
            }
            if (\count($result) >= $limit) {
                $truncated = true;
                break;
            }
            $identifier = $node->getIdentifier();
            if (isset($seen[$identifier])) {
                continue;
            }
            $seen[$identifier] = true;
            $result[] = $this->serializeNode($node, $limitToPropertyNames);
        }

        return [
            'returnedCount' => \count($result),
            'truncated' => $truncated,
            'nodes' => $result,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function serializeNode(Node $node, string $limitToPropertyNames): array
    {
        $limitToPropertyNames = \in_array(\trim($limitToPropertyNames), ['', '*'], true)
            ? null
            : $this->resolveListValue($limitToPropertyNames);

        return [
            'aggregateId' => $node->getIdentifier(),
            'label' => $node->getLabel(),
            'path' => $node->getPath(),
            'properties' => \array_filter(
                \iterator_to_array($node->getProperties()),
                static fn (string $propertyName): bool => !$limitToPropertyNames
                    || \in_array($propertyName, $limitToPropertyNames, true),
                \ARRAY_FILTER_USE_KEY
            ),
            'nodeTypeName' => $node->getNodeTypeName(),
        ];
    }

    /**
     * The content repository resolves this filter in a single query and expands every node type to
     * the node types extending it, so no node is returned twice and a list of a type and its super
     * type does not duplicate the nodes. A filter of * means every node type.
     */
    private function buildNodeTypeFilter(string $nodeTypeNames): ?string
    {
        $nodeTypeNames = $this->resolveListValue($nodeTypeNames);

        return $nodeTypeNames === [] ? null : \implode(',', $nodeTypeNames);
    }

    /**
     * @return array<int,string>
     */
    private function resolveListValue(string $value): array
    {
        $values = [];
        foreach (\explode(',', \trim($value)) as $singleValue) {
            $singleValue = \trim($singleValue);
            if ($singleValue !== '' && $singleValue !== '*' && !\in_array($singleValue, $values, true)) {
                $values[] = $singleValue;
            }
        }

        return $values;
    }
}
