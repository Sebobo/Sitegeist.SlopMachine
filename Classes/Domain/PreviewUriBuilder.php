<?php

declare(strict_types=1);

namespace Sitegeist\SlopMachine\Domain;

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Core\Bootstrap;
use Neos\Flow\Http\HttpRequestHandlerInterface;
use Neos\Flow\Mvc\Routing\Dto\ResolveContext;
use Neos\Flow\Mvc\Routing\Dto\RouteParameters;
use Neos\Flow\Mvc\Routing\RouterInterface;
use Neos\Neos\Domain\Model\Domain;
use Neos\Neos\Domain\Model\Site;
use Neos\Neos\Domain\Repository\SiteRepository;
use Psr\Http\Message\UriInterface;

/**
 * The URI of the preview of a document.
 *
 * The preview is loaded from the domain of the site the document belongs to: the frontend finds
 * its site by the host of the request, so a preview that is loaded from the domain of another
 * site shows the page of that other site - or no page at all.
 *
 * The preview is a frontend request and it needs an authenticated backend user: the agent
 * authenticates with its token, and the token is what makes the frontend render the workspace
 * instead of answering with the login page.
 */
#[Flow\Scope('singleton')]
class PreviewUriBuilder
{
    public function __construct(
        private readonly RouterInterface $router,
        private readonly SiteRepository $siteRepository,
        private readonly Bootstrap $bootstrap,
    ) {
    }

    /**
     * The URI of the preview of the document with the given context path - the path of a
     * document in a workspace.
     *
     * Null when the site of the document has no domain it could be reached on.
     */
    public function uriFor(string $nodePath, string $contextPath): ?string
    {
        $domain = $this->domainOf($nodePath);
        if ($domain === null) {
            return null;
        }

        // The frontend routes are resolved for the host of the site the document belongs to -
        // the frontend finds its site by the host of the request.
        $routeParameters = RouteParameters::createEmpty()
            ->withParameter('requestUriHost', $domain->getHostname());

        $resolveContext = new ResolveContext(
            new \GuzzleHttp\Psr7\Uri($this->baseUriOf($domain)),
            [
                '@package' => 'Neos.Neos',
                '@controller' => 'Frontend\\Node',
                '@action' => 'preview',
                '@format' => 'html',
                'node' => $contextPath,
            ],
            true,
            '',
            $routeParameters
        );

        return (string)$this->router->resolve($resolveContext);
    }

    /**
     * The domain the site of the document is reached on: the primary domain of the site, or the
     * first domain of the site that is active.
     *
     * Null when the site has no domain it could be reached on.
     */
    private function domainOf(string $nodePath): ?Domain
    {
        $site = $this->siteRepository->findOneByNodeName($this->siteNodeNameOf($nodePath));
        if (!$site instanceof Site) {
            return null;
        }

        $primaryDomain = $site->getPrimaryDomain();
        if ($primaryDomain instanceof Domain && $primaryDomain->getActive()) {
            return $primaryDomain;
        }

        $domain = $site->getFirstActiveDomain();

        return $domain instanceof Domain && $domain->getHostname() !== '' ? $domain : null;
    }

    /**
     * The node name of the site a node belongs to: the third segment of its path, the path of a
     * node below a site root being /sites/<site>/<node>.
     */
    private function siteNodeNameOf(string $nodePath): string
    {
        $pathParts = explode('/', $nodePath);
        if (count($pathParts) <= 3) {
            return '';
        }

        return $pathParts[2];
    }

    /**
     * The base URI of a domain.
     *
     * The scheme of the domain is used when the domain has one - a site that is only reachable
     * over https is not reached over http just because the request is. Without a scheme the
     * scheme of the request is used.
     */
    private function baseUriOf(Domain $domain): string
    {
        $port = $domain->getPort();

        return \sprintf(
            '%s://%s%s',
            $domain->getScheme() ?: $this->currentScheme(),
            $domain->getHostname(),
            $port && !\in_array($port, [80, 443], true) ? ':' . $port : ''
        );
    }

    /**
     * The scheme of the request the agent sends the preview request with.
     */
    private function currentScheme(): string
    {
        $requestHandler = $this->bootstrap->getActiveRequestHandler();
        if (!$requestHandler instanceof HttpRequestHandlerInterface) {
            return 'https';
        }

        return $requestHandler->getHttpRequest()->getUri()->getScheme() ?: 'https';
    }
}
