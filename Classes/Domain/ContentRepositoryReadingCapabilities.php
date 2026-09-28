<?php

declare(strict_types=1);

namespace Sitegeist\SlopMachine\Domain;

use Mcp\Capability\Attribute\McpResourceTemplate;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\ToolAnnotations;
use Neos\ContentRepository\Domain\Model\Node;
use Neos\ContentRepository\Domain\Repository\NodeDataRepository;
use Neos\Eel\FlowQuery\FlowQuery;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Security\Context;
use Neos\Neos\Domain\Service\ContentContext;
use Neos\Neos\Domain\Service\NodeSearchServiceInterface;
use Neos\Utility\Arrays;

#[Flow\Proxy(false)]
class ContentRepositoryReadingCapabilities
{
    public const FIND_CHILDREN_URI = 'contentsubgraph://find-children/{dimensionSpacePoint}/{parentNodeAggregateId}/{nodeTypeNames}/{limitToPropertyNames}';
    public const FIND_DESCENDANTS_URI = 'contentsubgraph://find-descendants/{dimensionSpacePoint}/{ancestorNodeAggregateId}/{nodeTypeNames}/{searchTerm}/{limitToPropertyNames}';
    public const FIND_SUBTREE_URI = 'contentsubgraph://find-subtree/{dimensionSpacePoint}/{entryNodeAggregateId}/{nodeTypeNames}/{maximumLevels}/{limitToPropertyNames}';

