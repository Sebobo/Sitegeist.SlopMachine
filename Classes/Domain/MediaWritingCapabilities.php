<?php

declare(strict_types=1);

namespace Sitegeist\SlopMachine\Domain;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\ToolAnnotations;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Flow\ResourceManagement\ResourceManager;
use Neos\Flow\Security\Context as SecurityContext;
use Neos\Media\Domain\Model\Asset;
use Neos\Media\Domain\Model\AssetCollection;
use Neos\Media\Domain\Model\Tag;
use Neos\Media\Domain\Repository\AssetCollectionRepository;
use Neos\Media\Domain\Repository\AssetRepository;
use Neos\Media\Domain\Repository\TagRepository;
use Neos\Media\Domain\Service\AssetService;
use Neos\Media\Domain\Strategy\AssetModelMappingStrategyInterface;
use Neos\Utility\MediaTypes;

/**
 * Uploading assets for the agent.
 *
 * Assets are not part of the content repository. They are not versioned, they are not
 * workspace aware, and a file uploaded here is immediately part of the live media
 * collection. No tool can undo that: DiscardChanges and RemoveNode only reach nodes. Two
 * things keep the uploads manageable anyway - every upload goes into one dedicated asset
 * collection, and every upload carries a tag, so a human can find and remove them.
 *
 * The upload is deliberately narrow. The bytes come with the call, because fetching them
 * from a URL would be a server side request forgery vector. The media type is determined
 * from the content and checked against a list of allowed types, and the size is capped.
 * Files are stored byte exact, nothing is re-encoded, so no metadata is lost.
 */
#[Flow\Proxy(false)]
class MediaWritingCapabilities
{
    /**
     * The largest accepted upload, in bytes. The schema limit on the base64 parameter is
     * the same size in base64 characters, so an oversized payload is rejected before
     * anything is decoded.
     */
    private const MAX_BASE64_LENGTH = 7000000;

    public function __construct(
        protected MediaUploadSettings $settings,
        protected ResourceManager $resourceManager,
        protected AssetRepository $assetRepository,
        protected AssetCollectionRepository $assetCollectionRepository,
        protected TagRepository $tagRepository,
        protected AssetModelMappingStrategyInterface $assetModelMappingStrategy,
        protected AssetService $assetService,
        protected AgentWorkspaceResolver $agentWorkspaceResolver,
        protected PersistenceManagerInterface $persistenceManager,
        protected SecurityContext $securityContext,
    ) {
    }

