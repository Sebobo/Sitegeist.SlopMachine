<?php

declare(strict_types=1);

namespace Sitegeist\SlopMachine\Domain;

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Sitegeist\SlopMachine\Domain\Model\Instruction;
use Sitegeist\SlopMachine\Domain\Repository\InstructionRepository;

/**
 * Maintains the instructions the administrators wrote: they are created, edited, deleted and
 * reordered in the backend module of the agents.
 */
#[Flow\Scope('singleton')]
class InstructionService
{
    public function __construct(
        protected InstructionRepository $instructionRepository,
        protected PersistenceManagerInterface $persistenceManager,
    ) {
    }

    /**
     * All entries, in the order they are sent to the agent in.
     *
     * @return list<Instruction>
     */
    public function findAll(): array
    {
        return array_values($this->instructionRepository->findAll()->toArray());
    }

    public function findByIdentifier(string $identifier): ?Instruction
    {
        $instruction = $this->persistenceManager->getObjectByIdentifier($identifier, Instruction::class);

        return $instruction instanceof Instruction ? $instruction : null;
    }

    /**
     * The identifier of the entry - the uuid the persistence manager knows it by.
     */
    public function getIdentifier(Instruction $instruction): string
    {
        $identifier = $this->persistenceManager->getIdentifierByObject($instruction);

        return $identifier === null ? '' : (string)$identifier;
    }

    /**
     * A new entry, at the end of the list.
     */
    public function create(string $title, string $instructions): Instruction
    {
        $instruction = new Instruction($title, $instructions, $this->getNextPosition());
        $this->instructionRepository->add($instruction);
        $this->persistAll();

        return $instruction;
    }

    /**
     * The position of a new entry: behind the last one.
     *
     * The positions are not renumbered when an entry is deleted, so the last position is
     * not the number of the entries.
     */
    protected function getNextPosition(): int
    {
        $position = 0;
        foreach ($this->findAll() as $instruction) {
            $position = max($position, $instruction->getPosition() + 1);
        }

        return $position;
    }

    public function update(Instruction $instruction, string $title, string $instructions): void
    {
        $instruction->setTitle($title);
        $instruction->setInstructions($instructions);
        $this->instructionRepository->update($instruction);
        $this->persistAll();
    }

    public function delete(Instruction $instruction): void
    {
        $this->instructionRepository->remove($instruction);
        $this->persistAll();
    }

    /**
     * Moves the entry one place up or down in the list.
     *
     * The positions are swapped with the neighbour, so the order of the other entries does not
     * change - and the gaps that a deleted entry leaves behind do not matter either.
     *
     * @param string $direction "up" or "down"
     */
    public function move(Instruction $instruction, string $direction): void
    {
        $instructions = $this->findAll();
        $index = null;
        foreach ($instructions as $currentIndex => $current) {
            if ($current === $instruction) {
                $index = $currentIndex;
                break;
            }
        }

        if ($index === null) {
            return;
        }

        $neighbourIndex = $direction === 'up' ? $index - 1 : $index + 1;
        if (!isset($instructions[$neighbourIndex])) {
            return;
        }

        $neighbour = $instructions[$neighbourIndex];
        $position = $instruction->getPosition();
        $instruction->setPosition($neighbour->getPosition());
        $neighbour->setPosition($position);

        $this->instructionRepository->update($instruction);
        $this->instructionRepository->update($neighbour);
        $this->persistAll();
    }

    /**
     * Writes the changes to the database.
     *
     * The module shows the entries as they are - and a query does not see an entry that was
     * only added to the persistence manager. So the changes are written right away, and the
     * list of the module shows what was written.
     */
    protected function persistAll(): void
    {
        $this->persistenceManager->persistAll();
    }
}
