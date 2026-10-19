<?php

declare(strict_types=1);

namespace Sitegeist\SlopMachine\Domain;

use Neos\ContentRepository\Domain\Model\Node;
use Neos\ContentRepository\Domain\Model\Workspace;

/**
 * Guards that both removal capabilities (the agent's own workspace and the shared review
 * workspace) apply before touching a node, so the rules cannot drift apart between them.
 */
final class NodeRemoval
{
    /**
     * The workspace root is the anchor of every path in the workspace; removing it would leave
     * the workspace unusable rather than delete a piece of content.
     */
    public const ROOT_PATH = '/';

    /**
     * Sites are the entry points of the Neos UI and of the whole content tree. An agent editing
     * content has no reason to remove one, and it is not recoverable by a human reviewing the
     * shared workspace without knowing which site was dropped.
     */
    public const SITE_NODE_TYPE = 'Neos.Neos:Site';

    private function __construct()
    {
    }

    /**
     * @param Workspace $workspace The workspace the node is addressed in, i.e. the workspace the
     *                             removal is performed in - not the workspace the node is stored in
     */
    public static function assertRemovable(Node $node, Workspace $workspace): void
    {
        if ($node->getPath() === self::ROOT_PATH) {
            throw new \RuntimeException('The workspace root node cannot be removed.', 1758250021);
        }

        if ($node->getNodeType()->isOfType(self::SITE_NODE_TYPE)) {
            throw new \RuntimeException(
                \sprintf('Site nodes (%s) cannot be removed by an agent.', self::SITE_NODE_TYPE),
                1758250022
            );
        }

        $ownWorkspaceName = $workspace->getName();
        if ($node->getWorkspace()->getName() !== $ownWorkspaceName) {
            throw new \RuntimeException(
                \sprintf(
                    'The node at %s is not part of the workspace "%s" itself but inherited from "%s".'
                    . ' Removing it would turn inherited content into a removal, which is not what removal is for.'
                    . ' Use DiscardChanges in your own workspace to restore inherited content to its published state.',
                    $node->getPath(),
                    $ownWorkspaceName,
                    $node->getWorkspace()->getName()
                ),
                1758250023
            );
        }
    }
}
