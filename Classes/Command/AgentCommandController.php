<?php

declare(strict_types=1);

namespace Sitegeist\SlopMachine\Command;

use Neos\Flow\Cli\CommandController;
use Neos\Neos\Domain\Service\UserService;
use Sitegeist\SlopMachine\Domain\Model\AgentAssignment;
use Sitegeist\SlopMachine\Domain\Repository\AgentAssignmentRepository;

class AgentCommandController extends CommandController
{
    public function __construct(
        private readonly UserService $userService,
        private readonly AgentAssignmentRepository $agentAssignmentRepository,
    ) {
        parent::__construct();
    }

    public function assignToUserCommand(string $agentId, string $username): void
    {
        $agentAssignment = $this->agentAssignmentRepository->findOneByAgentId($agentId);
        if ($agentAssignment) {
            $this->outputLine('Agent ' . $agentId . ' is already assigned.');
            $this->sendAndExit();
        }

        $user = $this->userService->getUser($username);
        if (!$user) {
            $this->outputLine('Unknown user ' . $username);
            $this->sendAndExit();
        }

        $agentAssignment = new AgentAssignment($agentId, $user);
        $this->agentAssignmentRepository->add($agentAssignment);

        $this->outputLine('Agent successfully assigned.');
    }
}
