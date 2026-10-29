<?php

declare(strict_types=1);

namespace Sitegeist\SlopMachine\Domain;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\ToolAnnotations;
use Neos\ContentRepository\Domain\Model\NodeType;
use Neos\Flow\Annotations as Flow;
use Neos\ContentRepository\Domain\Service\NodeTypeManager;

#[Flow\Proxy(false)]
class NodeTypeSchemaCapabilities
{
    public const FULL_URI = 'nodetypes://full-schema';
    public const BASIC_URI = 'nodetypes://basic-schema';

    public function __construct(
        protected NodeTypeManager $nodeTypeManager,
        protected NodePropertyValidation $nodePropertyValidation,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    #[McpTool(
        name: 'nodetype-full-schema',
        description: 'A list of available node types that can be handled by MCP clients. Results in a rather large set of data. To fetch only basic information, use `nodetype-basic-schema` instead.
            This contains all necessary structural data like
              - which node type has what properties of what type
              - which constraints regarding properties or allowed child nodes exist
              - which tethered nodes exist per node type.
            Both the structural constraints and the property validators are enforced on the write side, so this is the single source of truth for all schema validation information: a value that violates the validation of its property is rejected, not stored.',
        annotations: new ToolAnnotations(
            readOnlyHint: true,
            destructiveHint: false,
            idempotentHint: true,
            openWorldHint: false,
        )
    )]
    public function getFull(): array
    {
        $schema = [];
        foreach ($this->nodeTypeManager->getSubNodeTypes('Sitegeist.SlopMachine:Mixin.MCPExposed', false) as $nodeType) {
            $schema[$nodeType->getName()] = [
                'name' => $nodeType->getConfiguration('options.mcp.name'),
                'description' => $nodeType->getConfiguration('options.mcp.description'),
                'properties' => array_map(
                    fn (array $propertyConfiguration): array => [
                        'type' => $propertyConfiguration['type'] ?? 'string',
                        /** @todo handle i18n */
                        'label' => $propertyConfiguration['ui']['label'] ?? '',
                        'defaultValue' => $propertyConfiguration['defaultValue'] ?? '',
                        'required' => ($propertyConfiguration['validation']['Neos.Neos/Validation/NotEmptyValidator'] ?? null) !== null,
                        'validation' => $this->nodePropertyValidation->describe($propertyConfiguration),
                        'formatting' => $this->inlineFormatting($propertyConfiguration),
                        'editorOptions' => $this->inspectorEditorOptions($propertyConfiguration),
                    ],
                    array_filter(
                        $nodeType->getProperties(),
                        fn (string $propertyName): bool => !\str_starts_with($propertyName, '_'),
                        ARRAY_FILTER_USE_KEY,
                    ),
                ),
                'supertypes' => array_values(array_unique($this->resolveSuperTypeNames($nodeType))),
                'constraints' => $nodeType->getConfiguration('constraints'),
                'tetheredChildren' => $nodeType->getConfiguration('childNodes'),
            ];
        }

        return [
            'uri' => self::FULL_URI,
            'name' => 'Node Type Schema',
            'description' => 'The node types that are exposed to MCP, which is the set of node types you can address with the create and write tools.
                A node type is included when it, or one of its super types, is marked with the mixin Sitegeist.SlopMachine:Mixin.MCPExposed. Node types outside that set are not listed and should not be used; if a node type you need is missing, say so instead of guessing a name.
                The properties field defines all properties that can be set on a node of that type.
                An exception are properties of type reference or references. Those can reference other nodes instead. Single reference properties can be set to the target node\'s aggregate id. Properties of type references can be set to a list of node aggregate ids. Use the SetNodeReferences tool for writing references.
                The required flag marks properties that carry a not-empty validation, which is a hint and not a guarantee that a value will be accepted.
                The validation field states what a value of that property has to satisfy. constraints is the normalized part and the one to act on: maxLength and minLength for strings, minimum and maximum for numbers, pattern for a regular expression, format for a known format such as email, and minItems and maxItems for lists. validators lists the raw configuration behind it, each with the options of the validator and an enforced flag - a validator with enforced false could not be resolved by the server and is therefore not checked. A value that violates a constraint is rejected on write, with a message naming the property and the limit.
                The editorOptions field is what the Neos editor offers for the property, and it is not a limit: an editor may suggest 160 characters while the validator allows 230. constraints and validators are the limits, editorOptions is the guidance.
                The formatting field lists the formats the inline editor offers for a rich text property, as a name to enabled map. Such a property holds HTML, not plain text: the title of Neos.NodeTypes:Headline for example holds an <h1> to <h3> tag rather than a level in a separate property, so the tag has to be part of the value. A format that is not listed is not offered by the editor.
                The editorOptions field lists the options of the inspector editor, for example the choices of a select box or the range and unit of a number. A value outside them is not what the editor would produce.
                The constraints field defines structural restrictions the node type imposes, e.g. what type children of a node of this type must be of. A create command that violates a constraint is rejected.
                The tetheredChildren field defines which child nodes are automatically created under a new node of this type, and are addressed by their node name, not by an aggregate id.',
            'mimeType' => 'application/json',
            'text' => \json_encode($schema),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    #[McpTool(
        name: 'nodetype-basic-schema',
        description: 'A list of available node types reduced to basic information.  Results in a rather small set of incomplete data. To fetch full information, use `nodetype-full-schema` instead.
            This contains basic structural data like
              - which node type has what properties of what type
              - which property validations exist
              - which tethered nodes exist per node type.
            The structural constraints, the raw validator configuration and additional property information are excluded from this limited overview.',
        annotations: new ToolAnnotations(
            readOnlyHint: true,
            destructiveHint: false,
            idempotentHint: true,
            openWorldHint: false,
        )
    )]
    public function getBasic(): array
    {
        $schema = [];
        foreach ($this->nodeTypeManager->getSubNodeTypes('Sitegeist.SlopMachine:Mixin.MCPExposed', false) as $nodeType) {
            $schema[$nodeType->getName()] = [
                'name' => $nodeType->getConfiguration('options.mcp.name'),
                'description' => $nodeType->getConfiguration('options.mcp.description'),
                'properties' => array_map(
                    fn (array $propertyConfiguration): array => [
                        'type' => $propertyConfiguration['type'] ?? 'string',
                        'required' => ($propertyConfiguration['validation']['Neos.Neos/Validation/NotEmptyValidator'] ?? null) !== null,
                        'validation' => ['constraints' => $this->nodePropertyValidation->constraints($propertyConfiguration)],
                    ],
                    array_filter(
                        $nodeType->getProperties(),
                        fn (string $propertyName): bool => !\str_starts_with($propertyName, '_'),
                        ARRAY_FILTER_USE_KEY,
                    ),
                ),
                'tetheredChildren' => $nodeType->getConfiguration('childNodes'),
            ];
        }

        return [
            'uri' => self::FULL_URI,
            'name' => 'Node Type Schema',
            'description' => 'The list of available node types with reduced information.
                The properties field defines all properties that can be set on a node of that type.
                An exception are properties of type reference or references. Those can reference other nodes instead. Single reference properties can be set to the target node\'s aggregate id. Properties of type references can be set to a list of node aggregate ids. Use the SetNodeReferences tool for writing references.
                The validation.constraints field states the limits a value has to satisfy, for example maxLength or a regular expression pattern; a value that violates one of them is rejected on write. The raw validator configuration behind it is in the full schema.
                The tetheredChildren field defines which child nodes are automatically created under a new node of this type.',
            'mimeType' => 'application/json',
            'text' => \json_encode($schema),
        ];
    }

    /**
     * The inline editor's formatting whitelist of a property.
     *
     * A rich text property is not plain text: the editor offers a fixed set of
     * formats, and the value of such a property is HTML. The headline of
     * Neos.NodeTypes:Headline is the clearest example - its title holds an
     * `<h1>` to `<h3>` tag, not a level in a separate property. Without this
     * the whitelist is invisible to a client, and the only way to find out is to
     * read the node type configuration by hand.
     *
     * @param array<string,mixed> $propertyConfiguration
     * @return array<string,bool>|null
     */
    private function inlineFormatting(array $propertyConfiguration): ?array
    {
        $formatting = $propertyConfiguration['ui']['inline']['editorOptions']['formatting'] ?? null;
        if (!\is_array($formatting)) {
            return null;
        }
        $formats = [];
        foreach ($formatting as $format => $enabled) {
            $formats[(string)$format] = (bool)$enabled;
        }
        return $formats === [] ? null : $formats;
    }

    /**
     * The inspector editor options of a property.
     *
     * These are the values an editor can choose from - the options of a select
     * box, the unit and the range of a number, the items of a list. A client
     * that writes the property needs them to stay inside what the editor
     * offers.
     *
     * @param array<string,mixed> $propertyConfiguration
     * @return array<string,mixed>|null
     */
    private function inspectorEditorOptions(array $propertyConfiguration): ?array
    {
        $options = $propertyConfiguration['ui']['inspector']['editorOptions'] ?? null;
        if (!\is_array($options)) {
            return null;
        }
        // the placeholder is a label for the empty value, not a choice
        unset($options['placeholder']);
        return $options === [] ? null : $options;
    }

    /**
     * @return array<string>
     */
    private function resolveSuperTypeNames(NodeType $nodeType): array
    {
        $superTypeNames = [];
        foreach ($nodeType->getDeclaredSuperTypes() as $superType) {
            $superTypeNames[] = $superType->getName();
            $superTypeNames = array_merge($superTypeNames, $this->resolveSuperTypeNames($superType));
        }

        return $superTypeNames;
    }
}
