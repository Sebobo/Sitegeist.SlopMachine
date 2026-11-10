<?php

declare(strict_types=1);

namespace Sitegeist\SlopMachine\Tests\Functional;

use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Model\Workspace;
use Neos\ContentRepository\Domain\Repository\WorkspaceRepository;
use Neos\ContentRepository\Domain\Service\ContentDimensionCombinator;
use Neos\ContentRepository\Domain\Service\ContextFactoryInterface;
use Neos\ContentRepository\Domain\Service\NodeTypeManager;
use Neos\Flow\Security\Account;
use Neos\Flow\Security\AccountRepository;
use Neos\Flow\Tests\FunctionalTestCase;
use Neos\Neos\Domain\Model\Domain;
use Neos\Neos\Domain\Model\Site;
use Neos\Neos\Domain\Model\User;
use Neos\Neos\Domain\Repository\DomainRepository;
use Neos\Neos\Domain\Repository\SiteRepository;
use Neos\Neos\Domain\Service\UserService;
use Neos\Neos\Utility\User as UserUtility;
use Sitegeist\Pandora\Infrastructure\CapabilityHandlerContainer;
use Sitegeist\SlopMachine\Domain\DimensionSpacePointResolver;
use Sitegeist\SlopMachine\Domain\Model\AgentAssignment;
use Sitegeist\SlopMachine\Domain\Repository\AgentAssignmentRepository;

/**
 * The base of the tests of the agent.
 *
 * The tests work with a content repository that holds a site with one document, and with the
 * workspaces the agent works in:
 *
 * - "live" holds the published content,
 * - "shared-review" is the internal workspace the agent hands its work over to,
 * - "user-mcp-agent" is the personal workspace of the agent,
 * - "user-other" is the personal workspace of another user.
 *
 * The security is testable here: the authorization checks are enabled, so the policies of Neos
 * decide what the agent may do - which is the point of these tests.
 */
abstract class AgentTestCase extends FunctionalTestCase
{
    protected static $testablePersistenceEnabled = true;

    /**
     * The authorization checks are enabled - without them the policies would not decide
     * anything, and the tests would prove nothing.
     */
    protected $testableSecurityEnabled = true;

    /**
     * The role of the account of the agent in the tests.
     */
    protected const AGENT_ROLE = 'Sitegeist.SlopMachine:TestAgent';

    /**
     * The identifier of the account of the agent - in the installation it is the hash of the
     * token, and it is what the assignment of the agent is keyed by.
     */
    protected const AGENT_ID = 'the-agent-token-hash';

    /**
     * The document type of the tests - a type of this package, so that the tests do not
     * depend on the node types of the installation they run in.
     */
    protected const PAGE_NODE_TYPE = 'Sitegeist.SlopMachine:Testing:Document.Page';

    /**
     * The content element with an image, of the tests.
     */
    protected const IMAGE_NODE_TYPE = 'Sitegeist.SlopMachine:Testing:Content.Image';

    protected ContextFactoryInterface $contextFactory;
    protected NodeTypeManager $nodeTypeManager;
    protected WorkspaceRepository $workspaceRepository;
    protected SiteRepository $siteRepository;
    protected DomainRepository $domainRepository;
    protected UserService $neosUserService;

    protected Workspace $liveWorkspace;
    protected Workspace $reviewWorkspace;
    protected Workspace $agentWorkspace;
    protected Workspace $otherUserWorkspace;

    protected User $agentUser;
    protected User $otherUser;

    /**
     * The identifier of a document in the personal workspace of the other user - a document
     * the agent may not reach.
     */
    protected ?string $otherUserDocumentId = null;

    /**
     * The name of the site node - the default site of the installation, so that the contexts
     * of the framework find it.
     */
    protected string $siteNodeName = 'example';

    /**
     * The host the site of the tests is reached on.
     */
    protected string $siteHostName = 'localhost';

    protected function setUp(): void
    {
        parent::setUp();

        $this->contextFactory = $this->getObject(ContextFactoryInterface::class);
        $this->nodeTypeManager = $this->getObject(NodeTypeManager::class);
        $this->workspaceRepository = $this->getObject(WorkspaceRepository::class);
        $this->siteRepository = $this->getObject(SiteRepository::class);
        $this->domainRepository = $this->getObject(DomainRepository::class);
        $this->neosUserService = $this->getObject(UserService::class);

        $this->liveWorkspace = new Workspace('live');
        $this->workspaceRepository->add($this->liveWorkspace);

        // The shared review workspace: an internal workspace - no owner, based on live.
        $this->reviewWorkspace = new Workspace('shared-review', $this->liveWorkspace);
        $this->workspaceRepository->add($this->reviewWorkspace);

        $this->agentUser = $this->neosUserService->createUser('mcp-agent', 'a-password', 'MCP', 'Agent');
        $this->otherUser = $this->neosUserService->createUser('other-user', 'a-password', 'Other', 'User');
        // The users come with their personal workspaces - they have to be written before they
        // can be found.
        $this->persistenceManager->persistAll();

        $this->agentWorkspace = $this->workspaceOfUser('mcp-agent');
        $this->otherUserWorkspace = $this->workspaceOfUser('other-user');

        // The agent works in a personal workspace that is based on the shared review workspace,
        // so that it sees what the humans are reviewing.
        $this->agentWorkspace->setBaseWorkspace($this->reviewWorkspace);
        $this->workspaceRepository->update($this->agentWorkspace);

        $this->createSite();
        $this->createContentInLiveWorkspace();

        $this->persistenceManager->persistAll();

        $this->createDocumentInTheWorkspaceOfTheOtherUser();

        $this->assignAgentToUser();
        $this->authenticateAsAgent();
    }

