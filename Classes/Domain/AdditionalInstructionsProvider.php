<?php

declare(strict_types=1);

namespace Sitegeist\SlopMachine\Domain;

use Sitegeist\Pandora\Instruction\InstructionsProvider;
use Sitegeist\SlopMachine\Domain\Repository\InstructionRepository;

/**
 * The instructions the administrators wrote, sent to the agent.
 *
 * The provider is registered for the role of the agent next to the providers of the
 * installation - the registry of the MCP server concatenates them, so this text lands with
 * the instructions that are configured in the settings.
 *
 * Register it like this:
 *
 *     Sitegeist:
 *       Pandora:
 *         instructions:
 *           'YourRole:McpAgent':
 *             additionalInstructions: 'Sitegeist\SlopMachine\Domain\AdditionalInstructionsProvider'
 */
final class AdditionalInstructionsProvider implements InstructionsProvider
{
    public function __construct(
        private readonly InstructionRepository $instructionRepository,
    ) {
    }

    public function getInstructions(): string
    {
        $blocks = [];
        foreach ($this->instructionRepository->findAll() as $instruction) {
            $text = trim($instruction->getInstructions());
            if ($text !== '') {
                $blocks[] = $text;
            }
        }

        return implode("\n\n", $blocks);
    }
}
