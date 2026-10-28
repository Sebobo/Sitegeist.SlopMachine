<?php

declare(strict_types=1);

namespace Sitegeist\SlopMachine\Domain;

use Neos\ContentRepository\Domain\Model\Node;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Repository\NodeDataRepository;
use Neos\Neos\Domain\Service\ContentContext;

/**
 * The shared parts of the read capabilities: how a result set is cut into pages and how the
 * content of a single document is scoped.
 *
 * The content repository cannot paginate a recursive query. Its recursive lookup matches a path
 * prefix and then reduces the result in PHP - it picks the best workspace and dimension variant
 * per node and drops the removed ones - so a limit pushed into the query would cut off rows
 * before that reduction has decided which variant is the right one. A limit is therefore only
 * safe after the reduction and the sort, which is where this class applies it. The full result
 * set is still materialised in memory; pagination skips the serialisation, not the query.
 *
 * The content repository does paginate the direct children of a node
 * (`Node::getChildNodes($filter, $limit, $offset)`, which the framework's own PaginateController
 * is built on), and that is what the document queries are built on.
 */
final class ReadPagination
{
    /**
     * The number of nodes a read returns when the caller does not ask for a different number.
     */
    public const DEFAULT_LIMIT = 200;

    /**
     * The key under which the cursor of a result set is reported.
     */
    public const CURSOR_KEY = 'nextCursor';

    public function __construct(
        protected NodeDataRepository $nodeDataRepository,
    ) {
    }

    /**
     * Cuts a result set into one page and reports whether more nodes follow.
     *
     * The cursor is the position to resume from: the index of the last node of the page and its
     * aggregate id. The content repository sorts the nodes of a subtree by their index and breaks
     * ties by the aggregate id, so that pair identifies a position unambiguously.
     *
     * The cursor is a best effort resume position, not a snapshot: if nodes are added to or
     * removed from the subtree between two calls, the second call resumes from where the first
     * one stopped, and a node that moved past that position in the meantime is not seen again.
     *
     * @param array<int,mixed> $items
     * @param int $limit
     * @param null|callable(mixed):array{0: int, 1: string} $positionOf extracts the sort position of an item
     * @return array{returnedCount: int, truncated: bool, nextCursor: ?array<string,mixed>, nodes: array<int,mixed>}
     */
    public function paginate(array $items, int $limit, ?callable $positionOf = null): array
    {
        if ($limit < 1) {
            $limit = self::DEFAULT_LIMIT;
        }
        $positionOf = $positionOf ?? self::nodePosition();

        $total = \count($items);
        $page = \array_slice($items, 0, $limit);
        $truncated = $total > $limit;

        $nextCursor = null;
        if ($truncated) {
            $last = $page[\count($page) - 1] ?? null;
            if ($last !== null) {
                [$index, $aggregateId] = $positionOf($last);
                $nextCursor = [
                    'index' => $index,
                    'aggregateId' => $aggregateId,
                ];
            }
        }

        return [
            'returnedCount' => \count($page),
            'truncated' => $truncated,
            self::CURSOR_KEY => $nextCursor,
            'nodes' => $page,
        ];
    }

    /**
     * Keeps everything that comes after the node the cursor points at.
     *
     * The cursor holds the index and the aggregate id of the last node of the previous page, so
     * the next page is what follows it: a node is kept when its index is higher than the
     * cursor's, or when it has the same index and an aggregate id that sorts after the cursor's.
     * The result set is sorted by that pair, so what is kept is exactly the continuation.
     *
     * A cursor whose node is not in the set any more - it was removed in the meantime - is
     * applied as far as it still matches, so the result stays close to where the previous page
     * ended.
     *
     * @param array<int,mixed> $items
     * @param array<string,mixed>|null $cursor
     * @param null|callable(mixed):array{0: int, 1: string} $positionOf
     * @return array<int,mixed>
     */
    public function applyCursor(array $items, ?array $cursor, ?callable $positionOf = null): array
    {
        if (!\is_array($cursor) || !isset($cursor['index'], $cursor['aggregateId'])) {
            return $items;
        }
        $positionOf = $positionOf ?? self::nodePosition();

        $cursorIndex = (int)$cursor['index'];
        $cursorId = (string)$cursor['aggregateId'];

        $result = [];
        foreach ($items as $item) {
            [$index, $aggregateId] = $positionOf($item);
            if ($index > $cursorIndex) {
                $result[] = $item;
                continue;
            }
            if ($index === $cursorIndex && \strcmp($aggregateId, $cursorId) > 0) {
                $result[] = $item;
            }
        }

        return $result;
    }

    /**
     * The sort position of a node: its index, and its aggregate id as the tie breaker.
     *
     * @return callable(mixed):array{0: int, 1: string}
     */
    private static function nodePosition(): callable
    {
        return static function (mixed $item): array {
            if ($item instanceof NodeInterface) {
                return [$item->getIndex(), $item->getIdentifier()];
            }

            return [0, ''];
        };
    }

    /**
     * The direct children of a node, filtered by node type.
     *
     * The query goes through the repository rather than through Node::getChildNodes(), because
     * that one reads from the first level node cache, which can hold an empty result for a path
     * that does have children - the read tools then report a node as childless.
     *
     * @return array<int,NodeInterface>
     */
    public function findChildren(ContentContext $contentContext, NodeInterface $parentNode, string $nodeTypeFilter): array
    {
        return $this->nodeDataRepository->findByParentAndNodeTypeInContext(
            $parentNode->getPath(),
            $nodeTypeFilter,
            $contentContext,
            false
        );
    }

    /**
     * All descendants of a node, filtered by node type.
     *
     * @return array<int,NodeInterface>
     */
    public function findDescendants(ContentContext $contentContext, NodeInterface $ancestorNode, string $nodeTypeFilter): array
    {
        return $this->nodeDataRepository->findByParentAndNodeTypeInContext(
            $ancestorNode->getPath(),
            $nodeTypeFilter,
            $contentContext,
            true
        );
    }
}
