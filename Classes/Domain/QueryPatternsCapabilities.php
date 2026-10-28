<?php

declare(strict_types=1);

namespace Sitegeist\SlopMachine\Domain;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\ToolAnnotations;
use Neos\Flow\Annotations as Flow;

#[Flow\Proxy(false)]
class QueryPatternsCapabilities
{
    public const URI = 'guides://query-patterns';

    /**
     * @return array<string,mixed>
     */
    #[McpTool(
        name: 'query-patterns',
        description: 'Machine-readable guidance for reliable MCP content queries.',
        annotations: new ToolAnnotations(
            readOnlyHint: true,
            destructiveHint: false,
            idempotentHint: true,
            openWorldHint: false,
        )
    )]
    public function get(): array
    {
        $payload = [
            'version' => '1.0.0',
            'name' => 'MCP Query Reliability Patterns',
            'rules' => [
                [
                    'id' => 'encode-dimension-space-point',
                    'requirement' => 'always',
                    'message' => 'Dimension space point must be URL-encoded JSON, and it must be a point this installation actually allows. Call the dimensionspace tool once before your first query and pass one of the points it reports; do not invent dimension names or values. If the installation defines no content dimensions, the only valid point is an empty object ({}).',
                ],
                [
                    'id' => 'prefer-children-over-descendants',
                    'requirement' => 'prefer',
                    'message' => 'Use find-children before find-descendants when parent is known.',
                ],
                [
                    'id' => 'prefer-scoped-tools-over-recursive-scans',
                    'requirement' => 'always',
                    'message' => 'Use find-content for the content of one document and find-documents for the documents below it, instead of find-descendants. A document owns content of its own and it may also parent other documents, and each of those brings its own content with it - the recursive query mixes both, so on a page with child pages it returns the content of every one of them. find-content returns the content of the document itself, find-documents the documents below it.',
                ],
                [
                    'id' => 'paginate-with-cursor',
                    'requirement' => 'always',
                    'message' => 'Every read is capped. When a result reports truncated, send the nextCursor it reports back as the cursor parameter to get the next page. The cursor is a best effort resume position, not a snapshot: nodes that are added to or removed from the subtree in between can change what the next page holds.',
                ],
                [
                    'id' => 'use-property-limits',
                    'requirement' => 'always',
                    'message' => 'Use limitToPropertyNames to reduce payload size.',
                ],
                [
                    'id' => 'avoid-wide-descendant-scans',
                    'requirement' => 'avoid',
                    'message' => 'Avoid broad descendant scans on high-level roots - they walk the content of every document below the node. Use find-content and find-documents for a scoped answer.',
                ],
                [
                    'id' => 'bulk-writing-command-format',
                    'requirement' => 'always',
                    'message' => 'BulkWriting commands must be objects, not JSON-encoded strings.',
                ],
                [
                    'id' => 'schema-usage',
                    'requirement' => 'always',
                    'message' => 'All MCP resources and tools provide a strict schema that is both enforced and completely declared. Always use these schemas when calling the MCP API, never use trial and error.',
                ],
                [
                    'id' => 'linking',
                    'requirement' => 'always',
                    'message' => 'Always set link properties to node://<aggregateId>. Never use path URLs. This also includes handling of inline links (e.g. <a href="node://4fe8f2f2-8a3d-4062-8b46-aaf22f25d832">Some link text</a>)',
                ],
            ],
            'parameterFormats' => [
                [
                    'parameter' => 'dimensionSpacePoint',
                    'format' => 'object-or-urlencoded-json-object',
                    'description' => 'A dimension space point as reported by the dimensionspace tool. Both the object form ({language: "de"}, or {} for an installation with no content dimensions) and the URL-encoded JSON string form (%7B%22language%22%3A%22de%22%7D) are accepted everywhere; the URL-encoded form is deprecated but kept for compatibility with the read and site tools. The examples below are illustrations of the encoding only - they are not values this installation necessarily has.',
                    'exampleRaw' => '{"language":"de"}',
                    'exampleEncoded' => '%7B%22language%22%3A%22de%22%7D',
                    'exampleWhenNoDimensions' => [
                        'exampleRaw' => '{}',
                        'exampleEncoded' => '%7B%7D',
                    ],
                ],
                [
                    'parameter' => 'nodeTypeNames',
                    'format' => 'single-value-or-comma-separated-or-asterisk',
                    'exampleRaw' => 'Neos.Neos:ContentCollection,Neos.Neos:Content',
                    'exampleEncoded' => 'Neos.Neos%3AContentCollection%2CNeos.Neos%3AContent',
                ],
                [
                    'parameter' => 'limitToPropertyNames',
                    'format' => 'comma-separated-or-json-array-or-asterisk',
                    'exampleRaw' => 'title,uriPathSegment',
                    'exampleEncoded' => 'title,uriPathSegment',
                ],
                [
                    'parameter' => 'searchTerm',
                    'format' => 'single-value-or-asterisk',
                    'exampleRaw' => 'Easter',
                    'exampleEncoded' => 'Easter',
                ],
            ],
            'fallbackSequenceOnReadError' => [
                'reduce-limitToPropertyNames',
                'switch-find-descendants-to-find-content-if-document-known',
                'switch-find-descendants-to-find-documents-if-node-known',
                'switch-find-descendants-to-find-children-if-parent-known',
            ],
            'knownGoodDefaults' => [
                'nodeTypeName' => '*',
                'limitToPropertyNames' => 'title,name,abstract,description,uriPathSegment',
                'searchTerm' => '*',
                'limit' => 200,
            ],
        ];

        return [
            'uri' => self::URI,
            'name' => 'Query patterns',
            'description' => 'Machine-readable checklist and templates for robust MCP queries.',
            'mimeType' => 'application/json',
            'text' => \json_encode($payload),
        ];
    }
}
