<?php

declare(strict_types=1);

namespace Sitegeist\SlopMachine\Domain\Model;

use Neos\Flow\Annotations as Flow;
use Neos\Neos\Domain\Model\User;
use Doctrine\ORM\Mapping as ORM;

#[Flow\Entity]
class AgentAssignment
{
    /**
     * @ORM\Id
     */
    public string $agentId;

    /**
     * @ORM\ManyToOne
     * @var User
     */
    public User $user;

    public function __construct(string $agentId, User $user)
    {
        $this->agentId = $agentId;
        $this->user = $user;
    }
}
