<?php

declare(strict_types=1);

namespace Sitegeist\SlopMachine\Tests\Functional;

use Sitegeist\SlopMachine\Domain\MediaReadingCapabilities;

/**
 * The tools that list the media.
 *
 * They report a failure like every other capability of the agent: with the reason, and not as
 * an exception that leaves the tool call with the generic "the tool failed" of the protocol.
 */
class MediaReadingCapabilitiesTest extends AgentTestCase
{
    public function testAnUnknownTypeIsReportedWithItsReason(): void
    {
        $capabilities = $this->capability(MediaReadingCapabilities::class);
        self::assertInstanceOf(MediaReadingCapabilities::class, $capabilities);

        $result = $capabilities->findAssets('image/png');

        self::assertFalse($result['success'] ?? true, 'The call reports that it failed.');
        self::assertStringContainsString(
            'must be one of',
            (string)($result['message'] ?? ''),
            'The reason names the types that are allowed.'
        );
    }

    public function testTheTypeIsAcceptedInAnyCase(): void
    {
        $capabilities = $this->capability(MediaReadingCapabilities::class);
        self::assertInstanceOf(MediaReadingCapabilities::class, $capabilities);

        $result = $capabilities->findAssets('image');

        self::assertArrayHasKey('text', $result, 'The call succeeds.');
        self::assertIsArray(
            \json_decode((string)$result['text'], true),
            'The assets are listed.'
        );
    }

    public function testTheMediaTypesAreListed(): void
    {
        $capabilities = $this->capability(MediaReadingCapabilities::class);
        self::assertInstanceOf(MediaReadingCapabilities::class, $capabilities);

        $result = $capabilities->findTypes();

        self::assertSame(MediaReadingCapabilities::FIND_TYPES_URI, $result['uri'], 'The tool reports its own resource.');
        self::assertSame(
            ['Audio', 'Document', 'Image', 'Video'],
            \json_decode((string)$result['text'], true)
        );
    }

    public function testTheTagsAreListed(): void
    {
        $capabilities = $this->capability(MediaReadingCapabilities::class);
        self::assertInstanceOf(MediaReadingCapabilities::class, $capabilities);

        $result = $capabilities->findTags();

        self::assertSame(MediaReadingCapabilities::FIND_TAGS_URI, $result['uri'], 'The tool reports its own resource.');
        self::assertIsArray(
            \json_decode((string)$result['text'], true),
            'The tags are listed.'
        );
    }
}
