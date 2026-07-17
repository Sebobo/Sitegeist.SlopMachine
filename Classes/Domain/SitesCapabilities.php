<?php

declare(strict_types=1);

namespace Sitegeist\SlopMachine\Domain;

use Mcp\Capability\Attribute\McpResourceTemplate;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\ToolAnnotations;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Security\Context;
use Neos\Neos\Domain\Model\Site;
use Neos\Neos\Domain\Repository\SiteRepository;

#[Flow\Proxy(false)]
class SitesCapabilities
{
    public const SITES_LIST_URI = 'sites://list/{dimensionSpacePoint}';

    public function __construct(
        protected SiteRepository $siteRepository,
        protected MCPContentContextFactory $contentContextFactory,
        protected Context $securityContext,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    #[McpResourceTemplate(
        uriTemplate: self::SITES_LIST_URI,
        name: 'list-sites',
        description: 'A list of all available sites.',
    )]
    /** Also exposed as an MCP search tool so that it can be used by chat clients */
    #[McpTool(
        name: 'search-sites',
        description: 'Search available Neos sites and return search results with IDs that can be used for subsequent operations.',
        annotations: new ToolAnnotations(
            readOnlyHint: true,
            destructiveHint: false,
            idempotentHint: true,
            openWorldHint: false,
        )
    )]
    public function list(
        #[Schema(
            type: 'string',
            description: 'URL-encoded JSON dimension space point, for example %7B%22language%22%3A%22en%22%7D.',
        )]
        string $dimensionSpacePoint,
    ): array {
        $dimensionSpacePoint = \json_decode(\urldecode($dimensionSpacePoint), true, 512, JSON_THROW_ON_ERROR);
        $contentContext = $this->contentContextFactory->getReadingContentContext($dimensionSpacePoint);

        $sites = [];
        $this->securityContext->withoutAuthorizationChecks(function () use (&$sites, $contentContext) {
            foreach ($this->siteRepository->findAll() as $site) {
                /** @var Site $site */
                $siteNode = $contentContext->getNode('/sites/' . $site->getNodeName());
                if ($siteNode) {
                    $sites[] = [
                        'name' => $site->getName(),
                        'nodeAggregateId' => $siteNode->getIdentifier(),
                    ];
                }
            }
        });

        return [
            'uri' => 'sites://list/' . \rawurlencode(\json_encode($dimensionSpacePoint, JSON_THROW_ON_ERROR)),
            'name' => 'Sites',
            'description' => 'A list of sites. The nodeAggregateId identifies the site\'s root node in the subgraph.',
            'mimeType' => 'application/json',
            'text' => $sites,
        ];
    }
}
