<?php

declare(strict_types=1);

namespace Sitegeist\SlopMachine\Domain;

use Neos\ContentRepository\Domain\Model\Workspace;
use Neos\ContentRepository\Domain\Repository\WorkspaceRepository;
use Neos\Flow\Annotations as Flow;
use Neos\Neos\Service\TransliterationService;

/**
 * Creates and finds the topic workspaces: one workspace per topic an agent works on, based on
 * the shared review workspace.
 *
 * A topic workspace is what makes two agents on different topics stop interfering with each
 * other: each of them works in its own branch, and a human reviews and publishes each branch on
 * its own. The name of the workspace is derived from the topic, so the same topic always maps to
 * the same workspace - two agents that work on the same topic share it on purpose.
 */
#[Flow\Scope('singleton')]
class TopicWorkspaceService
{
    /**
     * The longest slug a topic may produce. The workspace name is the name of the shared review
     * workspace plus the slug, and a workspace name may not be longer than 200 characters.
     */
    public const MAX_SLUG_LENGTH = 60;

    public function __construct(
        protected SharedWorkspaceContextFactory $sharedWorkspaceContextFactory,
        protected WorkspaceRepository $workspaceRepository,
        protected TransliterationService $transliterationService,
        protected AgentWorkspaceResolver $agentWorkspaceResolver,
    ) {
    }

    /**
     * The name of the workspace for a topic.
     *
     * @throws \RuntimeException if the topic does not contain a single character that can be
     *         part of a workspace name
     */
    public function getWorkspaceName(string $topic): string
    {
        $slug = $this->slugify($topic);
        if ($slug === '') {
            throw new \RuntimeException(
                \sprintf(
                    'The topic "%s" does not contain a single letter or digit, so it cannot be turned into a'
                    . ' workspace name. Use a topic that names the work, for example "Zahnzusatzversicherung".',
                    $topic
                ),
                1758250010
            );
        }

        return $this->sharedWorkspaceContextFactory->getSharedWorkspaceName() . '-' . $slug;
    }

    /**
     * Creates the workspace for a topic, or returns the one that exists already.
     *
     * The workspace has no owner: a workspace without an owner is an internal workspace, so every
     * editor can read it and every editor can publish it into the shared review workspace - which
     * is exactly what the review of an agent's work needs.
     *
     * @param string $title The title of the workspace, the topic by default
     * @return array{workspace: Workspace, created: bool}
     */
    public function createWorkspace(string $topic, ?string $title = null): array
    {
        $workspaceName = $this->getWorkspaceName($topic);

        $existingWorkspace = $this->workspaceRepository->findOneByName($workspaceName);
        if ($existingWorkspace instanceof Workspace) {
            if (!$this->sharedWorkspaceContextFactory->isTopicWorkspace($existingWorkspace)) {
                throw new \RuntimeException(\sprintf(
                    'There is already a workspace named "%s", and it is not based on the shared review workspace'
                    . ' "%s". Pick a topic with a different name.',
                    $workspaceName,
                    $this->sharedWorkspaceContextFactory->getSharedWorkspaceName()
                ));
            }

            return ['workspace' => $existingWorkspace, 'created' => false];
        }

        $sharedWorkspace = $this->sharedWorkspaceContextFactory->getSharedWorkspace();

        $workspace = new Workspace($workspaceName, $sharedWorkspace);
        $workspace->setTitle($title !== null && \trim($title) !== '' ? \trim($title) : $topic);
        // No description: the base workspace and the way the work is reviewed are visible in the
        // workspace module, and who created the workspace belongs into the workspace details.
        $this->workspaceRepository->add($workspace);

        $this->topicWorkspaceCreated($workspace, $this->agentWorkspaceResolver->getAssignedUsername());

        return ['workspace' => $workspace, 'created' => true];
    }

    /**
     * A topic workspace was created.
     *
     * The creator is the name of the Neos user the agent works as - never the token the agent
     * authenticates with, which is a secret. Listeners record it where it belongs, for example
     * in the workspace details of the workspace module.
     */
    #[Flow\Signal]
    public function topicWorkspaceCreated(Workspace $workspace, ?string $creator): void
    {
    }

    /**
     * Turns a topic into the slug of a workspace name: lower case, ASCII, dash separated.
     */
    private function slugify(string $topic): string
    {
        $ascii = $this->transliterationService->transliterate($topic);
        $slug = \preg_replace('/[^a-zA-Z0-9]+/', '-', $ascii) ?? '';
        $slug = \strtolower(\trim($slug, '-'));

        if (\strlen($slug) > self::MAX_SLUG_LENGTH) {
            $slug = \rtrim(\substr($slug, 0, self::MAX_SLUG_LENGTH), '-');
        }

        return $slug;
    }
}
