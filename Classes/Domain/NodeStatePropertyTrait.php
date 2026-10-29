<?php

declare(strict_types=1);

namespace Sitegeist\SlopMachine\Domain;

use Neos\ContentRepository\Domain\Model\Node;

/**
 * The visibility state of a node is not a node type property.
 *
 * hidden, hiddenInIndex and the two visibility dates live in columns of their own and are
 * reached through dedicated setters. Writing them through setProperty() - which is what a
 * propertyValues map naturally does - stores them beside the real properties and leaves
 * the state untouched: the write reports success and nothing about the rendered page
 * changes. The Neos user interface avoids that by sending them with an underscore prefix
 * (_hidden), which ObjectAccess resolves onto the setters; an agent has no such
 * convention available, so the names are recognised here and routed to the setters.
 *
 * The values are read back through the getters for the same reason: the effective value
 * report compares what was asked for with what the node really holds, and the property
 * store always answers null for these names.
 */
trait NodeStatePropertyTrait
{
    /**
     * @var list<string>
     */
    private const NODE_STATE_PROPERTY_NAMES = [
        'hidden',
        'hiddenInIndex',
        'hiddenBeforeDateTime',
        'hiddenAfterDateTime',
    ];

    private function isNodeStateProperty(string $propertyName): bool
    {
        return in_array($propertyName, self::NODE_STATE_PROPERTY_NAMES, true);
    }

    private function setNodeStateProperty(Node $node, string $propertyName, mixed $value): void
    {
        if ($propertyName === 'hidden') {
            $node->setHidden($this->convertNodeStateFlag($propertyName, $value));

            return;
        }

        if ($propertyName === 'hiddenInIndex') {
            $node->setHiddenInIndex($this->convertNodeStateFlag($propertyName, $value));

            return;
        }

        if ($propertyName === 'hiddenBeforeDateTime') {
            $node->setHiddenBeforeDateTime($this->convertNodeStateDateTime($propertyName, $value));

            return;
        }

        $node->setHiddenAfterDateTime($this->convertNodeStateDateTime($propertyName, $value));
    }

    private function getNodeStateProperty(Node $node, string $propertyName): mixed
    {
        return match ($propertyName) {
            'hidden' => $node->isHidden(),
            'hiddenInIndex' => $node->isHiddenInIndex(),
            'hiddenBeforeDateTime' => $node->getHiddenBeforeDateTime(),
            'hiddenAfterDateTime' => $node->getHiddenAfterDateTime(),
            default => throw new \InvalidArgumentException(
                sprintf('%s is not a node state property.', $propertyName)
            ),
        };
    }

    private function convertNodeStateFlag(string $propertyName, mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if ($value === 1 || $value === 0) {
            return $value === 1;
        }

        if (is_string($value)) {
            $normalizedValue = strtolower(trim($value));
            if ($normalizedValue === 'true' || $normalizedValue === '1') {
                return true;
            }
            if ($normalizedValue === 'false' || $normalizedValue === '0') {
                return false;
            }
        }

        throw new \InvalidArgumentException(
            sprintf('%s expects true or false, got %s.', $propertyName, get_debug_type($value))
        );
    }

    private function convertNodeStateDateTime(string $propertyName, mixed $value): ?\DateTimeInterface
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value;
        }

        if (is_string($value)) {
            try {
                return new \DateTimeImmutable($value);
            } catch (\Exception $exception) {
                throw new \InvalidArgumentException(
                    sprintf('%s expects a parseable date, got "%s".', $propertyName, $value),
                    0,
                    $exception
                );
            }
        }

        throw new \InvalidArgumentException(
            sprintf('%s expects a date, got %s.', $propertyName, get_debug_type($value))
        );
    }
}
