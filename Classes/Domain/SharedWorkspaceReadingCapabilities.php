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
use Neos\Media\Domain\Model\Asset;

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
        protected ReadPagination $readPagination,
    ) {
    }

    /**
     * @param array<string,string> $dimensionSpacePoint
     * @param array<string,mixed> $cursor the nextCursor of a previous result
     * @return array<string,mixed>
     */
    #[Schema(properties: McpSchemaLibrary::PAGED_READ_TOOL_SCHEMA['properties'])]
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
            there are more children than you received - send the nextCursor back as the cursor to get the next page.
            The cursor is a best effort resume position, not a snapshot.
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
        #[Schema(description: 'The nextCursor of a previous result, to continue where it stopped.')]
        array $cursor = [],
    ): array {
        return $this->read(
            function ($contentContext) use ($parentNodeAggregateId, $nodeTypeNames, $limitToPropertyNames, $limit, $cursor): array {
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

                $nodes = $this->readPagination->applyCursor($nodes, $cursor === [] ? null : $cursor);
                $page = $this->readPagination->paginate($nodes, $limit);

                return $this->serializeNodes($page['nodes'], $limitToPropertyNames, \PHP_INT_MAX) + [
                    'returnedCount' => $page['returnedCount'],
                    'truncated' => $page['truncated'],
                    ReadPagination::CURSOR_KEY => $page[ReadPagination::CURSOR_KEY],
                ];
            },
            $dimensionSpacePoint
        );
    }

    /**
     * @param array<string,string> $dimensionSpacePoint
     * @param array<string,mixed> $cursor the nextCursor of a previous result
     * @return array<string,mixed>
     */
    #[Schema(properties: McpSchemaLibrary::PAGED_READ_TOOL_SCHEMA['properties'])]
    #[McpTool(
        name: 'find-content-in-shared-workspace',
        description: 'The content of a single document in the shared review workspace - its content collections, the layout
            roots below them and everything below those.
            This is the scoped counterpart of find-descendants-in-shared-workspace: a document owns content of its own and
            it may also parent other documents, and each of those brings its own content with it. The recursive query
            mixes both, so on a page with child pages it returns the content of every one of them.
            The result is a list of nodes, the document itself is not part of it. It is capped: when more nodes follow,
            the result reports truncated and a nextCursor, and you continue with that cursor as the cursor parameter.
            The cursor is a best effort resume position, not a snapshot - nodes that are added to or removed from the
            document in between can change what the next page holds.
            To reduce response size, you can limit the returned properties to the list of given names in limitToPropertyNames.
            To skip a parameter, provide an asterisk (*) as value.
            This tool is read-only and does not modify anything.',
        annotations: new ToolAnnotations(
            readOnlyHint: true,
            destructiveHint: false,
            idempotentHint: true,
            openWorldHint: false,
        )
    )]
    public function findContentInSharedWorkspace(
        string $nodeAggregateId,
        #[Schema(type: 'string', description: 'Comma separated list of property names to return, or * for all properties.')]
        string $limitToPropertyNames = '*',
        #[Schema(type: 'integer', description: 'Upper bound on the number of returned nodes.', minimum: 1)]
        int $limit = ReadPagination::DEFAULT_LIMIT,
        #[Schema(description: 'The nextCursor of a previous result, to continue where it stopped.')]
        array $cursor = [],
        #[Schema(
            description: 'The dimension space point to read in. Pass a point this installation allows: call the dimensionspace tool to get the valid points, and use the empty object {} only if it reports that this installation has no content dimensions.',
        )]
        array $dimensionSpacePoint = [],
    ): array {
        return $this->read(
            function ($contentContext) use ($nodeAggregateId, $limitToPropertyNames, $limit, $cursor): array {
                $document = $contentContext->getNodeByIdentifier($nodeAggregateId);
                if (!$document instanceof Node) {
                    throw new \RuntimeException(\sprintf('No node found for nodeAggregateId %s.', $nodeAggregateId));
                }

                $nodes = [];
                $seen = [];
                $collections = $this->readPagination->findChildren($contentContext, $document, 'Neos.Neos:ContentCollection');
                foreach ($collections as $collection) {
                    foreach ($this->readPagination->findChildren($contentContext, $collection, '') as $layoutRoot) {
                        if (!isset($seen[$layoutRoot->getIdentifier()])) {
                            $seen[$layoutRoot->getIdentifier()] = true;
                            $nodes[] = $layoutRoot;
                        }
                    }
                    foreach ($this->readPagination->findDescendants($contentContext, $collection, '') as $descendant) {
                        if (!isset($seen[$descendant->getIdentifier()])) {
                            $seen[$descendant->getIdentifier()] = true;
                            $nodes[] = $descendant;
                        }
                    }
                }

                $nodes = $this->readPagination->applyCursor($nodes, $cursor === [] ? null : $cursor);
                $page = $this->readPagination->paginate($nodes, $limit);

                return $this->serializeNodes($page['nodes'], $limitToPropertyNames, \PHP_INT_MAX) + [
                    'returnedCount' => $page['returnedCount'],
                    'truncated' => $page['truncated'],
                    ReadPagination::CURSOR_KEY => $page[ReadPagination::CURSOR_KEY],
                ];
            },
            $dimensionSpacePoint
        );
    }

    /**
     * @param array<string,string> $dimensionSpacePoint
     * @param array<string,mixed> $cursor the nextCursor of a previous result
     * @return array<string,mixed>
     */
    #[Schema(properties: McpSchemaLibrary::PAGED_READ_TOOL_SCHEMA['properties'])]
    #[McpTool(
        name: 'find-documents-in-shared-workspace',
        description: 'The documents below a node in the shared review workspace, without their content.
            By default only the direct child documents are returned, which is the cheap case. With recursive set to
            true, every document in the subtree is returned - that is the query that used to need
            find-descendants-in-shared-workspace with a document filter, and it is expensive on a site with many pages.
            The result is a list of nodes, the node you start from is not part of it. It is capped: when more nodes
            follow, the result reports truncated and a nextCursor, and you continue with that cursor as the cursor
            parameter. The cursor is a best effort resume position, not a snapshot - nodes that are added to or removed
            from the subtree in between can change what the next page holds.
            The nodeTypeNames parameter accepts a single node type or a comma separated list, for example
            "Neos.Neos:Document,Oneway.Base:TariffPage". A node type also matches everything that extends it, so
            "Neos.Neos:Document" returns the pages as well.
            To reduce response size, you can limit the returned properties to the list of given names in limitToPropertyNames.
            To skip a parameter, provide an asterisk (*) as value.
            This tool is read-only and does not modify anything.',
        annotations: new ToolAnnotations(
            readOnlyHint: true,
            destructiveHint: false,
            idempotentHint: true,
            openWorldHint: false,
        )
    )]
    public function findDocumentsInSharedWorkspace(
        string $nodeAggregateId,
        #[Schema(type: 'string', description: 'A single node type or a comma separated list of node types.')]
        string $nodeTypeNames = 'Neos.Neos:Document',
        #[Schema(type: 'boolean', description: 'Return every document in the subtree instead of only the direct children.')]
        bool $recursive = false,
        #[Schema(type: 'string', description: 'Comma separated list of property names to return, or * for all properties.')]
        string $limitToPropertyNames = '*',
        #[Schema(type: 'integer', description: 'Upper bound on the number of returned nodes.', minimum: 1)]
        int $limit = ReadPagination::DEFAULT_LIMIT,
        #[Schema(description: 'The nextCursor of a previous result, to continue where it stopped.')]
        array $cursor = [],
        #[Schema(
            description: 'The dimension space point to read in. Pass a point this installation allows: call the dimensionspace tool to get the valid points, and use the empty object {} only if it reports that this installation has no content dimensions.',
        )]
        array $dimensionSpacePoint = [],
    ): array {
        return $this->read(
            function ($contentContext) use ($nodeAggregateId, $nodeTypeNames, $recursive, $limitToPropertyNames, $limit, $cursor): array {
                $ancestorNode = $contentContext->getNodeByIdentifier($nodeAggregateId);
                if (!$ancestorNode instanceof Node) {
                    throw new \RuntimeException(\sprintf('No node found for nodeAggregateId %s.', $nodeAggregateId));
                }

                $nodeTypeFilter = $this->resolveListValue($nodeTypeNames);
                $nodeTypeFilter = $nodeTypeFilter === [] ? '' : \implode(',', $nodeTypeFilter);

                $nodes = $recursive
                    ? $this->readPagination->findDescendants($contentContext, $ancestorNode, $nodeTypeFilter)
                    : $this->readPagination->findChildren($contentContext, $ancestorNode, $nodeTypeFilter);

                $nodes = $this->readPagination->applyCursor($nodes, $cursor === [] ? null : $cursor);
                $page = $this->readPagination->paginate($nodes, $limit);

                return $this->serializeNodes($page['nodes'], $limitToPropertyNames, \PHP_INT_MAX) + [
                    'returnedCount' => $page['returnedCount'],
                    'truncated' => $page['truncated'],
                    ReadPagination::CURSOR_KEY => $page[ReadPagination::CURSOR_KEY],
                ];
            },
            $dimensionSpacePoint
        );
    }

    /**
     * @param array<string,string> $dimensionSpacePoint
     * @param array<string,mixed> $cursor the nextCursor of a previous result
     * @return array<string,mixed>
     */
    #[Schema(properties: McpSchemaLibrary::PAGED_READ_TOOL_SCHEMA['properties'])]
    #[McpTool(
        name: 'find-descendants-in-shared-workspace',
        description: 'A list of all descendant nodes of a given ancestor in the shared review workspace, optionally
            filtered by node type. Use this when you do not yet know the structure of the review workspace, or when
            you want to see how a whole subtree looks there. A descendant is any node below the given one, not the
            node itself. It is an expensive query - if you already know the parent to look at, prefer
            find-children-in-shared-workspace.
            The subtree of a page contains the content of every document below it as well, so when the question is
            about one document, prefer find-content-in-shared-workspace for its content and
            find-documents-in-shared-workspace for the documents below it.
            The nodeTypeNames parameter accepts a single node type or a comma separated list, for example
            "Neos.Neos:Document,Neos.Neos:Content". A node type also matches everything that extends it, so
            "Neos.Neos:Document" returns document types like pages as well. To reduce response size, limit the
            returned properties to the list of given names in limitToPropertyNames. To skip a parameter,
            provide an asterisk (*) as value. If the result is cut off at limit, truncated tells you that
            there are more descendants than you received - send the nextCursor back as the cursor to get the next
            page. The cursor is a best effort resume position, not a snapshot - nodes that are added to or removed
            from the subtree in between can change what the next page holds.
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
        #[Schema(description: 'The nextCursor of a previous result, to continue where it stopped.')]
        array $cursor = [],
    ): array {
        return $this->read(
            function ($contentContext) use ($ancestorNodeAggregateId, $nodeTypeNames, $limitToPropertyNames, $limit, $cursor): array {
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

                $nodes = $this->readPagination->applyCursor($nodes, $cursor === [] ? null : $cursor);
                $page = $this->readPagination->paginate($nodes, $limit);

                return $this->serializeNodes($page['nodes'], $limitToPropertyNames, \PHP_INT_MAX) + [
                    'returnedCount' => $page['returnedCount'],
                    'truncated' => $page['truncated'],
                    ReadPagination::CURSOR_KEY => $page[ReadPagination::CURSOR_KEY],
                ];
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
            'properties' => \array_map(
                fn (mixed $value): mixed => $this->serializePropertyValue($value),
                \array_filter(
                    \iterator_to_array($node->getProperties()),
                    static fn (string $propertyName): bool => !$limitToPropertyNames
                        || \in_array($propertyName, $limitToPropertyNames, true),
                    \ARRAY_FILTER_USE_KEY
                )
            ),
            'nodeTypeName' => $node->getNodeTypeName(),
            'hidden' => $node->isHidden(),
            'hiddenInIndex' => $node->isHiddenInIndex(),
            'hiddenBeforeDateTime' => $node->getHiddenBeforeDateTime()?->format(\DateTimeInterface::ATOM),
            'hiddenAfterDateTime' => $node->getHiddenAfterDateTime()?->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * An asset property is serialised as the asset it points to. The asset object itself
     * has no public property that identifies it, so the identifier and the title are what
     * tells one asset from another.
     */
    private function serializePropertyValue(mixed $value): mixed
    {
        if ($value instanceof Asset) {
            return [
                'id' => $value->getIdentifier(),
                'title' => $value->getTitle(),
            ];
        }

        return $value;
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
