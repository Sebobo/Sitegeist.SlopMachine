<?php

declare(strict_types=1);

namespace Sitegeist\SlopMachine\Domain;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\ToolAnnotations;
use Neos\ContentRepository\Domain\Model\Node;
use Neos\ContentRepository\Domain\Repository\NodeDataRepository;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Security\Context;
use Neos\Media\Domain\Model\Asset;
use Neos\Neos\Domain\Service\ContentContext;
use Neos\Neos\Domain\Service\NodeSearchServiceInterface;
use Neos\Utility\Arrays;

#[Flow\Proxy(false)]
class ContentRepositoryReadingCapabilities
{
    public const FIND_CHILDREN_URI = 'contentsubgraph://find-children/{dimensionSpacePoint}/{parentNodeAggregateId}/{nodeTypeNames}/{limitToPropertyNames}';
    public const FIND_CONTENT_URI = 'contentsubgraph://find-content/{dimensionSpacePoint}/{nodeAggregateId}/{limitToPropertyNames}';
    public const FIND_DOCUMENTS_URI = 'contentsubgraph://find-documents/{dimensionSpacePoint}/{nodeAggregateId}/{nodeTypeNames}/{limitToPropertyNames}';
    public const FIND_DESCENDANTS_URI = 'contentsubgraph://find-descendants/{dimensionSpacePoint}/{ancestorNodeAggregateId}/{nodeTypeNames}/{searchTerm}/{limitToPropertyNames}';
    public const FIND_SUBTREE_URI = 'contentsubgraph://find-subtree/{dimensionSpacePoint}/{entryNodeAggregateId}/{nodeTypeNames}/{maximumLevels}/{limitToPropertyNames}';

    public function __construct(
        protected MCPContentContextFactory $contentContextFactory,
        protected Context $securityContext,
        protected NodeSearchServiceInterface $nodeSearchService,
        protected NodeDataRepository $nodeDataRepository,
        protected ReadPagination $readPagination,
    ) {
    }