    /**
     * Uploads a file into the media collection and returns its identifier.
     *
     * The file content travels base64 encoded in the contentBase64 parameter, because the
     * arguments of a tool call are JSON. Encode the raw bytes, without a data URI prefix -
     * a prefix is tolerated, but the plain form is what the description asks for.
     *
     * The asset is not part of any workspace: it is live the moment the call returns, and
     * DiscardChanges or RemoveNode cannot take it back. It goes into the asset collection
     * "MCP Uploads" and carries the tag "mcp-upload" plus a second tag naming the agent,
     * so that agent uploads can be found and removed again.
     *
     * Uploading the same file twice returns the asset that already exists instead of
     * creating a second copy. Such an asset keeps the tags and the collection it had - it
     * was not uploaded by this call, so it is not treated as one.
     *
     * To put the asset on a page, pass the returned assetId as the value of the image
     * property with SetNodeProperties.
     *
     * @param string $filename The name of the file, with its extension. It decides the media type the file is served with.
     * @param string $contentBase64 The file content, base64 encoded.
     * @param string $title The title of the asset. Defaults to the file name without its extension.
     * @return array<string,mixed>
     */
    #[McpTool(
        name: 'upload-asset',
        description: 'Uploads a file into the media collection and returns the identifier of the new asset.
            The file content travels base64 encoded in the contentBase64 parameter, because the arguments of a tool call are JSON.
            The asset is not part of any workspace: it is live the moment this call returns, and neither DiscardChanges nor RemoveNode
            can take it back. It goes into the asset collection "MCP Uploads" and carries the tag "mcp-upload" plus a second tag naming
            the agent, so agent uploads can be found and removed again.
            Uploading the same file twice returns the asset that already exists instead of creating a second copy.
            To put the asset on a page, pass the returned assetId as the value of the image property with SetNodeProperties.
            The file name decides the media type the file is served with, so it has to carry the right extension; the content is
            checked against that extension and against the list of allowed media types. Files larger than the configured maximum
            (5 MB by default) are rejected.',
        annotations: new ToolAnnotations(
            readOnlyHint: false,
            destructiveHint: false,
            idempotentHint: true,
            openWorldHint: false,
        )
    )]
    public function uploadAsset(
        #[Schema(
            type: 'string',
            description: 'The name of the file, with its extension. It decides the media type the file is served with. Only letters, digits, dots, dashes and underscores are allowed.',
            maxLength: 255,
        )]
        string $filename,
        #[Schema(
            type: 'string',
            description: 'The file content, base64 encoded.',
            maxLength: self::MAX_BASE64_LENGTH,
        )]
        string $contentBase64,
        #[Schema(
            type: 'string',
            description: 'The title of the asset. Defaults to the file name without its extension.',
            maxLength: 255,
        )]
        string $title = '',
    ): array {
        $payload = $this->handleUploadAsset($filename, $contentBase64, $title);

        return [
            'content' => [
                [
                    'type' => 'text',
                    'text' => \json_encode($payload),
                ],
            ],
            'structuredContent' => $payload,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function handleUploadAsset(string $filename, string $contentBase64, string $title): array
    {
        try {
            $safeFilename = $this->sanitizeFilename($filename);
            $content = $this->decodeContent($contentBase64);
            $this->assertAllowedMediaType($this->detectMediaType($content, $safeFilename));

            $existingAsset = $this->assetRepository->findOneByResourceSha1(\sha1($content));
            if ($existingAsset instanceof Asset) {
                // The very same file is already in the media collection - a repeated upload,
                // or a file a human uploaded before. Return that one instead of creating a
                // second copy, and leave it as it is: it was not uploaded by this call, so it
                // neither gets the upload tag nor moves into the upload collection.
                return $this->describeAsset($existingAsset, null, true);
            }

            $resource = $this->resourceManager->importResourceFromContent($content, $safeFilename);
            $assetClass = $this->assetModelMappingStrategy->map($resource);
            if (!\is_a($assetClass, Asset::class, true)) {
                throw new \RuntimeException(\sprintf(
                    'The asset model mapping produced "%s", which is not an asset.',
                    $assetClass
                ));
            }

            $asset = new $assetClass($resource);
            $asset->setTitle($title !== '' ? $title : \pathinfo($safeFilename, PATHINFO_FILENAME));
            $collection = $this->getOrCreateCollection();
            $collection->addAsset($asset);
            $this->applyTags($asset);
            $this->assetRepository->add($asset);
            $this->persistenceManager->persistAll();
            $this->assetService->emitAssetCreated($asset);

            return $this->describeAsset($asset, $collection, false);
        } catch (\Throwable $exception) {
            return [
                'success' => false,
                'message' => $exception->getMessage(),
            ];
        }
    }

    /**
     * The file name ends up in the public URI of the resource and decides the media type
     * the file is served with, so it is kept to a safe shape: no directory components, no
     * traversal, and nothing that would need escaping in a URI.
     */
    private function sanitizeFilename(string $filename): string
    {
        $filename = \trim($filename);
        if ($filename === '') {
            throw new \RuntimeException('The filename must not be empty.');
        }

        $filename = \basename(\str_replace('\\', '/', $filename));
        if ($filename === '' || $filename === '.' || $filename === '..') {
            throw new \RuntimeException(\sprintf('The filename "%s" is not a file name.', $filename));
        }

        if (!\preg_match('/^[A-Za-z0-9._-]+$/', $filename)) {
            throw new \RuntimeException(
                'The filename may only contain letters, digits, dots, dashes and underscores.'
            );
        }

        if (\strlen($filename) > 255) {
            throw new \RuntimeException('The filename must not be longer than 255 characters.');
        }

        if (!\str_contains($filename, '.')) {
            throw new \RuntimeException('The filename must carry a file extension.');
        }

        return $filename;
    }

    /**
     * Decodes the payload and makes sure it is not larger than the configured maximum.
     */
    private function decodeContent(string $contentBase64): string
    {
        if (\str_starts_with($contentBase64, 'data:')) {
            $separator = \strpos($contentBase64, ',');
            if ($separator === false) {
                throw new \RuntimeException('The data URI of the content is malformed.');
            }
            $contentBase64 = \substr($contentBase64, $separator + 1);
        }

        $encoded = \preg_replace('/\s+/', '', $contentBase64);
        $content = \base64_decode($encoded ?? '', true);
        if ($content === false) {
            throw new \RuntimeException('The content is not valid base64.');
        }

        if ($content === '') {
            throw new \RuntimeException('The content is empty.');
        }

        $maxFileSize = $this->settings->getMaxFileSize();
        if (\strlen($content) > $maxFileSize) {
            throw new \RuntimeException(\sprintf(
                'The file is %d bytes, which is larger than the %d bytes allowed.',
                \strlen($content),
                $maxFileSize
            ));
        }

        return $content;
    }

    /**
     * The media type of the content, checked against the one the file name claims.
     *
     * The type of the content is what decides whether a file is accepted, because a file
     * name can be anything. The type of the file name is what the asset is served with, so
     * both have to be allowed and they have to be the same kind of type - an image named
     * as a PDF would be served as a PDF.
     */
    private function detectMediaType(string $content, string $filename): string
    {
        $fileInfo = new \finfo(\FILEINFO_MIME_TYPE);
        $detected = \strtolower((string)$fileInfo->buffer($content));
        $fromFilename = \strtolower((string)MediaTypes::getMediaTypeFromFilename($filename));

        if ($this->isAllowedMediaType($detected) === false) {
            throw new \RuntimeException(\sprintf(
                'The content is of type "%s", which is not allowed. Allowed types: %s.',
                $detected,
                \implode(', ', $this->settings->getAllowedMediaTypes())
            ));
        }

        if ($this->isAllowedMediaType($fromFilename) === false) {
            throw new \RuntimeException(\sprintf(
                'Files of type "%s" are not allowed. Allowed types: %s.',
                $fromFilename,
                \implode(', ', $this->settings->getAllowedMediaTypes())
            ));
        }

        if ($this->getMajorMediaType($detected) !== $this->getMajorMediaType($fromFilename)) {
            throw new \RuntimeException(\sprintf(
                'The content is of type "%s" but the file name claims "%s".',
                $detected,
                $fromFilename
            ));
        }

        return $fromFilename;
    }

    private function assertAllowedMediaType(string $mediaType): void
    {
        if ($this->isAllowedMediaType($mediaType) === false) {
            throw new \RuntimeException(\sprintf(
                'The media type "%s" is not allowed. Allowed types: %s.',
                $mediaType,
                \implode(', ', $this->settings->getAllowedMediaTypes())
            ));
        }
    }

    private function isAllowedMediaType(string $mediaType): bool
    {
        return \in_array(\strtolower($mediaType), $this->settings->getAllowedMediaTypes(), true);
    }

    private function getMajorMediaType(string $mediaType): string
    {
        return \explode('/', $mediaType, 2)[0];
    }

    /**
     * The collection every upload goes into. It is created if it does not exist yet, so
     * the tool works on a fresh installation without a setup step.
     */
    private function getOrCreateCollection(): AssetCollection
    {
        $title = $this->settings->getCollectionTitle();
        $collection = $this->assetCollectionRepository->findOneByTitle($title);
        if (!$collection instanceof AssetCollection) {
            $collection = new AssetCollection($title);
            $this->assetCollectionRepository->add($collection);
        }

        return $collection;
    }

    /**
     * Tags the asset, so that agent uploads are easy to find in the media browser.
     *
     * Every upload carries the configured tag and a second one naming the agent, which
     * tells the uploads of one agent apart from the uploads of another.
     */
    private function applyTags(Asset $asset): void
    {
        $tagLabel = $this->settings->getTagLabel();
        $labels = [$tagLabel];

        $agentName = $this->getAgentName();
        if ($agentName !== '') {
            $labels[] = $tagLabel . '-' . $this->sanitizeTagLabel($agentName);
        }

        foreach ($labels as $label) {
            $asset->addTag($this->getOrCreateTag($label));
        }
    }

    private function getOrCreateTag(string $label): Tag
    {
        $tag = $this->tagRepository->findOneByLabel($label);
        if (!$tag instanceof Tag) {
            $tag = new Tag($label);
            $this->tagRepository->add($tag);
        }

        return $tag;
    }

    /**
     * The name the agent is known by.
     *
     * The account identifier of the agent is the bearer token it authenticates with, so it
     * must not be written down. The name of the Neos user the agent is assigned to
     * identifies it instead; without an assignment a short hash of the account identifier
     * tells the uploads of different agents apart without exposing the token.
     */
    private function getAgentName(): string
    {
        $username = $this->agentWorkspaceResolver->getAssignedUsername();
        if ($username !== null && $username !== '') {
            return $username;
        }

        $accountIdentifier = $this->securityContext->getAccount()?->getAccountIdentifier();
        if (!\is_string($accountIdentifier) || $accountIdentifier === '') {
            return '';
        }

        return \substr(\sha1($accountIdentifier), 0, 8);
    }

    /**
     * A tag label ends up in a label of the media browser, so it is kept to a safe shape.
     */
    private function sanitizeTagLabel(string $label): string
    {
        $label = \strtolower(\preg_replace('/[^a-z0-9._-]+/i', '-', $label) ?? '');

        return \trim($label, '-');
    }

    /**
     * @return array<string,mixed>
     */
    private function describeAsset(Asset $asset, ?AssetCollection $uploadedInto, bool $alreadyExisted): array
    {
        $collections = [];
        foreach ($asset->getAssetCollections() as $collection) {
            $collections[] = $this->describeCollection($collection);
        }
        if ($collections === [] && $uploadedInto instanceof AssetCollection) {
            // The collection an asset was added to is not part of the asset in memory yet,
            // so it is reported from here.
            $collections[] = $this->describeCollection($uploadedInto);
        }

        $tags = [];
        foreach ($asset->getTags() as $tag) {
            $tags[] = [
                'id' => $this->persistenceManager->getIdentifierByObject($tag),
                'label' => $tag->getLabel(),
            ];
        }

        return [
            'success' => true,
            'assetId' => $this->persistenceManager->getIdentifierByObject($asset),
            'title' => $asset->getTitle(),
            'mediaType' => $asset->getMediaType(),
            'assetClass' => \get_class($asset),
            'uri' => $this->resourceManager->getPublicPersistentResourceUri($asset->getResource()),
            'collections' => $collections,
            'tags' => $tags,
            'alreadyExisted' => $alreadyExisted,
        ];
    }

    /**
     * @return array{id: string, title: string}
     */
    private function describeCollection(AssetCollection $collection): array
    {
        return [
            'id' => $this->persistenceManager->getIdentifierByObject($collection),
            'title' => $collection->getTitle(),
        ];
    }
}
