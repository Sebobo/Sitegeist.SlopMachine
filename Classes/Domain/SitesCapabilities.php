<?php

declare(strict_types=1);

namespace Sitegeist\SlopMachine\Domain;

use Mcp\Capability\Attribute\McpResourceTemplate;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\ToolAnnotations;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Security\Context;
use Neos\Neos\Domain\Model\Domain;
use Neos\Neos\Domain\Model\Site;
use Neos\Neos\Domain\Repository\SiteRepository;
use Neos\Neos\Domain\Service\ContentContext;

#[Flow\Proxy(false)]
class SitesCapabilities
{
    public const SITES_LIST_URI = 'sites://list/{dimensionSpacePoint}';
    public const SITE_URLS_URI = 'sites://urls/{dimensionSpacePoint}';

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
        description: 'Search available Neos sites and return search results with IDs that can be used for subsequent operations.
            Call the dimensionspace tool to obtain a dimension space point that this installation allows and pass it URL-encoded; do not invent dimension names or values.
            To also get the URLs, enabled status and domains of the sites, use list-site-urls.
            This tool is read-only and does not modify anything.',
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
            description: 'URL-encoded JSON dimension space point. Get a valid one from the dimensionspace tool; if this installation has no content dimensions, use %7B%7D.',
        )]
        string $dimensionSpacePoint,
    ): array {
        $sites = [];
        $failure = $this->read($dimensionSpacePoint, function (ContentContext $contentContext) use (&$sites): void {
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
        });
        if ($failure !== null) {
            return $failure;
        }

        return [
            'uri' => 'sites://list/' . \rawurlencode(\json_encode(\json_decode(\urldecode($dimensionSpacePoint), true) ?: [], JSON_THROW_ON_ERROR)),
            'name' => 'Sites',
            'description' => 'A list of sites. The nodeAggregateId identifies the site\'s root node in the subgraph.',
            'mimeType' => 'application/json',
            'text' => \json_encode($sites, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    #[McpResourceTemplate(
        uriTemplate: self::SITE_URLS_URI,
        name: 'list-site-urls',
        description: 'The sites of this installation with their enabled status, their domains and the primary domain of each site, including the absolute URL to open a site under.',
    )]
    /** Also exposed as an MCP search tool so that it can be used by chat clients */
    #[McpTool(
        name: 'list-site-urls',
        description: 'A list of the sites of this installation, each with the node aggregate id of its root node, whether it is enabled, all of its domains with the absolute URL of every domain, and which domain is the primary one.
            Use this when you need to know where a site can be reached, for example to check a published page in a browser or to tell a human which URL to look at.
            Turn a node path into a URL by dropping the /sites/<siteNodeName> prefix and appending the rest to the domain URL: for the site "alwe" with the node path /sites/alwe/impressum the URL is https://<primary domain URL>/impressum.
            Prefer the primary domain. The other domains of a site are aliases, staging hosts or hostnames that are not routed in production.
            enabled is false when the site is taken offline, and a domain with active false is not routed at all, so such a site cannot be opened in a browser.
            These URLs serve the published content. Writes you make land in a workspace that is not published, so opening a URL shows what is live, not what you just changed. Use this to check published content, never to verify your own writes.
            Pass siteNodeName to get a single site instead of all of them.
            Call the dimensionspace tool to obtain a dimension space point that this installation allows and pass it URL-encoded; do not invent dimension names or values.
            This tool is read-only and does not modify anything.',
        annotations: new ToolAnnotations(
            readOnlyHint: true,
            destructiveHint: false,
            idempotentHint: true,
            openWorldHint: false,
        )
    )]
    public function listUrls(
        #[Schema(
            type: 'string',
            description: 'URL-encoded JSON dimension space point. Get a valid one from the dimensionspace tool; if this installation has no content dimensions, use %7B%7D.',
        )]
        string $dimensionSpacePoint,
        #[Schema(
            type: 'string',
            description: 'Return only the site with this node name, for example "alwe". Omit it to get all sites.',
        )]
        ?string $siteNodeName = null,
    ): array {
        $sites = [];
        $failure = $this->read(
            $dimensionSpacePoint,
            function (ContentContext $contentContext) use (&$sites, $siteNodeName): void {
                $this->securityContext->withoutAuthorizationChecks(function () use (&$sites, $contentContext, $siteNodeName) {
                    foreach ($this->siteRepository->findAll() as $site) {
                        /** @var Site $site */
                        if ($siteNodeName !== null && $siteNodeName !== '' && $site->getNodeName() !== $siteNodeName) {
                            continue;
                        }
                        $siteNode = $contentContext->getNode('/sites/' . $site->getNodeName());
                        if ($siteNode === null) {
                            // The site is configured, but its root node is not in the workspace that is
                            // being addressed. Reporting it without an id would invite writes against a
                            // node that cannot be addressed, so it is left out.
                            continue;
                        }
                        $sites[] = $this->describeSite($site, $siteNode->getIdentifier());
                    }
                });
            }
        );
        if ($failure !== null) {
            return $failure;
        }

        if ($siteNodeName !== null && $siteNodeName !== '' && $sites === []) {
            return [
                'success' => false,
                'message' => 'No site with the node name "' . $siteNodeName . '" exists in this installation.'
                    . ' Call the tool without siteNodeName to get the node names of all sites.',
            ];
        }

        return [
            'uri' => 'sites://urls/' . \rawurlencode(\json_encode(\json_decode(\urldecode($dimensionSpacePoint), true) ?: [], JSON_THROW_ON_ERROR)),
            'name' => 'Site URLs',
            'description' => 'The sites of this installation with their enabled status, their domains, the primary domain of each site and the absolute URL of every domain. The URLs serve the published content, not the content of a workspace.',
            'mimeType' => 'application/json',
            'text' => \json_encode($sites, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function describeSite(Site $site, string $nodeAggregateId): array
    {
        $primaryDomain = $site->getPrimaryDomain();
        $domains = [];
        /** @var Domain $domain */
        foreach ($site->getDomains() as $domain) {
            $domains[] = [
                'hostname' => $domain->getHostname(),
                'scheme' => $domain->getScheme(),
                'port' => $domain->getPort(),
                'active' => (bool)$domain->getActive(),
                'primary' => $primaryDomain !== null
                    && $domain->getHostname() === $primaryDomain->getHostname()
                    && $domain->getPort() === $primaryDomain->getPort(),
                'url' => $this->buildUrl($domain),
            ];
        }

        return [
            'name' => $site->getName(),
            'nodeName' => $site->getNodeName(),
            'nodeAggregateId' => $nodeAggregateId,
            'enabled' => $site->isOnline(),
            'state' => $site->isOnline() ? 'online' : 'offline',
            'primaryDomainUrl' => $primaryDomain instanceof Domain ? $this->buildUrl($primaryDomain) : null,
            'domains' => $domains,
        ];
    }

    /**
     * Builds the absolute URL a domain is reachable under.
     *
     * A site is served from the root of its host, so the URL is the origin alone and a node path is
     * appended to it. A domain without an explicit scheme is reported with https, which is what a
     * public site is reached with; the configured scheme is reported unchanged next to it, so a
     * domain that is really only served over http stays visible as such.
     */
    private function buildUrl(Domain $domain): string
    {
        $scheme = $domain->getScheme() ?: 'https';
        $port = $domain->getPort();

        return $scheme . '://' . $domain->getHostname() . ($port !== null ? ':' . $port : '');
    }

    /**
     * Decodes the dimension space point, builds the content context and runs the reader against it.
     *
     * Everything that can fail with a bad dimension space point - an unknown dimension, a value
     * this installation does not have, malformed JSON - fails here, so it is turned into the
     * success/message shape the other capabilities use instead of surfacing as an opaque error of
     * the tool call.
     *
     * @param callable(ContentContext):void $reader
     * @return array<string,mixed>|null The failure payload, or null when the reader ran
     */
    private function read(string $dimensionSpacePoint, callable $reader): ?array
    {
        try {
            $decoded = \json_decode(\urldecode($dimensionSpacePoint), true, 512, JSON_THROW_ON_ERROR);
            $reader($this->contentContextFactory->getReadingContentContext(\is_array($decoded) ? $decoded : []));
        } catch (\Throwable $throwable) {
            return [
                'success' => false,
                'message' => $throwable->getMessage(),
            ];
        }

        return null;
    }
}
