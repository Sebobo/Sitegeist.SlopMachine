<?php

declare(strict_types=1);

namespace Sitegeist\SlopMachine\Tests\Functional;

use Neos\Flow\Security\Account;
use Neos\Flow\Security\AccountRepository;
use Neos\Party\Domain\Service\PartyService;
use Sitegeist\SlopMachine\Security\AgentAwareUserService;

/**
 * The user the policies compare the workspace owner with.
 *
 * The account of the agent is a machine account: it has no Neos user of its own. The service
 * answers with the user the agent is assigned to, so that the policies treat the personal
 * workspace of the agent as its own - and the personal workspaces of the other users as
 * somebody else's.
 */
class AgentAwareUserServiceTest extends AgentTestCase
{
    public function testTheUserOfTheAgentIsAnsweredForTheTokenAccount(): void
    {
        $service = $this->getObject(AgentAwareUserService::class);

        self::assertSame(
            $this->agentUser,
            $service->getBackendUser(),
            'The account of the agent answers with the user the agent is assigned to.'
        );
    }

    public function testTheUserOfTheAccountIsAnsweredForANormalAccount(): void
    {
        $account = new Account();
        $account->setAccountIdentifier('a-normal-account');
        $account->setAuthenticationProviderName('TestingProvider');
        $this->getObject(AccountRepository::class)->add($account);
        $this->getObject(PartyService::class)->assignAccountToParty($account, $this->otherUser);
        $this->persistenceManager->persistAll();
        $this->authenticateAccount($account);

        $service = $this->getObject(AgentAwareUserService::class);

        self::assertSame(
            $this->otherUser,
            $service->getBackendUser(),
            'An account with a Neos user answers with that user.'
        );
    }

    public function testNullIsAnsweredWhenNoAgentIsAssigned(): void
    {
        $account = new Account();
        $account->setAccountIdentifier('an-account-without-an-agent');
        $account->setAuthenticationProviderName('TestingProvider');
        $this->getObject(AccountRepository::class)->add($account);
        $this->persistenceManager->persistAll();
        $this->authenticateAccount($account);

        $service = $this->getObject(AgentAwareUserService::class);

        self::assertNull(
            $service->getBackendUser(),
            'An account that is not an agent answers with nobody.'
        );
    }
}
