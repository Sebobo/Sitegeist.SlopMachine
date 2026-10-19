<?php

declare(strict_types=1);

namespace Sitegeist\SlopMachine\Domain;

use Sitegeist\Pandora\Instruction\InstructionsProvider;

/**
 * Explains the workspace split to the agent: it works in its own workspace and hands work over to
 * a shared review workspace that humans publish.
 *
 * Registered next to ContentRepositoryInstructionsProvider for the same
 * role; the registry concatenates the providers, so this text lands after the generic content
 * repository introduction.
 */
final class SharedWorkspaceInstructionsProvider implements InstructionsProvider
{
    public function __construct(
        protected AgentWorkspaceResolver $agentWorkspaceResolver,
        protected SharedWorkspaceContextFactory $sharedWorkspaceContextFactory,
    ) {
    }

    public function getInstructions(): string
    {
        try {
            $workspaceName = $this->agentWorkspaceResolver->getWorkspaceName();
            $baseWorkspaceName = $this->agentWorkspaceResolver->getWorkspace()->getBaseWorkspace()?->getName();
        } catch (\Throwable) {
            $workspaceName = '(unresolved)';
            $baseWorkspaceName = null;
        }

        try {
            $sharedWorkspaceName = $this->sharedWorkspaceContextFactory->getWorkspaceName();
        } catch (\Throwable) {
            $sharedWorkspaceName = '(unresolved)';
        }

        $workspaceDescription = $baseWorkspaceName === null
            ? \sprintf('Your own workspace is "%s".', $workspaceName)
            : \sprintf(
                'Your own workspace is "%s", based on "%s".',
                $workspaceName,
                $baseWorkspaceName
            );

        return 'You work in two workspaces, and the difference matters:
            ' . $workspaceDescription . ' All of your regular read and write tools (find-children, find-descendants,
            find-subtree, BulkWriting, CreateNodeAggregateWithNode, SetNodeProperties, SetNodeReferences) only ever
            address your own workspace. They cannot see the shared workspace, and they cannot address the live workspace.
            The shared review workspace is "' . $sharedWorkspaceName . '". Humans review and publish it. You never
            address the live workspace, and no tool lets you publish anything.
            - To write into the shared review workspace, use BulkWriteToSharedWorkspace. It takes the same commands as
              BulkWriting, so nothing about the writing dialect changes. A node aggregate id that you created in your
              own workspace does not exist in the shared workspace, so you can only attach new content below nodes
              that are already published there.
            - Because your own workspace is based on the shared one, what you write into the shared review workspace
              is immediately visible in your own workspace under the same node aggregate ids. Create it once, in the
              shared review workspace, not in both.
            - To read the shared review workspace, use find-children-in-shared-workspace or
              find-descendants-in-shared-workspace. Do this before you write, or you will create content that already
              exists there.
            - To remove content from the shared review workspace, use RemoveFromSharedWorkspace. That is a permanent
              deletion in a workspace humans work in, so read it first and remove the smallest possible node.
            - To remove content from your own workspace, use RemoveNode. That is also a permanent deletion; use it for
              content you created yourself that must not end up in the review workspace.
            - Both removal tools only accept nodes that belong to the workspace they address. A node you did not
              create there - an inherited, already published node - is refused, and removing it is not what you want
              in the first place: in your own workspace, DiscardChanges restores an edited node to its published state
              instead of deleting it.
            - To see everything you changed in your own workspace, use ListUnpublishedChanges. It reports the difference
              between your workspace and the workspace it is based on, which is the same list of changes you would hand
              over. This is also how you compare your work with the review workspace.
            - To undo your own changes, use DiscardChanges. It restores content to its state in the base workspace,
              which is the opposite of RemoveNode: reverting a node you created removes it, reverting a node you edited
              restores it. Prefer DiscardChanges when you are unsure, and prefer an explicit list of node aggregate ids
              over discardAll.
            None of these tools publish anything. Handing work over means writing it into the shared review workspace;
            a human decides whether it goes live.';
    }
}
