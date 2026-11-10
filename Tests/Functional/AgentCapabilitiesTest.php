<?php

declare(strict_types=1);

namespace Sitegeist\SlopMachine\Tests\Functional;

use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\Flow\Package\PackageManager;
use Sitegeist\Pandora\Capability\CapabilityDescriptors;
use Sitegeist\Pandora\Capability\PackageKeyResolver;
use Sitegeist\Pandora\Infrastructure\CapabilityHandlerContainer;
use Sitegeist\Pandora\McpServerBuilderFactory;
use Sitegeist\SlopMachine\Domain\AgentWorkspaceCapabilities;
use Sitegeist\SlopMachine\Domain\ContentRepositoryReadingCapabilities;
use Sitegeist\SlopMachine\Domain\ContentRepositoryWritingCapabilities;

/**
 * The capabilities of the agent: what it may use, and what it may not.
 *
 * The capabilities are deny-by-default - a capability that is not granted is not even visible
 * to the agent. And they run under the policies of Neos: a capability may not switch the
 * authorization checks off, because then the policies would not decide anything any more.
 */
class AgentCapabilitiesTest extends AgentTestCase
{
    public function testTheGrantedCapabilitiesAreExposed(): void
    {
        $names = $this->capabilityNames();

        self::assertContains('find-children', $names, 'The capability the role grants is exposed.');
        self::assertContains('SetNodeProperties', $names);
        self::assertContains('PreviewDocument', $names);
    }

    public function testACapabilityThatIsNotGrantedIsHidden(): void
    {
        self::assertNotContains(
            'RemoveNode',
            $this->capabilityNames(),
            'A capability that is not granted is not visible to the agent.'
        );
    }

    public function testNoCapabilityDisablesTheAuthorizationChecks(): void
    {
        $directory = \dirname((new \ReflectionClass(AgentWorkspaceCapabilities::class))->getFileName());
        $sources = \glob($directory . '/*.php');
        self::assertIsArray($sources);
        self::assertNotEmpty($sources);

        foreach ($sources as $source) {
            self::assertStringNotContainsString(
                'withoutAuthorizationChecks',
                (string)\file_get_contents($source),
                \sprintf('%s may not disable the authorization checks: the policies of Neos have to decide what the agent may do.', \basename($source))
            );
        }
    }

    public function testTheAgentMayWriteInItsOwnWorkspace(): void
    {
        $document = $this->documentIn($this->agentWorkspace);
        self::assertInstanceOf(NodeInterface::class, $document);

        $capabilities = $this->capability(ContentRepositoryWritingCapabilities::class);
        $result = $capabilities->setNodeProperties([
            'nodeAggregateId' => $document->getIdentifier(),
            'originDimensionSpacePoint' => $this->dimensionSpacePoint(),
            'propertyValues' => ['title' => 'A new title'],
        ]);

        self::assertTrue($result['structuredContent']['success'] ?? false, 'The agent writes in its own workspace.');
    }

    public function testTheAgentMayReadItsOwnWorkspace(): void
    {
        $capabilities = $this->capability(ContentRepositoryReadingCapabilities::class);
        $result = $capabilities->findChildren(
            $this->siteRootAggregateId(),
            'Neos.Neos:Document',
            'title',
            10,
            [],
            $this->dimensionSpacePoint(),
        );

        self::assertGreaterThan(0, $result['returnedCount'] ?? 0, 'The agent reads its own workspace.');
    }

    public function testAnImagePropertyCanBeRemoved(): void
    {
        $document = $this->documentIn($this->agentWorkspace);
        self::assertInstanceOf(NodeInterface::class, $document);

        $capabilities = $this->capability(ContentRepositoryWritingCapabilities::class);

        // An image element without an image - the element is created, the image is added later.
        $created = $capabilities->createNodeAggregateWithNode([
            'nodeTypeName' => self::IMAGE_NODE_TYPE,
            'parentNodeAggregateId' => $this->mainCollectionIdOf($document),
            'originDimensionSpacePoint' => $this->dimensionSpacePoint(),
            'initialPropertyValues' => ['title' => 'An image'],
        ]);
        $imageNodeId = $created['structuredContent']['nodeAggregateId'] ?? null;
        self::assertIsString($imageNodeId);

        // A null value removes the property - it is not converted into an asset.
        $result = $capabilities->setNodeProperties([
            'nodeAggregateId' => $imageNodeId,
            'originDimensionSpacePoint' => $this->dimensionSpacePoint(),
            'propertyValues' => ['image' => null],
        ]);

        self::assertTrue(
            $result['structuredContent']['success'] ?? false,
            'The image property can be removed: ' . (string)($result['structuredContent']['message'] ?? '')
        );
    }

    /**
     * A capability: the handler container builds them, because they are not proxied.
     *
     * @template T of object
     * @param class-string<T> $className
     * @return T
     */
    protected function capability(string $className): object
    {
        /** @var T $capability */
        $capability = $this->getObject(CapabilityHandlerContainer::class)->get($className);

        return $capability;
    }

    /**
     * The names of the capabilities the agent may use.
     *
     * The roles are the ones of the security context - the same set the server prunes the
     * registry with at runtime, the parent roles included.
     *
     * @return list<string>
     */
    protected function capabilityNames(): array
    {
        $roles = \array_values($this->securityContext->getRoles());
        self::assertNotEmpty($roles);

        $serverBuilderFactory = $this->getObject(McpServerBuilderFactory::class);
        $builtServer = $serverBuilderFactory->buildServerForRoles($roles);

        $descriptors = CapabilityDescriptors::createFromRegistry(
            $builtServer->registry,
            new PackageKeyResolver($this->getObject(PackageManager::class))
        );

        return \array_map(
            static fn ($descriptor): string => $descriptor->name,
            \iterator_to_array($descriptors)
        );
    }

    /**
     * The aggregate id of the root node of the site of the tests.
     */
    protected function siteRootAggregateId(): string
    {
        return $this->siteRoot()->getIdentifier();
    }

    /**
     * The root node of the site of the tests.
     */
    protected function siteRoot(): NodeInterface
    {
        $context = $this->contextFactory->create([
            'workspaceName' => $this->agentWorkspace->getName(),
            'dimensions' => $this->dimensions(),
        ]);
        $siteNode = $context->getNode('/sites/' . $this->siteNodeName);
        self::assertInstanceOf(NodeInterface::class, $siteNode);

        return $siteNode;
    }

    /**
     * The aggregate id of the content collection of a document - the "main" collection, where
     * its content lives.
     */
    protected function mainCollectionIdOf(NodeInterface $document): string
    {
        $main = $document->getNode('main');
        self::assertInstanceOf(NodeInterface::class, $main);

        return $main->getIdentifier();
    }
}