    /**
     * A document in the personal workspace of the other user.
     *
     * The policies deny every access to the personal workspace of another user - also for the
     * setup of the test, so the document is created with the authorization checks switched
     * off. The agent must not be able to reach it.
     */
    protected function createDocumentInTheWorkspaceOfTheOtherUser(): void
    {
        $this->securityContext->withoutAuthorizationChecks(function (): void {
            $context = $this->contextFactory->create([
                'workspaceName' => $this->otherUserWorkspace->getName(),
                'dimensions' => $this->dimensions(),
            ]);
            $siteNode = $context->getNode('/sites/' . $this->siteNodeName);
            self::assertNotNull($siteNode);

            $document = $siteNode->createNode('secret', $this->nodeTypeManager->getNodeType(self::PAGE_NODE_TYPE));
            $document->setProperty('title', 'Secret');

            $this->persistenceManager->persistAll();
            $this->otherUserDocumentId = $document->getIdentifier();
        });
    }

    /**
     * An object of the object manager, with its type.
     *
     * @template T of object
     * @param class-string<T> $className
     * @return T
     */
    protected function getObject(string $className): object
    {
        /** @var T $object */
        $object = $this->objectManager->get($className);

        return $object;
    }

    /**
     * A capability of the agent.
     *
     * The capability classes are not proxied, so the handler container builds them - Flow
     * cannot inject the dependencies of a class that carries no proxy.
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

    protected function tearDown(): void
    {
        $this->contextFactory->reset();
        parent::tearDown();
    }

    /**
     * The personal workspace of a user.
     */
    protected function workspaceOfUser(string $username): Workspace
    {
        $workspaceName = UserUtility::getPersonalWorkspaceNameForUsername($username);
        $workspace = $this->workspaceRepository->findOneByName($workspaceName);
        self::assertInstanceOf(Workspace::class, $workspace, sprintf('The personal workspace "%s" exists.', $workspaceName));

        return $workspace;
    }

    /**
     * The site of the tests, with the domain it is reached on - the preview of a document is
     * loaded from the domain of the site the document belongs to.
     */
    protected function createSite(): void
    {
        $site = new Site($this->siteNodeName);
        $site->setState(Site::STATE_ONLINE);
        $site->setSiteResourcesPackageKey('Neos.Fusion');
        $this->siteRepository->add($site);

        $domain = new Domain();
        $domain->setHostname($this->siteHostName);
        $domain->setSite($site);
        $domain->setActive(true);
        $this->domainRepository->add($domain);
        $site->setPrimaryDomain($domain);
    }

    /**
     * The content of the tests: a site with one document, published to live.
     */
    protected function createContentInLiveWorkspace(): void
    {
        $liveContext = $this->contextFactory->create([
            'workspaceName' => 'live',
            'dimensions' => $this->dimensions(),
        ]);
        $rootNode = $liveContext->getRootNode();

        $sitesNode = $rootNode->createNode('sites', $this->nodeTypeManager->getNodeType('Neos.Neos:Sites'));
        $siteNode = $sitesNode->createNode($this->siteNodeName, $this->nodeTypeManager->getNodeType('Neos.Neos:Site'));

        $page = $siteNode->createNode('home', $this->nodeTypeManager->getNodeType(self::PAGE_NODE_TYPE));
        $page->setProperty('title', 'Home');
    }

    /**
     * The dimensions of the content of the tests.
     *
     * They are the dimensions of the installation the tests run in - the tests must not depend
     * on a dimension the installation may not have. The dimension space point is resolved
     * against the dimensions of the installation, the same way the agent resolves it.
     *
     * @return array<string,list<string>>
     */
    protected function dimensions(): array
    {
        return $this->getObject(DimensionSpacePointResolver::class)->resolve($this->dimensionSpacePoint());
    }

    /**
     * The dimension space point of the installation - the first one the dimensionspace tool
     * reports.
     *
     * @return array<string,string>
     */
    protected function dimensionSpacePoint(): array
    {
        $combinations = $this->getObject(ContentDimensionCombinator::class)->getAllAllowedCombinations();
        $firstCombination = \reset($combinations);
        $point = [];
        foreach ($firstCombination ?: [] as $dimensionName => $dimensionValues) {
            $point[$dimensionName] = (string)\reset($dimensionValues);
        }

        return $point;
    }

    /**
     * The assignment of the agent to the Neos user it works as.
     */
    protected function assignAgentToUser(): void
    {
        $assignment = new AgentAssignment(self::AGENT_ID, $this->agentUser);
        $this->getObject(AgentAssignmentRepository::class)->add($assignment);
        $this->persistenceManager->persistAll();
    }

    /**
     * The account of the agent: the identifier is the hash of its token, and the role is the
     * role an installation gives the account.
     */
    protected function authenticateAsAgent(): void
    {
        $account = new Account();
        $account->setAccountIdentifier(self::AGENT_ID);
        $account->setAuthenticationProviderName('TestingProvider');
        $account->setRoles([$this->policyService->getRole(self::AGENT_ROLE)]);
        $this->getObject(AccountRepository::class)->add($account);
        $this->persistenceManager->persistAll();

        $this->authenticateAccount($account);
    }

    /**
     * The path of the document of the tests.
     */
    protected function documentPath(): string
    {
        return '/sites/' . $this->siteNodeName . '/home';
    }

    /**
     * The document of the tests, in the given workspace.
     */
    protected function documentIn(Workspace $workspace): ?NodeInterface
    {
        $context = $this->contextFactory->create([
            'workspaceName' => $workspace->getName(),
            'dimensions' => $this->dimensions(),
        ]);

        return $context->getNode($this->documentPath());
    }
}
