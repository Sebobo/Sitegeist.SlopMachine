<?php

declare(strict_types=1);

namespace Sitegeist\SlopMachine\Domain;

final class SchemaLibrary
{
    /**
     * A dimension space point, e.g. {"language":"de"}.
     *
     * The array branch of the type is what the MCP SDK hands the validator: the request body is
     * decoded with json_decode(assoc: true), which maps an empty JSON object and an empty JSON
     * array onto the very same empty PHP array, so "{}" - the correct value for an installation
     * without content dimensions - arrives as "[]" and would be rejected by a plain
     * "type: object". maxItems: 0 keeps it to that one case: a real list like ["de"] stays
     * invalid, and so does a non-string value. additionalProperties only constrains the object
     * branch and maxItems only the array one, so neither weakens the other.
     *
     * @var array<string,mixed>
     */
    public const DIMENSION_SPACE_POINT = [
        'type' => ['object', 'array'],
        'maxItems' => 0,
        'additionalProperties' => ['type' => 'string'],
    ];

    /**
     * A free-form string-keyed map of property name to value, e.g. {"title":"My title"}.
     *
     * Same array branch as {@see self::DIMENSION_SPACE_POINT}.
     *
     * @var array<string,mixed>
     */
    public const PROPERTY_VALUES = [
        'type' => ['object', 'array'],
        'maxItems' => 0,
        'additionalProperties' => true,
    ];

    /**
     * A map of reference name to a single node aggregate id or a list of them.
     *
     * Same array branch as {@see self::DIMENSION_SPACE_POINT}.
     *
     * @var array<string,mixed>
     */
    public const REFERENCES = [
        'type' => ['object', 'array'],
        'maxItems' => 0,
        'additionalProperties' => [
            'oneOf' => [
                ['type' => 'string'],
                [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
            ],
        ],
    ];

    public const CREATE_NODEAGGREGATE_WITH_NODE_SCHEMA = [
        'type' => 'object',
        'description' => 'A single CreateNodeAggregateWithNode command. Creates a new node with the given parameters.
            If you want to perform subsequent actions using the created node, you can provide its nodeAggregateId with the optional nodeAggregateId parameter. By default, UUIDs are to be used.
            Node names are strictly optional and should not be used for regular editorial nodes.
            The succeedingSiblingNodeAggregateId is optional, only use it if a position relative to the siblings is explicitly requested.
            The optional references property accepts a single or an array of nodeAggregateIds per reference name.
            References must be set via the references property, as a single node aggregate id or a list of them per reference name.
            If the references property is set, it must not be empty. If no references are to be set, omit the property completely.
            Remember that tethered children don\'t need to be created explicitly as they are created automatically created together with their parent.
            Returns the nodeAggregateId and nodeName of the created node as well as the nodeAggregateIds of the tethered descendants that were created additionally.
            Example:
                {"commands":[{"type":"CreateNodeAggregateWithNode","nodeTypeName":"Acme.Site:Document.WebPage","parentNodeAggregateId":"27ad5d9d-e8e9-4e91-aa16-e1e8719e2f37","originDimensionSpacePoint":{"language":"de"},"initialPropertyValues":{"title":"My title","uriPathSegment":"my-title"}}]}',
        'properties' => [
            'type' => [
                'type' => 'string',
                'const' => 'CreateNodeAggregateWithNode',
            ],
            'nodeTypeName' => ['type' => 'string'],
            'originDimensionSpacePoint' => self::DIMENSION_SPACE_POINT,
            'parentNodeAggregateId' => ['type' => 'string'],
            'initialPropertyValues' => self::PROPERTY_VALUES,
            'nodeAggregateId' => ['type' => 'string'],
            'succeedingSiblingNodeAggregateId' => ['type' => 'string'],
            'nodeName' => ['type' => 'string'],
            'references' => self::REFERENCES,
        ],
        'required' => [
            'type',
            'nodeTypeName',
            'originDimensionSpacePoint',
            'parentNodeAggregateId',
        ],
        'additionalProperties' => false,
    ];

    public const SET_NODE_PROPERTIES_SCHEMA = [
        'type' => 'object',
        'description' => 'A single SetNodeProperties command. References must not be set with this, use SetNodeReferences instead.',
        'properties' => [
            'type' => [
                'type' => 'string',
                'const' => 'SetNodeProperties',
            ],
            'nodeAggregateId' => ['type' => 'string'],
            'originDimensionSpacePoint' => self::DIMENSION_SPACE_POINT,
            'propertyValues' => self::PROPERTY_VALUES,
        ],
        'required' => [
            'type',
            'nodeAggregateId',
            'originDimensionSpacePoint',
            'propertyValues',
        ],
        'additionalProperties' => false,
    ];

    public const SET_NODE_REFERENCES_SCHEMA = [
        'type' => 'object',
        'description' => 'A single SetNodeReferences command. Sets references on an existing node to one or more other existing nodes.
            Use this tool for setting properties of type reference or references.
            The references parameter accepts a single or an array of nodeAggregateIds per reference name.
            NodeAggregateIds must be sent without any prefix.',
        'properties' => [
            'type' => [
                'type' => 'string',
                'const' => 'SetNodeReferences',
            ],
            'nodeAggregateId' => ['type' => 'string'],
            'originDimensionSpacePoint' => self::DIMENSION_SPACE_POINT,
            'references' => self::REFERENCES,
        ],
        'required' => [
            'type',
            'nodeAggregateId',
            'originDimensionSpacePoint',
            'references',
        ],
        'additionalProperties' => false,
    ];

    public const COMMANDS_SCHEMA = [
        'oneOf' => [
            self::CREATE_NODEAGGREGATE_WITH_NODE_SCHEMA,
            self::SET_NODE_PROPERTIES_SCHEMA,
            self::SET_NODE_REFERENCES_SCHEMA,
        ],
        'discriminator' => [
            'propertyName' => 'type'
        ]
    ];
}
