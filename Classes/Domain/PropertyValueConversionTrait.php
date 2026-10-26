<?php

declare(strict_types=1);

namespace Sitegeist\SlopMachine\Domain;

use Neos\Flow\Validation\Error;
use Neos\Media\Domain\Model\ResourceBasedInterface;

/**
 * Converts a raw property value into the type the node type declares for it.
 *
 * A node type declares an image with the interface Neos\Media\Domain\Model\ImageInterface,
 * and class_exists() is false for an interface - so the asset id was stored as a plain
 * string instead of the asset it names. The property mapper does know how to turn an asset
 * id into an asset, so the interfaces that describe a media asset are converted as well.
 */
trait PropertyValueConversionTrait
{
    /**
     * @param string $propertyName
     * @param string|null $expectedType The property type of the node type, for example "string" or "Neos\Media\Domain\Model\ImageInterface"
     * @param mixed $value
     * @return mixed
     */
    private function convertPropertyValue(string $propertyName, ?string $expectedType, mixed $value): mixed
    {
        if ($expectedType === null || $expectedType === '') {
            return $value;
        }

        if (\interface_exists($expectedType) && \is_a($expectedType, ResourceBasedInterface::class, true)) {
            return $this->convertAssetPropertyValue($propertyName, $expectedType, $value);
        }

        if (\class_exists($expectedType) && !$value instanceof $expectedType) {
            return $this->propertyMapper->convert($value, $expectedType);
        }

        return $value;
    }

    /**
     * Turns an asset id into the asset it names and makes sure that the asset is of the
     * type the property expects - a document does not belong into an image property.
     *
     * @param string $propertyName
     * @param string $expectedType
     * @param mixed $value
     * @return mixed
     */
    private function convertAssetPropertyValue(string $propertyName, string $expectedType, mixed $value): mixed
    {
        if ($value instanceof $expectedType) {
            return $value;
        }

        try {
            $converted = $this->propertyMapper->convert($value, $expectedType);
        } catch (\Throwable $exception) {
            throw new \RuntimeException(\sprintf(
                'The value for property "%s" could not be read as an asset: %s',
                $propertyName,
                $exception->getMessage()
            ), 0, $exception);
        }

        if ($converted instanceof Error) {
            throw new \RuntimeException(\sprintf(
                'The value for property "%s" is not valid: %s',
                $propertyName,
                $converted->getMessage()
            ));
        }

        if (!$converted instanceof $expectedType) {
            throw new \RuntimeException(\sprintf(
                'The value for property "%s" is a %s, but the property expects a %s.',
                $propertyName,
                \get_class($converted),
                $expectedType
            ));
        }

        return $converted;
    }
}
