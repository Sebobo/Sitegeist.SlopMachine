<?php

declare(strict_types=1);

namespace Sitegeist\SlopMachine\Domain;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\ToolAnnotations;
use Neos\ContentRepository\Domain\Service\ContentDimensionCombinator;
use Neos\Flow\Annotations as Flow;
use Mcp\Capability\Attribute\McpResource;

#[Flow\Proxy(false)]
class DimensionSpaceCapabilities
{
    public function __construct(
        protected ContentDimensionCombinator $contentDimensionCombinator,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    #[McpResource(
        uri: 'dimensionspace://show',
        name: 'dimensionspace',
        description: 'A list of all dimension space points that this installation allows, as objects. Content can be varied in across multiple dimensions. Each allowed combination of values of such dimensions is called a dimension space point. Call this once before your first query: it is the only authoritative list, and the other tools expect one of the points it reports, URL-encoded. If this installation defines no content dimensions, the list is a single empty object, {}.',
    )]
    /** Also exposed as an MCP search tool so that it can be used by chat clients */
    #[McpTool(
        name: 'dimensionspace',
        description: 'A list of all dimension space points that this installation allows, as objects. Content can be varied in across multiple dimensions. Each allowed combination of values of such dimensions is called a dimension space point. Call this once before your first query: it is the only authoritative list, and the other tools expect one of the points it reports, URL-encoded. If this installation defines no content dimensions, the list is a single empty object, {}.',
        annotations: new ToolAnnotations(
            readOnlyHint: true,
            destructiveHint: false,
            idempotentHint: true,
            openWorldHint: false,
        )
    )]
    public function get(): array
    {
        $dimensionSpace = $this->contentDimensionCombinator->getAllAllowedCombinations();
        $points = [];
        foreach ($dimensionSpace as $overqualifiedDimensionSpacePoint) {
            $point = [];
            foreach ($overqualifiedDimensionSpacePoint as $dimensionName => $dimensionValue) {
                $point[$dimensionName] = \reset($dimensionValue);
            }
            // An installation without content dimensions has exactly one allowed point, the empty
            // one. Reporting it as a list containing an empty object keeps the shape the same as
            // for a dimensioned installation, where each entry is a point of that shape.
            $points[] = $point === [] ? new \stdClass() : $point;
        }

        return [
            'uri' => 'dimensionspace://show',
            'name' => 'Dimension Space',
            'description' => 'A list of the dimension space points that this installation allows. Call this before your first query and pass one of these points, URL-encoded, to the other tools. If this installation defines no content dimensions, the list holds a single empty object, {}',
            'mimeType' => 'application/json',
            'text' => \json_encode($points),
        ];
    }
}
