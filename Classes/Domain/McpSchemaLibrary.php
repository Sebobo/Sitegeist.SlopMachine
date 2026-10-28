<?php

declare(strict_types=1);

namespace Sitegeist\SlopMachine\Domain;

/**
 * Reusable JSON schema fragments for the MCP capabilities in this package.
 *
 * The write commands themselves are deliberately not repeated here: the shared workspace tools
 * reuse SchemaLibrary::COMMANDS_SCHEMA, so an agent that has
 * learned BulkWriting can issue byte-identical commands against the review workspace.
 */
final class McpSchemaLibrary
{
    /**
     * A dimension space point, e.g. {"language":"de"}.
     *
     * This installation runs without content dimensions, so an empty object is a valid and
     * complete value here - but the capabilities always accept the object shape the
     * `dimensionspace` tool hands out rather than the url encoded JSON string the read-only
     * resources use in their URI templates.
     *
     * The array branch of the type is what the MCP SDK hands the validator: the request body is
     * decoded with json_decode(assoc: true), which maps an empty JSON object and an empty JSON
     * array onto the very same empty PHP array, so "{}" arrives as "[]" and would be rejected by
     * a plain "type: object". maxItems: 0 keeps it to that one case - a real list like ["de"]
     * stays invalid, and so does a non-string dimension value. additionalProperties only
     * constrains the object branch and maxItems only the array one, so neither weakens the other.
     *
     * The type is an array rather than a oneOf because the SDK merges the type it infers from
     * the PHP signature ("array") into the same schema node, and a sibling "type" keyword would
     * reject the object form. maxItems is set for the same reason.
     *
     * @var array<string,mixed>
     */
    public const DIMENSION_SPACE_POINT = [
        'type' => ['object', 'array', 'string'],
        'maxItems' => 0,
        'additionalProperties' => ['type' => 'string'],
    ];

    /**
     * The method level schema for a tool whose `dimensionSpacePoint` parameter is described
     * individually on the parameter itself: it contributes nothing but the shape above, every
     * other parameter of the tool is merged in from its own #[Schema].
     *
     * A method level one is needed because the Mcp\Capability\Attribute\Schema attribute cannot
     * give a parameter a union type. This is a constant rather than a helper method because
     * attribute arguments have to be constant expressions.
     *
     * @var array<string,mixed>
     */
    public const DIMENSION_SPACE_POINT_TOOL_SCHEMA = [
        'properties' => [
            'dimensionSpacePoint' => self::DIMENSION_SPACE_POINT,
        ],
    ];

    /**
     * @var array{type: string, items: array{type: string}, minItems: int}
     */
    public const NODE_AGGREGATE_IDS = [
        'type' => 'array',
        'items' => ['type' => 'string'],
        'minItems' => 1,
    ];

    /**
     * The cursor of a paged read: the position to resume from.
     *
     * It is an object with the index of the last node of the previous page and its aggregate id.
     * The empty form - an empty object or an empty array - means "start at the beginning", which
     * is also what a caller sends when it does not want to continue a page.
     *
     * The type is a union for the same reason as the dimension space point: the SDK merges the
     * type it infers from the PHP signature ("array") into the same schema node, and a sibling
     * "type" keyword would reject the object form. An empty PHP array is encoded as a JSON array
     * rather than as an empty object, so the array form has to be accepted as well.
     *
     * @var array<string,mixed>
     */
    public const CURSOR = [
        'type' => ['object', 'array'],
        'additionalProperties' => ['type' => ['string', 'integer']],
    ];

    /**
     * The method level schema of a read tool that can be paged.
     *
     * The cursor needs a union type, and the Schema attribute only takes a single type string at
     * the parameter level - the union has to come from the method level, which is where the
     * dimension space point gets its union type from as well.
     *
     * @var array<string,mixed>
     */
    public const PAGED_READ_TOOL_SCHEMA = [
        'properties' => [
            'dimensionSpacePoint' => self::DIMENSION_SPACE_POINT,
            'cursor' => self::CURSOR,
        ],
    ];

    private function __construct()
    {
    }
}
