<?php

declare(strict_types=1);

namespace Sitegeist\SlopMachine\Domain;

use Sitegeist\Pandora\Instruction\InstructionsProvider;

/**
 * Explains the workspace split to the agent: it works in its own workspace and publishes what it
 * has done into a shared review workspace that humans publish further.
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
            $sharedWorkspaceName = $this->sharedWorkspaceContextFactory->getSharedWorkspaceName();
        } catch (\Throwable) {
            $sharedWorkspaceName = '(unresolved)';
        }

        try {
            $topicWorkspaceName = $this->sharedWorkspaceContextFactory->getTopicWorkspace()?->getName();
        } catch (\Throwable) {
            $topicWorkspaceName = null;
        }

        $workspaceDescription = $baseWorkspaceName === null
            ? \sprintf('Your own workspace is "%s".', $workspaceName)
            : \sprintf(
                'Your own workspace is "%s", based on "%s".',
                $workspaceName,
                $baseWorkspaceName
            );

        $topicDescription = $topicWorkspaceName === null
            ? 'You are not working on a topic at the moment.'
            : \sprintf('You are working on the topic workspace "%s".', $topicWorkspaceName);

        return 'You work in two workspaces, and the difference matters:
            ' . $workspaceDescription . ' All of your regular read and write tools (find-children, find-descendants,
            find-subtree, BulkWriting, CreateNodeAggregateWithNode, SetNodeProperties, SetNodeReferences) address your
            own workspace: they write into it, and what they read is your own workspace\'s view. They cannot address the
            shared review workspace or the live workspace.
            The shared review workspace is "' . $sharedWorkspaceName . '". Humans review and publish it.
            ' . $topicDescription . ' The workspace you publish into is the one you are based on: the shared review
            workspace, unless you work on a topic, then the topic workspace - a branch of the shared review workspace.
            Two agents - or an agent and a human - working on different topics then do not write over each other.
            - To create a topic workspace, call CreateTopicWorkspace with the topic, for example
              "Zahnzusatzversicherung". The name of the workspace is derived from the topic, so the same topic always
              maps to the same workspace: calling it again for a topic that has a workspace already returns that
              workspace instead of creating a second one. The workspace is not active after the call.
            - To work on a topic, call RebaseOwnWorkspace with the name the topic workspace has. It also takes the name
              of the shared review workspace, to go back to it - and nothing else. Rebase before you start working on
              the topic, not in the middle of it: your own unpublished changes stay in your workspace, but they are
              relative to the new base from now on, and content that only existed in the previous base is no longer
              part of your view.
            - Do the work in your own workspace, and hand it over when a coherent unit is really done - one finished
              page, say, not every single node edit. Humans review what lands there, so a package should be
              something they can judge as a whole.
            - To hand a package over, call PublishOwnWorkspace. It is exactly what an editor does when they are
              done: the content leaves your workspace and appears in the workspace you are based on, where a human
              reviews it and publishes it further. Pass no node aggregate ids to publish everything you have, or the
              ids of the nodes you want to publish. With dryRun set to true nothing is published and you get the
              list of what would be.
            - A publish empties your workspace for what has been published: ListUnpublishedChanges reports what is
              left. Your own read tools still see the published content, because it is inherited from the workspace
              you are based on - under the same node aggregate ids.
            - To correct something you have already published, change it in your own workspace and publish it again:
              the publish replaces what is already there. Do not try to revert it in the workspace you published into.
            - To remove content from your own workspace, use RemoveNode. That is also a permanent deletion; use it for
              content you created yourself that must not end up in the workspace you publish into.
            - Both removal tools only accept nodes that belong to the workspace they address. A node you did not
              create there - an inherited, already published node - is refused, and removing it is not what you want
              in the first place: in your own workspace, DiscardChanges restores an edited node to its published state
              instead of deleting it.
            - A move leaves two changes behind: the node is created at the new position and removed at the old one.
              ListUnpublishedChanges reports both.
            - To remove content from the workspace you publish into, use RemoveFromSharedWorkspace. That is a permanent
              deletion in a workspace humans work in, so read it first and remove the smallest possible node. There is
              no undo for it, which is another reason to hand over only finished packages.
            - To see everything you changed in your own workspace, use ListUnpublishedChanges. It reports the difference
              between your workspace and the workspace it is based on, which is the same list of changes you would
              publish.
            - To undo your own changes, use DiscardChanges. It restores content to its state in the base workspace,
              which is the opposite of RemoveNode: reverting a node you created removes it, reverting a node you edited
              restores it. Prefer DiscardChanges when you are unsure, and prefer an explicit list of node aggregate ids
              over discardAll.
            - To see how a document looks, call PreviewDocument with the node aggregate id. It returns the URL of the
            . preview - the rendered page, not the content of the document. Fetch the URL with the same token you use for
            . this connection, otherwise the frontend answers with the login page instead of the page:
            . curl -H "Authorization: Bearer <your token>" "<the url>"
            . The preview of your own workspace shows your changes, the preview of the workspace you hand your work over
            . to shows the state a reviewer sees.
            You never publish into the live workspace, and you never publish into a workspace you are not based on.
            The only publish you can do is into the workspace you are based on; a human decides whether it goes live.';
    }
}
