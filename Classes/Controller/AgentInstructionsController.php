<?php

declare(strict_types=1);

namespace Sitegeist\SlopMachine\Controller;

use Neos\Flow\Annotations as Flow;
use Neos\Flow\I18n\Translator;
use Neos\Fusion\View\FusionView;
use Neos\Neos\Controller\Module\AbstractModuleController;
use Sitegeist\SlopMachine\Domain\AdditionalInstructionsProvider;
use Sitegeist\SlopMachine\Domain\ConfiguredInstructionsService;
use Sitegeist\SlopMachine\Domain\InstructionService;

/**
 * The backend module of the agents: it shows the instructions the agents are told, and lets
 * the administrators of the installation add their own.
 *
 * The module is rendered with Fusion, and the parts of it are exchanged with htmx - the list
 * of the entries is on the left, the instructions of the selected entry on the right.
 */
#[Flow\Scope('singleton')]
final class AgentInstructionsController extends AbstractModuleController
{
    /**
     * The module is rendered with Fusion, not with a template of the backend.
     *
     * @var class-string
     */
    protected $defaultViewObjectName = FusionView::class;

    public function __construct(
        private readonly InstructionService $instructionService,
        private readonly ConfiguredInstructionsService $configuredInstructionsService,
        private readonly Translator $translator,
    ) {
    }

    /**
     * The module: the list of the entries and the details of the selected one.
     */
    public function indexAction(?string $instruction = null): void
    {
        $this->renderModule($instruction !== '' ? $instruction : null);
    }

    /**
     * Shows the instructions of an entry.
     */
    public function selectAction(string $instruction): void
    {
        $this->renderModule($instruction);
    }

    /**
     * Opens the form for a new entry.
     */
    public function newAction(): void
    {
        $this->renderModule(null, [
            'mode' => 'create',
            'id' => null,
            'title' => '',
            'instructions' => '',
            'error' => null,
        ]);
    }

    /**
     * Opens the form of an entry, so that it can be changed.
     */
    public function editAction(string $instruction): void
    {
        $entry = $this->instructionService->findByIdentifier($instruction);
        if ($entry === null) {
            $this->renderModule();
            return;
        }

        $this->renderModule($instruction, [
            'mode' => 'edit',
            'id' => $instruction,
            'title' => $entry->getTitle(),
            'instructions' => $entry->getInstructions(),
            'error' => null,
        ]);
    }

    /**
     * Writes a new entry.
     */
    public function createAction(string $title = '', string $instructions = ''): void
    {
        $error = $this->validate($title, $instructions);
        if ($error !== null) {
            $this->renderModule(null, [
                'mode' => 'create',
                'id' => null,
                'title' => $title,
                'instructions' => $instructions,
                'error' => $error,
            ]);
            return;
        }

        $instruction = $this->instructionService->create($title, $instructions);
        $this->renderModule($this->instructionService->getIdentifier($instruction));
    }

    /**
     * Writes the changes of an entry.
     */
    public function updateAction(string $instruction, string $title = '', string $instructions = ''): void
    {
        $entry = $this->instructionService->findByIdentifier($instruction);
        if ($entry === null) {
            $this->renderModule();
            return;
        }

        $error = $this->validate($title, $instructions);
        if ($error !== null) {
            $this->renderModule($instruction, [
                'mode' => 'edit',
                'id' => $instruction,
                'title' => $title,
                'instructions' => $instructions,
                'error' => $error,
            ]);
            return;
        }

        $this->instructionService->update($entry, $title, $instructions);
        $this->renderModule($instruction);
    }

    /**
     * Removes an entry.
     */
    public function deleteAction(string $instruction): void
    {
        $entry = $this->instructionService->findByIdentifier($instruction);
        if ($entry !== null) {
            $this->instructionService->delete($entry);
        }

        $this->renderModule();
    }

    /**
     * Moves an entry one place up or down.
     */
    public function moveAction(string $instruction, string $direction = 'down'): void
    {
        $entry = $this->instructionService->findByIdentifier($instruction);
        if ($entry !== null) {
            $this->instructionService->move($entry, $direction === 'up' ? 'up' : 'down');
        }

        $this->renderModule($instruction);
    }

    /**
     * Renders the module with the given entry selected and, if given, an open form.
     *
     * @param array{mode: string, id: string|null, title: string, instructions: string, error: string|null}|null $form
     */
    protected function renderModule(?string $selectedId = null, ?array $form = null): void
    {
        $configuredEntries = [];
        $customEntries = [];
        foreach ($this->buildEntries() as $entry) {
            if ($entry['source'] === 'custom') {
                $customEntries[] = $entry;
                continue;
            }
            $configuredEntries[] = $entry;
        }

        $selectedEntry = null;
        foreach (array_merge($configuredEntries, $customEntries) as $entry) {
            if ($entry['id'] === $selectedId) {
                $selectedEntry = $entry;
                break;
            }
        }

        $this->view->assignMultiple([
            'configuredEntries' => $configuredEntries,
            'customEntries' => $customEntries,
            'selectedEntry' => $selectedEntry,
            'selectedId' => $selectedEntry !== null ? $selectedEntry['id'] : null,
            'form' => $form,
            'ownEntriesAreSent' => $this->configuredInstructionsService->isProviderConfigured(
                AdditionalInstructionsProvider::class
            ),
        ]);
    }

    /**
     * All entries: the ones that are configured for the roles of the installation, and the
     * ones the administrators wrote.
     *
     * @return list<array{id: string, title: string, instructions: string, source: string, role: string|null, provider: string|null}>
     */
    protected function buildEntries(): array
    {
        $entries = [];

        foreach ($this->configuredInstructionsService->findConfiguredProviders() as $provider) {
            $entries[] = [
                'id' => $this->getConfiguredEntryId($provider['role'], $provider['name']),
                'title' => $provider['name'],
                'instructions' => $provider['instructions'],
                'source' => 'configuration',
                'role' => $provider['role'],
                'provider' => $provider['class'],
            ];
        }

        foreach ($this->instructionService->findAll() as $instruction) {
            $entries[] = [
                'id' => $this->instructionService->getIdentifier($instruction),
                'title' => $instruction->getTitle(),
                'instructions' => $instruction->getInstructions(),
                'source' => 'custom',
                'role' => null,
                'provider' => null,
            ];
        }

        return $entries;
    }

    /**
     * The identifier of a configured entry.
     *
     * It is derived from the role and the name of the provider, so that it stays the same
     * as long as the configuration does.
     */
    protected function getConfiguredEntryId(string $role, string $name): string
    {
        return 'config-' . substr(sha1($role . '#' . $name), 0, 16);
    }

    /**
     * The message of the first problem the values have, or NULL if they are all right.
     */
    protected function validate(string $title, string $instructions): ?string
    {
        if (trim($title) === '') {
            return $this->translator->translateById(
                'instruction.error.titleMissing',
                [],
                null,
                null,
                'Main',
                'Sitegeist.SlopMachine'
            );
        }

        if (mb_strlen($title) > 255) {
            return $this->translator->translateById(
                'instruction.error.titleTooLong',
                [],
                null,
                null,
                'Main',
                'Sitegeist.SlopMachine'
            );
        }

        if (trim($instructions) === '') {
            return $this->translator->translateById(
                'instruction.error.instructionsMissing',
                [],
                null,
                null,
                'Main',
                'Sitegeist.SlopMachine'
            );
        }

        return null;
    }
}
