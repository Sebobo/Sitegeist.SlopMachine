<?php

declare(strict_types=1);

namespace Sitegeist\SlopMachine\Domain;

use Neos\Flow\Annotations as Flow;

/**
 * The configuration of the media upload capability.
 *
 * A regular (proxied) Flow singleton rather than a #[Flow\Proxy(false)] capability, because
 * settings and other scalars cannot be injected into non-proxied classes.
 *
 * @see \Sitegeist\Pandora\Infrastructure\CapabilityHandlerContainer
 */
#[Flow\Scope('singleton')]
class MediaUploadSettings
{
    /**
     * Five megabytes - the largest accepted upload unless the installation configures
     * something else.
     */
    public const DEFAULT_MAX_FILE_SIZE = 5242880;

    public const DEFAULT_COLLECTION_TITLE = 'MCP Uploads';

    public const DEFAULT_TAG_LABEL = 'mcp-upload';

    /**
     * @param array<string,mixed> $mediaSettings Sitegeist:SlopMachine:agent:media
     */
    public function __construct(
        protected array $mediaSettings,
    ) {
    }

    /**
     * The largest accepted upload, in bytes.
     */
    public function getMaxFileSize(): int
    {
        $maxFileSize = $this->mediaSettings['maxFileSize'] ?? self::DEFAULT_MAX_FILE_SIZE;

        if (!\is_numeric($maxFileSize) || (int)$maxFileSize <= 0) {
            return self::DEFAULT_MAX_FILE_SIZE;
        }

        return (int)$maxFileSize;
    }

    /**
     * The title of the asset collection every upload goes into. The collection is created
     * if it does not exist yet.
     */
    public function getCollectionTitle(): string
    {
        $title = $this->mediaSettings['collectionTitle'] ?? self::DEFAULT_COLLECTION_TITLE;

        if (!\is_string($title) || $title === '') {
            return self::DEFAULT_COLLECTION_TITLE;
        }

        return $title;
    }

    /**
     * The media types that may be uploaded. The type of the *content* decides, not the file
     * name, so a renamed file does not slip through.
     *
     * @return list<string>
     */
    public function getAllowedMediaTypes(): array
    {
        $mediaTypes = $this->mediaSettings['allowedMediaTypes'] ?? [];
        if (!\is_array($mediaTypes)) {
            return [];
        }

        $result = [];
        foreach ($mediaTypes as $mediaType) {
            if (\is_string($mediaType) && $mediaType !== '') {
                $result[] = \strtolower(\trim($mediaType));
            }
        }

        return $result;
    }

    /**
     * The label of the tag every upload carries. A second tag naming the uploading
     * account is added on top of it.
     */
    public function getTagLabel(): string
    {
        $label = $this->mediaSettings['tagLabel'] ?? self::DEFAULT_TAG_LABEL;

        if (!\is_string($label) || $label === '') {
            return self::DEFAULT_TAG_LABEL;
        }

        return $label;
    }
}
