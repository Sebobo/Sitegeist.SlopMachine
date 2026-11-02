<?php

declare(strict_types=1);

namespace Sitegeist\SlopMachine\Domain;

use Neos\ContentRepository\Domain\Service\ContentDimensionPresetSourceInterface;
use Neos\Flow\Annotations as Flow;

/**
 * Resolves a dimension space point against the content dimensions of the installation.
 *
 * A dimension space point names the dimension values - the ones the dimensionspace tool
 * reports - and the values of the preset that contains them are the dimensions of a content
 * context. The point therefore has to be resolved against the values of the presets, not
 * against their identifiers: an installation defines the presets, but the values are what a
 * caller sees and passes on.
 */
#[Flow\Scope('singleton')]
class DimensionSpacePointResolver
{
    public function __construct(
        protected ContentDimensionPresetSourceInterface $contentDimensionPresetSource,
    ) {
    }

    /**
     * @param array<string,mixed> $dimensionSpacePoint
     * @return array<string,array<int,string>> the dimension values per dimension
     */
    public function resolve(array $dimensionSpacePoint): array
    {
        $presets = $this->contentDimensionPresetSource->getAllPresets();
        $dimensions = [];

        foreach ($dimensionSpacePoint as $dimensionName => $dimensionValue) {
            if (!isset($presets[$dimensionName])) {
                throw new \RuntimeException(\sprintf(
                    'This installation has no content dimension "%s". %s Call the dimensionspace tool to get the'
                    . ' dimension space points that can be used.',
                    $dimensionName,
                    $presets === []
                        ? 'It defines no content dimensions at all, so the only valid dimension space point is an'
                        . ' empty object ({}).'
                        : 'The dimensions it defines are: ' . \implode(', ', \array_keys($presets)) . '.'
                ), 1421329001);
            }
            $values = $this->valuesOfPreset($presets[$dimensionName]['presets'] ?? [], $dimensionValue);
            if ($values === null) {
                throw new \RuntimeException(\sprintf(
                    'The content dimension "%s" has no value "%s". The values it allows are: %s.'
                    . ' Call the dimensionspace tool to get the dimension space points that can be used.',
                    $dimensionName,
                    \is_string($dimensionValue) ? $dimensionValue : \gettype($dimensionValue),
                    \implode(', ', $this->allowedValues($presets[$dimensionName]['presets'] ?? []))
                ), 1421329002);
            }
            $dimensions[$dimensionName] = $values;
        }

        $missingDimensions = \array_diff(\array_keys($presets), \array_keys($dimensionSpacePoint));
        if ($missingDimensions !== []) {
            throw new \RuntimeException(\sprintf(
                'The dimension space point is incomplete, it does not cover %s.'
                . ' Call the dimensionspace tool to get the dimension space points that can be used.',
                \implode(', ', $missingDimensions)
            ), 1421329003);
        }

        return $dimensions;
    }

    /**
     * The values of the preset that contains the given dimension value.
     *
     * @param array<string,array<string,mixed>> $presets
     * @return array<int,string>|null the values of the preset, null when no preset has the value
     */
    private function valuesOfPreset(array $presets, string $dimensionValue): ?array
    {
        foreach ($presets as $preset) {
            $values = (array)($preset['values'] ?? []);
            if (\in_array($dimensionValue, $values, true)) {
                return \array_values($values);
            }
        }

        return null;
    }

    /**
     * All the values the presets of a dimension allow.
     *
     * @param array<string,array<string,mixed>> $presets
     * @return list<string>
     */
    private function allowedValues(array $presets): array
    {
        $values = [];
        foreach ($presets as $preset) {
            foreach ((array)($preset['values'] ?? []) as $value) {
                if (\is_string($value) && !\in_array($value, $values, true)) {
                    $values[] = $value;
                }
            }
        }

        return $values;
    }
}
