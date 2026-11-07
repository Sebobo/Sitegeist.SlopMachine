<?php

declare(strict_types=1);

namespace Sitegeist\SlopMachine\Domain\Model;

use Doctrine\ORM\Mapping as ORM;
use Neos\Flow\Annotations as Flow;

/**
 * An instruction the administrators of the installation wrote for the agents.
 *
 * The instructions of the agents come from two places: the providers that are configured for
 * the role of the agent, and the entries of this model. This model is the second one - the
 * place where an administrator writes down what the configured instructions do not cover.
 *
 * The position is the place of the entry in the list. The entries are sent to the agent in
 * that order, and the administrators can reorder them.
 */
#[Flow\Entity]
class Instruction
{
    #[ORM\Column(type: 'string')]
    protected string $title;

    #[ORM\Column(type: 'text')]
    protected string $instructions;

    #[ORM\Column(type: 'integer')]
    protected int $position;

    public function __construct(string $title, string $instructions, int $position)
    {
        $this->title = $title;
        $this->instructions = $instructions;
        $this->position = $position;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): void
    {
        $this->title = $title;
    }

    public function getInstructions(): string
    {
        return $this->instructions;
    }

    public function setInstructions(string $instructions): void
    {
        $this->instructions = $instructions;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): void
    {
        $this->position = $position;
    }
}