    /**
     * @param string|array<string,string> $dimensionSpacePoint
     * @param array<string,mixed> $cursor the nextCursor of a previous result
     * @return array<string,mixed>
     */
    #[McpTool(
        name: 'find-children',
        description: 'A list of all available child nodes of a given parent that are of a given type.
            This is a rather efficient query; use this if you already know the parent under which you want to search.
            The nodeTypeNames parameter accepts a single node type or a comma separated list of node types, for example "Neos.Neos:Document,Neos.Neos:Content". A node type also matches everything that extends it, so "Neos.Neos:Document" returns pages and other document types as well. Listing a type together with one of its super types returns every matching node exactly once, never twice.
            Call the dimensionspace tool to obtain a dimension space point that this installation allows and pass it URL-encoded; do not invent dimension names or values.
            To reduce response size, you can limit the returned properties to the list of given names in the parameter limitToPropertyNames.
            To skip a parameter, provide an asterisk (*) as value.
            The result is a list in which every node appears once, containing the direct children of the given node only, not the whole subtree.
            The returned data describes the current state of the content graph and does not give any reliable information on structural constraints.',
        annotations: new ToolAnnotations(
            readOnlyHint: true,
            destructiveHint: false,
            idempotentHint: true,
            openWorldHint: false,
        )
    )]
    #[Schema(properties: McpSchemaLibrary::PAGED_READ_TOOL_SCHEMA['properties'])]
    public function findChildren(
        string $parentNodeAggregateId,
        string $nodeTypeNames,
        string $limitToPropertyNames,
        #[Schema(type: 'integer', description: 'Upper bound on the number of returned nodes.', minimum: 1)]
        int $limit = ReadPagination::DEFAULT_LIMIT,
        #[Schema(description: 'The nextCursor of a previous result, to continue where it stopped.')]
        array $cursor = [],
        string|array $dimensionSpacePoint = [],
    ): array {
        $result = [];
        $this->securityContext->withoutAuthorizationChecks(
            function() use(
                $nodeTypeNames,
                $dimensionSpacePoint,
                $parentNodeAggregateId,
                $limitToPropertyNames,
                $limit,
                $cursor,
                &$result,
            ) {
                try {
                    $dimensionSpacePoint = MCPContentContextFactory::decodeDimensionSpacePoint($dimensionSpacePoint);
                    $nodeTypeNames = $this->resolveNodeTypeNames($nodeTypeNames);
                    $limitToPropertyNames = $this->resolveListValue($limitToPropertyNames);
                    $contentContext = $this->contentContextFactory->getReadingContentContext($dimensionSpacePoint);

                    $ancestorNode = $this->requireNode($contentContext, $parentNodeAggregateId);
                    /**
                     * One query for the whole filter, on purpose.
                     *
                     * A FlowQuery filter group such as "[instanceof A],[instanceof B]" is evaluated
                     * as one query per group member, so a node matching two of the requested types
                     * - a type together with one of its super types, which is the normal case when
                     * asking for a base type like Neos.Neos:Document and a concrete one - was
                     * returned once per matching group. The content repository takes the filter as
                     * a single comma separated list and resolves it in one pass instead.
                     */
                    $foundNodes = $this->readPagination->findChildren(
                        $contentContext,
                        $ancestorNode,
                        $nodeTypeNames === [] ? '' : \implode(',', $nodeTypeNames)
                    );
                    $foundNodes = $this->readPagination->applyCursor($foundNodes, $cursor === [] ? null : $cursor);
                    $page = $this->readPagination->paginate($foundNodes, $limit);
                    $nodes = [];
                    foreach ($page['nodes'] as $node) {
                        /** @var Node $node */
                        $nodes[] = $this->serializeNode($node, $limitToPropertyNames);
                    }

                    $result = [
                        'uri' => self::FIND_CHILDREN_URI,
                        'name' => 'Child nodes',
                        'description' => 'A list of nodes. The aggregateId identifies a node in the subgraph. The properties field contains the current state of the properties of that node. The nodeTypeName field contains the name of the type of that node, for more information see the ' . NodeTypeSchemaCapabilities::FULL_URI . ' resource.',
                        'mimeType' => 'application/json',
                        'returnedCount' => $page['returnedCount'],
                        'truncated' => $page['truncated'],
                        ReadPagination::CURSOR_KEY => $page[ReadPagination::CURSOR_KEY],
                        'text' => \json_encode($nodes),
                    ];
                } catch (\Throwable $t) {
                    $result = [
                        'success' => false,
                        'message' => $t->getMessage(),
                    ];
                    return;
                }
            }
        );

        return $result;
    }

    /**
     * The content of a single document: the content collections it owns, the layout roots below
     * them and everything below those.
     *
     * This is the scoped counterpart of find-descendants. A document owns content of its own and
     * it may also parent other documents, and each of those brings its own content with it. The
     * recursive query mixes both, so on a page with child pages it returns the content of every
     * one of them - on one page of this installation 5,585 nodes where the page's own content is
     * 5. Use this tool when the question is about one document, and find-documents when it is
     * about the documents below it.
     *
     * @param string|array<string,string> $dimensionSpacePoint
     * @param array<string,mixed> $cursor the nextCursor of a previous result
     * @return array<string,mixed>
     */
    #[McpTool(
        name: 'find-content',
        description: 'The content of a single document - its content collections, the layout roots below them and
            everything below those. This is the scoped counterpart of find-descendants: a document owns content of its
            own and it may also parent other documents, and each of those brings its own content with it. The
            recursive query mixes both, so on a page with child pages it returns the content of every one of them.
            Use this tool when the question is about the content of one document, and find-documents when it is about
            the documents below it.
            The result is a list of nodes, the document itself is not part of it. It is capped: when more nodes
            follow, the result reports truncated and a nextCursor, and you continue with that cursor as the cursor
            parameter. The cursor is a best effort resume position, not a snapshot - nodes that are added to or
            removed from the document in between can change what the next page holds.
            To reduce response size, you can limit the returned properties to the list of given names in limitToPropertyNames.
            To skip a parameter, provide an asterisk (*) as value.
            Call the dimensionspace tool to obtain a dimension space point that this installation allows and pass it URL-encoded; do not invent dimension names or values.',
        annotations: new ToolAnnotations(
            readOnlyHint: true,
            destructiveHint: false,
            idempotentHint: true,
            openWorldHint: false,
        )
    )]
    #[Schema(properties: McpSchemaLibrary::PAGED_READ_TOOL_SCHEMA['properties'])]
    public function findContent(
        string $nodeAggregateId,
        #[Schema(type: 'string', description: 'Comma separated list of property names to return, or * for all properties.')]
        string $limitToPropertyNames = '*',
        #[Schema(type: 'integer', description: 'Upper bound on the number of returned nodes.', minimum: 1)]
        int $limit = ReadPagination::DEFAULT_LIMIT,
        #[Schema(description: 'The nextCursor of a previous result, to continue where it stopped.')]
        array $cursor = [],
        string|array $dimensionSpacePoint = [],
    ): array {
        $result = [];
        $this->securityContext->withoutAuthorizationChecks(
            function () use (
                $nodeAggregateId,
                $limitToPropertyNames,
                $limit,
                $cursor,
                $dimensionSpacePoint,
                &$result,
            ) {
                try {
                    $dimensionSpacePoint = MCPContentContextFactory::decodeDimensionSpacePoint($dimensionSpacePoint);
                    $limitToPropertyNames = $this->resolveListValue($limitToPropertyNames);
                    $contentContext = $this->contentContextFactory->getReadingContentContext($dimensionSpacePoint);

                    $document = $this->requireNode($contentContext, $nodeAggregateId);

                    /**
                     * Two steps, because the content of a document sits two levels down: the
                     * document owns the content collections, and the collections own the layout
                     * roots (a column, a banner), and the content is below those. The second
                     * step takes the descendants of the layout roots, which already covers the
                     * fragment column and everything in it.
                     */
                    $nodes = [];
                    $seen = [];
                    $collections = $this->readPagination->findChildren(
                        $contentContext,
                        $document,
                        'Neos.Neos:ContentCollection'
                    );
                    foreach ($collections as $collection) {
                        $layoutRoots = $this->readPagination->findChildren($contentContext, $collection, '');
                        foreach ($layoutRoots as $layoutRoot) {
                            if (!isset($seen[$layoutRoot->getIdentifier()])) {
                                $seen[$layoutRoot->getIdentifier()] = true;
                                $nodes[] = $layoutRoot;
                            }
                        }
                        $descendants = $this->readPagination->findDescendants($contentContext, $collection, '');
                        foreach ($descendants as $descendant) {
                            if (!isset($seen[$descendant->getIdentifier()])) {
                                $seen[$descendant->getIdentifier()] = true;
                                $nodes[] = $descendant;
                            }
                        }
                    }

                    $nodes = $this->readPagination->applyCursor($nodes, $cursor === [] ? null : $cursor);
                    $page = $this->readPagination->paginate($nodes, $limit);

                    $payload = \array_map(
                        fn (Node $node): array => $this->serializeNode($node, $limitToPropertyNames),
                        $page['nodes'],
                    );

                    $result = [
                        'uri' => self::FIND_CONTENT_URI,
                        'name' => 'Content of a document',
                        'description' => 'A list of nodes. The aggregateId identifies a node in the subgraph. The properties field contains the current state of the properties of that node. The nodeTypeName field contains the name of the type of that node, for more information see the ' . NodeTypeSchemaCapabilities::FULL_URI . ' resource.',
                        'mimeType' => 'application/json',
                        'returnedCount' => $page['returnedCount'],
                        'truncated' => $page['truncated'],
                        ReadPagination::CURSOR_KEY => $page[ReadPagination::CURSOR_KEY],
                        'text' => \json_encode($payload),
                    ];
                } catch (\Throwable $t) {
                    $result = [
                        'success' => false,
                        'message' => $t->getMessage(),
                    ];
                    return;
                }
            }
        );

        return $result;
    }

    /**
     * The documents below a node, without their content.
     *
     * @param string|array<string,string> $dimensionSpacePoint
     * @param array<string,mixed> $cursor the nextCursor of a previous result
     * @return array<string,mixed>
     */
    #[McpTool(
        name: 'find-documents',
        description: 'The documents below a node, without their content.
            By default only the direct child documents are returned, which is the cheap case. With recursive set to
            true, every document in the subtree is returned - that is the query that used to need find-descendants
            with a document filter, and it is expensive on a site with many pages.
            The result is a list of nodes, the node you start from is not part of it. It is capped: when more nodes
            follow, the result reports truncated and a nextCursor, and you continue with that cursor as the cursor
            parameter. The cursor is a best effort resume position, not a snapshot - nodes that are added to or
            removed from the subtree in between can change what the next page holds.
            The nodeTypeNames parameter accepts a single node type or a comma separated list, for example
            "Neos.Neos:Document,Oneway.Base:TariffPage". A node type also matches everything that extends it, so
            "Neos.Neos:Document" returns the pages as well.
            To reduce response size, you can limit the returned properties to the list of given names in limitToPropertyNames.
            To skip a parameter, provide an asterisk (*) as value.
            Call the dimensionspace tool to obtain a dimension space point that this installation allows and pass it URL-encoded; do not invent dimension names or values.',
        annotations: new ToolAnnotations(
            readOnlyHint: true,
            destructiveHint: false,
            idempotentHint: true,
            openWorldHint: false,
        )
    )]
    #[Schema(properties: McpSchemaLibrary::PAGED_READ_TOOL_SCHEMA['properties'])]
    public function findDocuments(
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
        string|array $dimensionSpacePoint = [],
    ): array {
        $result = [];
        $this->securityContext->withoutAuthorizationChecks(
            function () use (
                $nodeAggregateId,
                $nodeTypeNames,
                $recursive,
                $limitToPropertyNames,
                $limit,
                $cursor,
                $dimensionSpacePoint,
                &$result,
            ) {
                try {
                    $dimensionSpacePoint = MCPContentContextFactory::decodeDimensionSpacePoint($dimensionSpacePoint);
                    $nodeTypeNames = $this->resolveNodeTypeNames($nodeTypeNames);
                    $limitToPropertyNames = $this->resolveListValue($limitToPropertyNames);
                    $contentContext = $this->contentContextFactory->getReadingContentContext($dimensionSpacePoint);

                    $ancestorNode = $this->requireNode($contentContext, $nodeAggregateId);
                    $nodeTypeFilter = $nodeTypeNames === [] ? '' : \implode(',', $nodeTypeNames);

                    $nodes = $recursive
                        ? $this->readPagination->findDescendants($contentContext, $ancestorNode, $nodeTypeFilter)
                        : $this->readPagination->findChildren($contentContext, $ancestorNode, $nodeTypeFilter);

                    $nodes = $this->readPagination->applyCursor($nodes, $cursor === [] ? null : $cursor);
                    $page = $this->readPagination->paginate($nodes, $limit);

                    $payload = \array_map(
                        fn (Node $node): array => $this->serializeNode($node, $limitToPropertyNames),
                        $page['nodes'],
                    );

                    $result = [
                        'uri' => self::FIND_DOCUMENTS_URI,
                        'name' => 'Documents below a node',
                        'description' => 'A list of nodes. The aggregateId identifies a node in the subgraph. The properties field contains the current state of the properties of that node. The nodeTypeName field contains the name of the type of that node, for more information see the ' . NodeTypeSchemaCapabilities::FULL_URI . ' resource.',
                        'mimeType' => 'application/json',
                        'returnedCount' => $page['returnedCount'],
                        'truncated' => $page['truncated'],
                        ReadPagination::CURSOR_KEY => $page[ReadPagination::CURSOR_KEY],
                        'text' => \json_encode($payload),
                    ];
                } catch (\Throwable $t) {
                    $result = [
                        'success' => false,
                        'message' => $t->getMessage(),
                    ];
                    return;
                }
            }
        );

        return $result;
    }

    /**
     * @param string|array<string,string> $dimensionSpacePoint
     * @param array<string,mixed> $cursor the nextCursor of a previous result
     * @return array<string,mixed>
     */
    #[McpTool(
        name: 'find-descendants',
        description: 'A list of all available descendant nodes of a given ancestor that are of a given type and match an optional search term.
            This is a rather expensive query; use this if do not yet know the structure or the parent to search children of.
            The nodeTypeNames parameter accepts a single node type or a comma separated list of node types, for example "Neos.Neos:Document,Neos.Neos:Content". A node type also matches everything that extends it, and listing a type together with one of its super types still returns every matching node exactly once.
            Without a search term the result is a list with the whole subtree below the given node - the given node itself is not part of it - in which every node appears once. With a search term the result is an object keyed by node path instead, because a search term is not restricted to the subtree; check which of the two shapes you got before parsing it.
            A search term is matched against node properties across the workspace, so it can return nodes outside the subtree. Pass an asterisk (*) to walk the subtree structurally instead.
            The result is capped: when more nodes follow, the result reports truncated and a nextCursor, and you continue with that cursor as the cursor parameter. The cursor is a best effort resume position, not a snapshot - nodes that are added to or removed from the subtree in between can change what the next page holds.
            This is the most expensive query there is, because the subtree of a page contains the content of every document below it as well. When you know the document you are interested in, prefer find-content for its content and find-documents for the documents below it.
            Call the dimensionspace tool to obtain a dimension space point that this installation allows and pass it URL-encoded; do not invent dimension names or values.
            To reduce response size, you can limit the returned properties to the list of given names in the parameter limitToPropertyNames.
            To skip a parameter, provide an asterisk (*) as value.
            The returned data describes the current state of the content graph and does not give any reliable information on structural constraints.',
        annotations: new ToolAnnotations(
            readOnlyHint: true,
            destructiveHint: false,
            idempotentHint: true,
            openWorldHint: false,
        )
    )]
    #[Schema(properties: McpSchemaLibrary::PAGED_READ_TOOL_SCHEMA['properties'])]
    public function findDescendants(
        string $ancestorNodeAggregateId,
        string $nodeTypeNames,
        string $searchTerm,
        string $limitToPropertyNames,
        #[Schema(type: 'integer', description: 'Upper bound on the number of returned nodes.', minimum: 1)]
        int $limit = ReadPagination::DEFAULT_LIMIT,
        #[Schema(description: 'The nextCursor of a previous result, to continue where it stopped.')]
        array $cursor = [],
        string|array $dimensionSpacePoint = [],
    ): array {
        $result = [];
        $this->securityContext->withoutAuthorizationChecks(
            function() use(
                $nodeTypeNames,
                $dimensionSpacePoint,
                $ancestorNodeAggregateId,
                $limitToPropertyNames,
                $searchTerm,
                $limit,
                $cursor,
                &$result,
            ) {
                try {
                    $dimensionSpacePoint = MCPContentContextFactory::decodeDimensionSpacePoint($dimensionSpacePoint);
                    $nodeTypeNames = $this->resolveNodeTypeNames($nodeTypeNames);
                    $limitToPropertyNames = $this->resolveListValue($limitToPropertyNames);
                    $contentContext = $this->contentContextFactory->getReadingContentContext($dimensionSpacePoint);

                    if ($searchTerm = $this->resolveSingleValue($searchTerm)) {
                        /** @var Node[] $nodes */
                        $nodes = $this->nodeSearchService->findByProperties($searchTerm, $nodeTypeNames, $contentContext);
                    } else {
                        /**
                         * The query goes through the repository rather than through a FlowQuery
                         * find(), because the repository sorts the nodes of a subtree by their
                         * index and breaks ties by the aggregate id - and that order is what the
                         * cursor is a position in. A FlowQuery result is in tree order, whose
                         * indexes are not comparable between different parents, so a cursor into it
                         * would skip and repeat nodes.
                         *
                         * The repository resolves a comma separated list of node types in one
                         * query, so a type together with one of its super types still returns
                         * every matching node exactly once.
                         */
                        $ancestorNode = $this->requireNode($contentContext, $ancestorNodeAggregateId);
                        /** @var Node[] $nodes */
                        $nodes = $this->readPagination->findDescendants(
                            $contentContext,
                            $ancestorNode,
                            $nodeTypeNames === [] ? '' : \implode(',', $nodeTypeNames)
                        );
                    }
                    $nodes = $this->readPagination->applyCursor($nodes, $cursor === [] ? null : $cursor);
                    $page = $this->readPagination->paginate($nodes, $limit);
                    $payload = \array_map(
                        fn (Node $node): array => $this->serializeNode($node, $limitToPropertyNames),
                        $page['nodes'],
                    );

                    $result = [
                        'uri' => self::FIND_DESCENDANTS_URI,
                        'name' => 'Descendant nodes',
                        'description' => 'A list of nodes. The aggregateId identifies a node in the subgraph. The properties field contains the current state of the properties of that node. The nodeTypeName field contains the name of the type of that node, for more information see the ' . NodeTypeSchemaCapabilities::FULL_URI . ' resource.',
                        'mimeType' => 'application/json',
                        'returnedCount' => $page['returnedCount'],
                        'truncated' => $page['truncated'],
                        ReadPagination::CURSOR_KEY => $page[ReadPagination::CURSOR_KEY],
                        'text' => \json_encode($payload),
                    ];
                } catch (\Throwable $t) {
                    $result = [
                        'success' => false,
                        'message' => $t->getMessage(),
                    ];
                    return;
                }
            }
        );

        return $result;
    }

    /**
     * @param string|array<string,string> $dimensionSpacePoint
     * @param array<string,mixed> $cursor the nextCursor of a previous result
     * @return array<string,mixed>
     */
    #[McpTool(
        name: 'find-subtree',
        description: 'A hierarchical subtree for a single page only.
            It contains the page, its content and the content of the documents below it, as a tree of level, node and
            children. It is the tool to use when the shape of the tree is what you need; for the content of a page on
            its own, find-content is the cheaper and more precise answer.
            maximumLevels bounds how deep the hierarchy is walked and costs one query per visited node, so keep it as
            small as the task allows.
            The result is capped: when more nodes follow, the result reports truncated and a nextCursor, and you
            continue with that cursor as the cursor parameter. The cursor is a best effort resume position, not a
            snapshot - nodes that are added to or removed from the subtree in between can change what the next page
            holds.
            Call the dimensionspace tool to obtain a dimension space point that this installation allows and pass it URL-encoded; do not invent dimension names or values.
            To reduce response size, you can limit the returned properties to the list of given names in the parameter limitToPropertyNames.
            To skip a parameter, provide an asterisk (*) as value.',
        annotations: new ToolAnnotations(
            readOnlyHint: true,
            destructiveHint: false,
            idempotentHint: true,
            openWorldHint: false,
        )
    )]
    #[Schema(properties: McpSchemaLibrary::PAGED_READ_TOOL_SCHEMA['properties'])]
    public function findSubtree(
        string $entryNodeAggregateId,
        string $nodeTypeNames,
        int $maximumLevels,
        string $limitToPropertyNames,
        #[Schema(type: 'integer', description: 'Upper bound on the number of returned nodes.', minimum: 1)]
        int $limit = ReadPagination::DEFAULT_LIMIT,
        #[Schema(description: 'The nextCursor of a previous result, to continue where it stopped.')]
        array $cursor = [],
        string|array $dimensionSpacePoint = [],
    ): array {
        $result = [];
        $this->securityContext->withoutAuthorizationChecks(
            function () use (
                $dimensionSpacePoint,
                $entryNodeAggregateId,
                $nodeTypeNames,
                $maximumLevels,
                $limitToPropertyNames,
                $limit,
                $cursor,
                &$result,
            ) {
                try {
                    $dimensionSpacePoint = MCPContentContextFactory::decodeDimensionSpacePoint($dimensionSpacePoint);
                    $nodeTypeNames = $this->resolveNodeTypeNames($nodeTypeNames);
                    $limitToPropertyNames = $this->resolveListValue($limitToPropertyNames);
                    $contentContext = $this->contentContextFactory->getReadingContentContext($dimensionSpacePoint);

                    $entryNode = $contentContext->getNodeByIdentifier($entryNodeAggregateId);
                    if (!$entryNode instanceof Node) {
                        throw new \RuntimeException('entry node not found for given nodeAggregateId.');
                    }

                    $walk = $this->collectSubtreeWalk(
                        contentContext: $contentContext,
                        currentNode: $entryNode,
                        nodeTypeFilter: $nodeTypeNames === [] ? '' : \implode(',', $nodeTypeNames),
                        level: 0,
                        maximumLevels: $maximumLevels,
                        walk: [],
                    );

                    $walk = $this->readPagination->applyCursor(
                        $walk,
                        $cursor === [] ? null : $cursor,
                        static fn (array $entry): array => [$entry['node']->getIndex(), $entry['node']->getIdentifier()]
                    );
                    $page = $this->readPagination->paginate(
                        $walk,
                        $limit,
                        static fn (array $entry): array => [$entry['node']->getIndex(), $entry['node']->getIdentifier()]
                    );
                    $subtree = $this->buildSubtreeFromWalk($page['nodes'], $limitToPropertyNames);

                    $result = [
                        'uri' => self::FIND_SUBTREE_URI,
                        'name' => 'Page subtree',
                        'description' => 'A hierarchical subtree with level, node and children each.',
                        'mimeType' => 'application/json',
                        'returnedCount' => $page['returnedCount'],
                        'truncated' => $page['truncated'],
                        ReadPagination::CURSOR_KEY => $page[ReadPagination::CURSOR_KEY],
                        'text' => \json_encode($subtree),
                    ];
                } catch (\Throwable $t) {
                    $result = [
                        'success' => false,
                        'message' => $t->getMessage(),
                    ];
                    return;
                }
            }
        );

        return $result;
    }

    /**
     * Resolves a node aggregate id, failing with a readable message instead of letting a null node
     * reach the content repository.
     */
    private function requireNode(ContentContext $contentContext, string $nodeAggregateId): Node
    {
        $node = $contentContext->getNodeByIdentifier($nodeAggregateId);
        if (!$node instanceof Node) {
            throw new \RuntimeException('No node found for nodeAggregateId ' . $nodeAggregateId . '.');
        }

        return $node;
    }

    /**
     * @param ?array<int,string> $limitToPropertyNames
     * @return array<string,mixed>
     */
    private function serializeNode(Node $node, ?array $limitToPropertyNames): array
    {
        return [
            'aggregateId' => $node->getIdentifier(),
            'label' => $node->getLabel(),
            'properties' => array_map(
                fn (mixed $value): mixed => $this->serializePropertyValue($value),
                array_filter(
                    iterator_to_array($node->getProperties()),
                    fn (string $propertyName) => !$limitToPropertyNames || in_array(
                            $propertyName,
                            $limitToPropertyNames
                        ),
                    ARRAY_FILTER_USE_KEY
                )
            ),
            'nodeTypeName' => $node->getNodeTypeName(),
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
     * Walks the subtree of a node and returns the nodes in the order the walk visited them.
     *
     * The walk is flattened first and the tree is rebuilt from the flat list afterwards, so that
     * the cursor and the limit mean the same thing here as in the list shaped reads: a position
     * in the ordered sequence of the nodes below the entry node.
     *
     * The children are read through the repository and not through Node::getChildNodes(): that
     * one reads from the first level node cache, which can hold an empty result for a path that
     * does have children - the tool then reports a node as childless even though it is not.
     *
     * @param array<int,array{node: Node, level: int}> $walk
     * @param int $level
     * @param int $maximumLevels
     * @return array<int,array{node: Node, level: int}>
     */
    private function collectSubtreeWalk(
        ContentContext $contentContext,
        Node $currentNode,
        string $nodeTypeFilter,
        int $level,
        int $maximumLevels,
        array $walk,
    ): array {
        $walk[] = ['node' => $currentNode, 'level' => $level];

        if ($level >= $maximumLevels) {
            return $walk;
        }

        foreach ($this->readPagination->findChildren($contentContext, $currentNode, $nodeTypeFilter) as $childNode) {
            /** @var Node $childNode */
            $walk = $this->collectSubtreeWalk(
                contentContext: $contentContext,
                currentNode: $childNode,
                nodeTypeFilter: $nodeTypeFilter,
                level: $level + 1,
                maximumLevels: $maximumLevels,
                walk: $walk,
            );
        }

        return $walk;
    }

    /**
     * Rebuilds the tree from the flat walk.
     *
     * The shape is the one the tool has always returned: a single object with level, node and
     * children. When the cursor cut the walk in the middle, the first node that is left becomes
     * the root of what is returned.
     *
     * @param array<int,array{node: Node, level: int}> $walk
     * @param ?array<int,string> $limitToPropertyNames
     * @return array<string,mixed>|null
     */
    private function buildSubtreeFromWalk(array $walk, ?array $limitToPropertyNames): ?array
    {
        if ($walk === []) {
            return null;
        }

        $entries = \array_values($walk);
        $position = 0;

        /**
         * Reads one node and everything below it. The walk is ordered and every entry knows its
         * level, so the entries that follow a node and are deeper than it are its children -
         * and the children of those follow in turn.
         */
        $build = function () use (&$build, &$position, $entries, $limitToPropertyNames): array {
            $entry = $entries[$position];
            $position++;

            $subtree = [
                'level' => $entry['level'],
                'node' => $this->serializeNode($entry['node'], $limitToPropertyNames),
                'children' => [],
            ];

            while ($position < \count($entries) && $entries[$position]['level'] > $entry['level']) {
                $subtree['children'][] = $build();
            }

            return $subtree;
        };

        return $build();
    }

    private function resolveSingleValue(?string $value): ?string
    {
        if (!$value) {
            return null;
        }
        $value = \urldecode($value);

        if ($value === '*') {
            return null;
        }

        return $value;
    }

    /**
     * @return ?array<int,string>
     */
    private function resolveListValue(string $limitToPropertyNames): ?array
    {
        $limitToPropertyNames = \urldecode($limitToPropertyNames);
        if ($limitToPropertyNames === '*') {
            return null;
        }

        $jsonDecodedValue = \json_decode($limitToPropertyNames, true);
        if ($jsonDecodedValue !== null) {
            return $jsonDecodedValue;
        }

        return Arrays::trimExplode(',', $limitToPropertyNames);
    }

    /**
     * Normalizes the nodeTypeNames query parameter into a list of single node type names.
     *
     * Accepts a single name ("Neos.Neos:Document"), a comma separated list
     * ("Neos.Neos:Document,Neos.Neos:Content"), a JSON array ('["A","B"]') and
     * tolerates surrounding whitespace. An asterisk (*) or an empty value means
     * "any node type" and therefore resolves to an empty list.
     *
     * @return array<int,string>
     */
    private function resolveNodeTypeNames(?string $nodeTypeNames): array
    {
        $nodeTypeNames = $this->resolveSingleValue($nodeTypeNames);
        if ($nodeTypeNames === null) {
            return [];
        }

        $trimmedValue = \trim($nodeTypeNames);
        if (\str_starts_with($trimmedValue, '[')) {
            $jsonDecodedValue = \json_decode($trimmedValue, true);
            if (\is_array($jsonDecodedValue)) {
                $nodeTypeNames = \implode(',', \array_map('strval', $jsonDecodedValue));
            }
        }

        $result = [];
        foreach (Arrays::trimExplode(',', $nodeTypeNames) as $nodeTypeName) {
            $nodeTypeName = \trim($nodeTypeName);
            if ($nodeTypeName !== '' && !\in_array($nodeTypeName, $result, true)) {
                $result[] = $nodeTypeName;
            }
        }

        return $result;
    }

}
