<?php

declare(strict_types=1);

namespace Sitegeist\SlopMachine\Domain;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\ToolAnnotations;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Flow\ResourceManagement\ResourceManager;
use Neos\Media\Domain\Model\Asset;
use Neos\Media\Domain\Model\AssetCollection;
use Neos\Media\Domain\Model\Tag;
use Neos\Media\Domain\Repository\AssetCollectionRepository;
use Neos\Media\Domain\Repository\AudioRepository;
use Neos\Media\Domain\Repository\DocumentRepository;
use Neos\Media\Domain\Repository\ImageRepository;
use Neos\Media\Domain\Repository\TagRepository;
use Neos\Media\Domain\Repository\VideoRepository;

#[Flow\Proxy(false)]
class MediaReadingCapabilities
{
    public const FIND_ASSET_COLLECTIONS_URI = 'media://find-assetcollections';
    public const FIND_TAGS_URI = 'media://find-tags';
    public const FIND_TYPES_URI = 'media://find-types';
    public const FIND_ASSETS_URI = 'media://find-assets/{assetCollection}/{tag}/{type}';

    /**
     * The media types the assets are listed by.
     */
    public const MEDIA_TYPES = ['Audio', 'Document', 'Image', 'Video'];

    public function __construct(
        protected AudioRepository $audioRepository,
        protected DocumentRepository $documentRepository,
        protected ImageRepository $imageRepository,
        protected VideoRepository $videoRepository,
        protected AssetCollectionRepository $assetCollectionRepository,
        protected TagRepository $tagRepository,
        protected PersistenceManagerInterface $persistenceManager,
        protected ResourceManager $resourceManager,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    #[McpTool(
        name: 'find-asset-collections',
        description: 'A list of all available asset collections. Asset collections contain media assets like images, documents etc.',
        annotations: new ToolAnnotations(
            readOnlyHint: true,
            destructiveHint: false,
            idempotentHint: true,
            openWorldHint: false,
        )
    )]
    public function findAssetCollections(): array
    {
        try {
            $payload = [];
            foreach ($this->assetCollectionRepository->findAll() as $assetCollection) {
                /** @var AssetCollection $assetCollection */
                $payload[] = [
                    'id' => $this->persistenceManager->getIdentifierByObject($assetCollection),
                    'title' => $assetCollection->getTitle(),
                ];
            }

            return [
                'uri' => self::FIND_ASSET_COLLECTIONS_URI,
                'name' => 'Asset collections',
                'description' => 'A list of all available asset collections. The id identifies the asset collection and is to be sent as parameter for subsequent calls.',
                'mimeType' => 'application/json',
                'text' => \json_encode($payload),
            ];
        } catch (\Throwable $throwable) {
            return $this->failure($throwable);
        }
    }

    /**
     * @return array<string,mixed>
     */
    #[McpTool(
        name: 'find-tags',
        description: 'A list of all available tags. Tags are used to group media assets, for example "mcp-upload" for the assets an agent uploaded.',
        annotations: new ToolAnnotations(
            readOnlyHint: true,
            destructiveHint: false,
            idempotentHint: true,
            openWorldHint: false,
        )
    )]
    public function findTags(): array
    {
        try {
            $payload = [];
            foreach ($this->tagRepository->findAll() as $tag) {
                /** @var Tag $tag */
                $payload[] = [
                    'id' => $this->persistenceManager->getIdentifierByObject($tag),
                    'label' => $tag->getLabel(),
                ];
            }

            return [
                'uri' => self::FIND_TAGS_URI,
                'name' => 'Tags',
                'description' => 'A list of all available tags. The id identifies the tag and is to be sent as parameter for subsequent calls.',
                'mimeType' => 'application/json',
                'text' => \json_encode($payload),
            ];
        } catch (\Throwable $throwable) {
            return $this->failure($throwable);
        }
    }

    /**
     * @return array<string,mixed>
     */
    #[McpTool(
        name: 'find-types',
        description: 'A list of the media types the assets are listed by: Audio, Document, Image and Video.',
        annotations: new ToolAnnotations(
            readOnlyHint: true,
            destructiveHint: false,
            idempotentHint: true,
            openWorldHint: false,
        )
    )]
    public function findTypes(): array
    {
        return [
            'uri' => self::FIND_TYPES_URI,
            'name' => 'Media types',
            'description' => 'A list of the media types the assets are listed by. The value is to be sent as parameter for subsequent calls.',
            'mimeType' => 'application/json',
            'text' => \json_encode(self::MEDIA_TYPES),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    #[McpTool(
        name: 'find-assets',
        description: 'A list of all available assets. Can be filtered by asset collection, tag or type. The asset collection and tag parameters are optional and can be set to * if to be ignored. Each parameter has its own resource to determine the available values.
            The type is one of Audio, Document, Image or Video - the list the find-types tool returns. The id of an asset is what an image property is set to.',
        annotations: new ToolAnnotations(
            readOnlyHint: true,
            destructiveHint: false,
            idempotentHint: true,
            openWorldHint: false,
        )
    )]
    public function findAssets(
        string $type,
        ?string $assetCollection = null,
        ?string $tag = null,
    ): array {
        try {
            $type = $this->normalizeMediaType(\urldecode($type));
            $query = match ($type) {
                'Audio' => $this->audioRepository->createQuery(),
                'Document' => $this->documentRepository->createQuery(),
                'Image' => $this->imageRepository->createQuery(),
                'Video' => $this->videoRepository->createQuery(),
                default => throw new \RuntimeException(\sprintf(
                    'Invalid type "%s", must be one of %s.',
                    $type,
                    \implode(', ', self::MEDIA_TYPES)
                )),
            };
            $filters = [];
            $assetCollection = $assetCollection ? \urldecode($assetCollection) : $assetCollection;
            if ($assetCollection && $assetCollection !== '*') {
                $filters[] = $query->contains('assetCollections', $assetCollection);
            }
            $tag = $tag ? \urldecode($tag) : $tag;
            if ($tag && $tag !== '*') {
                $filters[] = $query->contains('tags', $tag);
            }

            $payload = [];
            $assets = $filters === []
                ? $query->execute()
                : $query->matching($query->logicalAnd(...$filters))->execute();
            foreach ($assets as $asset) {
                /** @var Asset $asset */
                $payload[] = [
                    'id' => $asset->getIdentifier(),
                    'uri' => $this->resourceManager->getPublicPersistentResourceUri($asset->getResource()),
                    'title' => $asset->getTitle(),
                    'caption' => $asset->getCaption(),
                    'copyrightNotice' => $asset->getCopyrightNotice(),
                    'mediaType' => $asset->getMediaType(),
                ];
            }

            return [
                'uri' => self::FIND_ASSETS_URI,
                'name' => 'Assets',
                'description' => 'A list of assets. The id identifies the asset if it is to be referenced. The uri can be used to evaluate the file contents.',
                'mimeType' => 'application/json',
                'text' => \json_encode($payload),
            ];
        } catch (\Throwable $throwable) {
            return $this->failure($throwable);
        }
    }

    /**
     * The media type in the spelling the media types are listed by.
     *
     * The type arrives as a free string, and an agent that writes "image" instead of "Image"
     * should not be told that its call failed - the type it means is obvious.
     */
    private function normalizeMediaType(string $type): string
    {
        foreach (self::MEDIA_TYPES as $mediaType) {
            if (\strcasecmp($mediaType, $type) === 0) {
                return $mediaType;
            }
        }

        return $type;
    }

    /**
     * The answer of a call that failed.
     *
     * The other capabilities of the agent report a failure like this, and an exception that
     * leaves the tool call ends in the generic "the tool failed" of the protocol - which tells
     * the agent nothing about what went wrong.
     *
     * @return array<string,mixed>
     */
    private function failure(\Throwable $throwable): array
    {
        return [
            'success' => false,
            'message' => $throwable->getMessage(),
        ];
    }
}