    public function __construct(
        protected MCPContentContextFactory $contentContextFactory,
        protected Context $securityContext,
        protected NodeSearchServiceInterface $nodeSearchService,
        protected NodeDataRepository $nodeDataRepository,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    #[McpResourceTemplate(
        uriTemplate: self::FIND_CHILDREN_URI,
        name: 'find-children',
        description: 'A list of all available child nodes of a given parent that are of a given type.
            This is a rather efficient query; use this if you already know the parent under which you want to search.
            The nodeTypeNames parameter accepts a single node type or a comma separated list of node types, for example "Neos.Neos:Document,Neos.Neos:Content". A node type also matches everything that extends it, so "Neos.Neos:Document" returns pages and other document types as well. Listing a type together with one of its super types returns every matching node exactly once, never twice.
            Call the dimensionspace tool to obtain a dimension space point that this installation allows and pass it URL-encoded; do not invent dimension names or values.
            To reduce response size, you can limit the returned properties to the list of given names in the parameter limitToPropertyNames.
            To skip a parameter, provide an asterisk (*) as value.
            The result is a list in which every node appears once, containing the direct children of the given node only, not the whole subtree.
            The returned data describes the current state of the content graph and does not give any reliable information on structural constraints.',
        meta: [
            'purpose' => 'content search'
        ],
    )]
    /** Also exposed as an MCP search tool so that it can be used by chat clients */
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
    public function findChildren(
        string $dimensionSpacePoint,
        string $parentNodeAggregateId,
        string $nodeTypeNames,
        string $limitToPropertyNames,
    ): array {
        $result = [];
        $this->securityContext->withoutAuthorizationChecks(
            function() use(
                $nodeTypeNames,
                $dimensionSpacePoint,
                $parentNodeAggregateId,
                $limitToPropertyNames,
                &$result,
            ) {
                try {
                    $dimensionSpacePoint = \json_decode(\urldecode($dimensionSpacePoint), true, 512, JSON_THROW_ON_ERROR);
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
                    $foundNodes = $this->nodeDataRepository->findByParentAndNodeTypeInContext(
                        $ancestorNode->getPath(),
                        $nodeTypeNames === [] ? null : \implode(',', $nodeTypeNames),
                        $contentContext,
                        false
                    );
                    $nodes = [];
                    foreach ($foundNodes as $node) {
                        /** @var Node $node */
                        $nodes[] = $this->serializeNode($node, $limitToPropertyNames);
                    }

                    $result = [
                        'uri' => self::FIND_CHILDREN_URI,
                        'name' => 'Child nodes',
                        'description' => 'A list of nodes. The aggregateId identifies a node in the subgraph. The properties field contains the current state of the properties of that node. The nodeTypeName field contains the name of the type of that node, for more information see the ' . NodeTypeSchemaCapabilities::FULL_URI . ' resource.',
                        'mimeType' => 'application/json',
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
     * @return array<string,mixed>
     */
    #[McpResourceTemplate(
        uriTemplate: self::FIND_DESCENDANTS_URI,
        name: 'find-descendants',
        description: 'A list of all available descendant nodes of a given ancestor that are of a given type and match an optional search term.
            This is a rather expensive query; use this if do not yet know the structure or the parent to search children of.
            The nodeTypeNames parameter accepts a single node type or a comma separated list of node types, for example "Neos.Neos:Document,Neos.Neos:Content". A node type also matches everything that extends it, and listing a type together with one of its super types still returns every matching node exactly once.
            Without a search term the result is a list with the whole subtree below the given node - the given node itself is not part of it - in which every node appears once. With a search term the result is an object keyed by node path instead, because a search term is not restricted to the subtree; check which of the two shapes you got before parsing it.
            A search term is matched against node properties across the workspace, so it can return nodes outside the subtree. Pass an asterisk (*) to walk the subtree structurally instead.
            The result is not capped: a broad nodeTypeNames filter combined with all properties can return a very large list. Prefer find-children or find-subtree when the parent is known, and pass a limitToPropertyNames list to keep the response small.
            Call the dimensionspace tool to obtain a dimension space point that this installation allows and pass it URL-encoded; do not invent dimension names or values.
            To reduce response size, you can limit the returned properties to the list of given names in the parameter limitToPropertyNames.
            To skip a parameter, provide an asterisk (*) as value.
            The returned data describes the current state of the content graph and does not give any reliable information on structural constraints.',
        meta: [
            'purpose' => 'content search'
        ],
    )]
    /** Also exposed as an MCP search tool so that it can be used by chat clients */
    #[McpTool(
        name: 'find-descendants',
        description: 'A list of all available descendant nodes of a given ancestor that are of a given type and match an optional search term.
            This is a rather expensive query; use this if do not yet know the structure or the parent to search children of.
            The nodeTypeNames parameter accepts a single node type or a comma separated list of node types, for example "Neos.Neos:Document,Neos.Neos:Content". A node type also matches everything that extends it, and listing a type together with one of its super types still returns every matching node exactly once.
            Without a search term the result is a list with the whole subtree below the given node - the given node itself is not part of it - in which every node appears once. With a search term the result is an object keyed by node path instead, because a search term is not restricted to the subtree; check which of the two shapes you got before parsing it.
            A search term is matched against node properties across the workspace, so it can return nodes outside the subtree. Pass an asterisk (*) to walk the subtree structurally instead.
            The result is not capped: a broad nodeTypeNames filter combined with all properties can return a very large list. Prefer find-children or find-subtree when the parent is known, and pass a limitToPropertyNames list to keep the response small.
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
    public function findDescendants(
        string $dimensionSpacePoint,
        string $ancestorNodeAggregateId,
        string $nodeTypeNames,
        string $searchTerm,
        string $limitToPropertyNames,
    ): array {
        $result = [];
        $this->securityContext->withoutAuthorizationChecks(
            function() use(
                $nodeTypeNames,
                $dimensionSpacePoint,
                $ancestorNodeAggregateId,
                $limitToPropertyNames,
                $searchTerm,
                &$result,
            ) {
                try {
                    $dimensionSpacePoint = \json_decode(\urldecode($dimensionSpacePoint), true, 512, JSON_THROW_ON_ERROR);
                    $nodeTypeNames = $this->resolveNodeTypeNames($nodeTypeNames);
                    $limitToPropertyNames = $this->resolveListValue($limitToPropertyNames);
                    $contentContext = $this->contentContextFactory->getReadingContentContext($dimensionSpacePoint);

                    if ($searchTerm = $this->resolveSingleValue($searchTerm)) {
                        /** @var Node[] $nodes */
                        $nodes = $this->nodeSearchService->findByProperties($searchTerm, $nodeTypeNames, $contentContext);
                    } elseif ($nodeTypeNames === []) {
                        /**
                         * "Any node type" cannot be expressed as a FlowQuery filter here: find()
                         * returns early on an empty filter expression and therefore leaves the
                         * context - the ancestor itself - as the result. The content repository
                         * resolves a recursive lookup under a parent path with no node type
                         * restriction, which is the query find() performs internally for a filter
                         * like "[instanceof Neos.Neos:Document]".
                         */
                        $ancestorNode = $this->requireNode($contentContext, $ancestorNodeAggregateId);
                        /** @var Node[] $nodes */
                        $nodes = $this->nodeDataRepository->findByParentAndNodeTypeInContext(
                            $ancestorNode->getPath(),
                            null,
                            $contentContext,
                            true
                        );
                    } else {
                        $ancestorNode = $this->requireNode($contentContext, $ancestorNodeAggregateId);
                        $flowQuery = new FlowQuery([$ancestorNode]);
                        /** @var Node[] $nodes */
                        $nodes = $flowQuery->find($this->buildNodeTypeFilterExpression($nodeTypeNames))->get();
                    }
                    $payload = \array_map(
                        fn (Node $node): array => $this->serializeNode($node, $limitToPropertyNames),
                        $nodes,
                    );

                    $result = [
                        'uri' => self::FIND_DESCENDANTS_URI,
                        'name' => 'Descendant nodes',
                        'description' => 'A list of nodes. The aggregateId identifies a node in the subgraph. The properties field contains the current state of the properties of that node. The nodeTypeName field contains the name of the type of that node, for more information see the ' . NodeTypeSchemaCapabilities::FULL_URI . ' resource.',
                        'mimeType' => 'application/json',
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
     * @return array<string,mixed>
     */
    #[McpResourceTemplate(
        uriTemplate: self::FIND_SUBTREE_URI,
        name: 'find-subtree',
        description: 'A hierarchical subtree for a single page only.
            Includes only nodes matching the given base node type.
            Explicitly excludes nodes under descendant document nodes (subpages).
            PRIMARY QUERY for editorial single-page content inspection when a page nodeAggregateId is known.
            To fetch all content from a document, set the node type names to `Neos.Neos:ContentCollection,Neos.Neos:Content`.
            Use find-descendants only as fallback when the page root is unknown or a cross-page search is explicitly required.
            maximumLevels bounds how deep the hierarchy is walked and costs one query per visited node, so keep it as small as the task allows.
            Call the dimensionspace tool to obtain a dimension space point that this installation allows and pass it URL-encoded; do not invent dimension names or values.
            To reduce response size, you can limit the returned properties to the list of given names in the parameter limitToPropertyNames.
            To skip a parameter, provide an asterisk (*) as value.',
        meta: [
            'purpose' => 'content search'
        ],
    )]
    /** Also exposed as an MCP search tool so that it can be used by chat clients */
    #[McpTool(
        name: 'find-subtree',
        description: 'A hierarchical subtree for a single page only.
            Includes only nodes matching the given base node type.
            Explicitly excludes nodes under descendant document nodes (subpages).
            PRIMARY QUERY for editorial single-page content inspection when a page nodeAggregateId is known.
            To fetch all content from a document, set the node type names to `Neos.Neos:ContentCollection,Neos.Neos:Content`.
            Use find-descendants only as fallback when the page root is unknown or a cross-page search is explicitly required.
            maximumLevels bounds how deep the hierarchy is walked and costs one query per visited node, so keep it as small as the task allows.
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
    public function findSubtree(
        string $dimensionSpacePoint,
        string $entryNodeAggregateId,
        string $nodeTypeNames,
        int $maximumLevels,
        string $limitToPropertyNames,
    ): array {
        $result = [];
        $this->securityContext->withoutAuthorizationChecks(
            function () use (
                $dimensionSpacePoint,
                $entryNodeAggregateId,
                $nodeTypeNames,
                $maximumLevels,
                $limitToPropertyNames,
                &$result,
            ) {
                try {
                    $dimensionSpacePoint = \json_decode(\urldecode($dimensionSpacePoint), true, 512, JSON_THROW_ON_ERROR);
                    $nodeTypeNames = $this->resolveNodeTypeNames($nodeTypeNames);
                    $limitToPropertyNames = $this->resolveListValue($limitToPropertyNames);
                    $contentContext = $this->contentContextFactory->getReadingContentContext($dimensionSpacePoint);

                    $entryNode = $contentContext->getNodeByIdentifier($entryNodeAggregateId);
                    if (!$entryNode instanceof Node) {
                        throw new \RuntimeException('entry node not found for given nodeAggregateId.');
                    }

                    $subtree = $this->collectMatchingSubtrees(
                        currentNode: $entryNode,
                        nodeTypeFilter: $nodeTypeNames === [] ? null : \implode(',', $nodeTypeNames),
                        limitToPropertyNames: $limitToPropertyNames,
                        level: 0,
                        maximumLevels: $maximumLevels,
                    );

                    $result = [
                        'uri' => self::FIND_SUBTREE_URI,
                        'name' => 'Page subtree',
                        'description' => 'A hierarchical subtree with level, node and children each.',
                        'mimeType' => 'application/json',
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

    private function serializeNode(Node $node, ?array $limitToPropertyNames): array
    {
        return [
            'aggregateId' => $node->getIdentifier(),
            'label' => $node->getLabel(),
            'properties' => array_filter(
                iterator_to_array($node->getProperties()),
                fn (string $propertyName) => !$limitToPropertyNames || in_array(
                        $propertyName,
                        $limitToPropertyNames
                    ),
                ARRAY_FILTER_USE_KEY
            ),
            'nodeTypeName' => $node->getNodeTypeName(),
        ];
    }

    /**
     * @return array{level: int, node: array<string,mixed>, children: array<int,array<string,mixed>>}
     */
    private function collectMatchingSubtrees(
        Node $currentNode,
        ?string $nodeTypeFilter,
        ?array $limitToPropertyNames,
        int $level,
        int $maximumLevels,
    ): array {
        $childSubtrees = [];
        if ($level <= $maximumLevels) {
            foreach ($currentNode->getChildNodes($nodeTypeFilter) as $childNode) {
                /** @var Node $childNode */
                $childSubtrees[] = $this->collectMatchingSubtrees(
                    currentNode: $childNode,
                    nodeTypeFilter: $nodeTypeFilter,
                    limitToPropertyNames: $limitToPropertyNames,
                    level: $level + 1,
                    maximumLevels: $maximumLevels
                );
            }
        }

        return [
            'level' => $level,
            'node' => $this->serializeNode($currentNode, $limitToPropertyNames),
            'children' => $childSubtrees,
        ];
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

    /**
     * Builds a single FlowQuery filter expression matching any of the given node type names.
     *
     * All node types are combined into one comma separated filter group, for example
     * "[instanceof Neos.Neos:Document],[instanceof Neos.Neos:Content]". The content
     * repository resolves such a group into a single query instead of one query per
     * node type.
     *
     * @param array<int,string> $nodeTypeNames
     */
    private function buildNodeTypeFilterExpression(array $nodeTypeNames): string
    {
        return '[' . \implode('],[', \array_map(
            static fn (string $nodeTypeName): string => 'instanceof ' . $nodeTypeName,
            $nodeTypeNames
        )) . ']';
    }
}
