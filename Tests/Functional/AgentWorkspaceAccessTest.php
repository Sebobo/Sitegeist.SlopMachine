<?php

declare(strict_types=1);

namespace Sitegeist\SlopMachine\Tests\Functional;

use Neos\ContentRepository\Domain\Model\Node;
use Neos\Flow\Security\Exception\AccessDeniedException;
use Sitegeist\SlopMachine\Domain\Repository\AgentAssignmentRepository;

/**
 * The workspaces the agent may reach, and the ones it may not.
 *
 * These are the tests that keep the boundary of the agent intact: the policies of Neos decide
 * what the account of the agent may read and write, and the personal workspaces of the other
 * users have to stay closed.
 */
class AgentWorkspaceAccessTest extends AgentTestCase
{
    public function testTheAgentMayReadItsOwnPersonalWorkspace(): void
    {
        $document = $this->documentIn($this->agentWorkspace);

        self::assertInstanceOf(Node::class, $document, 'The agent reads the document in its own workspace.');
        self::assertSame('Home', $document->getProperty('title'));
    }

    public function testTheAgentMayNotReadThePersonalWorkspaceOfAnotherUser(): void
    {
        $this->expectException(AccessDeniedException::class);

        $this->documentIn($this->otherUserWorkspace);
    }

    public function testTheAgentMayReadTheSharedReviewWorkspace(): void
    {
        $document = $this->documentIn($this->reviewWorkspace);

        self::assertInstanceOf(Node::class, $document, 'The agent reads the document in the review workspace.');
    }

    public function testTheAgentMayNotPublishToTheLiveWorkspace(): void
    {
        $context = $this->contextFactory->create(['workspaceName' => $this->agentWorkspace->getName()]);
        $siteNode = $context->getNode('/sites/' . $this->siteNodeName);
        self::assertInstanceOf(Node::class, $siteNode);

        $document = $siteNode->createNode('a-new-page', $this->nodeTypeManager->getNodeType('Neos.Neos:Document'));
        $this->persistenceManager->persistAll();

        $this->expectException(AccessDeniedException::class);

        $this->agentWorkspace->publishNode($document, $this->liveWorkspace);
    }

    public function testTheAgentMayNotLogInWithAPassword(): void
    {
        self::assertFalse(
            $this->privilegeManager->isPrivilegeTargetGranted('Neos.Neos:BackendLogin'),
            'The account of the agent has no business at the login form.'
        );
    }

    public function testTheAgentMayNotReachItsOwnWorkspaceWithoutTheAssignment(): void
    {
        // Without the assignment the account of the agent has no Neos user, so the policies
        // treat the personal workspace of the agent as the personal workspace of somebody else.
        $assignments = $this->getObject(AgentAssignmentRepository::class);
        foreach ($assignments->findAll() as $assignment) {
            $assignments->remove($assignment);
        }
        $this->persistenceManager->persistAll();

        $this->expectException(AccessDeniedException::class);

        $this->documentIn($this->agentWorkspace);
    }
}
