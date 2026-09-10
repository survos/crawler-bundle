<?php

declare(strict_types=1);

namespace Survos\CrawlerBundle\Model;

/**
 * One URL seen by the crawler, for one user.
 *
 * A plain data holder: every field is public, and jsonSerialize() decides what reaches
 * tests/crawldata.json. That split matters. The shape of that file used to be an accident
 * of property visibility, which is how $duration and $visits came to be measured on every
 * request and then silently dropped for being private. Add a field to the payload
 * deliberately, by naming it below -- not by changing a keyword.
 */
class Link implements \JsonSerializable
{
    public const STATUS_IGNORED = 'ignored';
    public const STATUS_ALREADY_VISITED = 'already_visited';

    public function __construct(
        public string $path,
        public bool $seen = false,
        public int $depth = 0,
        public ?string $linkStatus = null,
        public ?string $username = null,
        public ?string $route = null,
        /** @var array<string,mixed>|null Route parameters. */
        public ?array $rp = null,
        /** The whole response body. Kept out of jsonSerialize() -- it would dwarf the file. */
        public ?string $html = null,
        /** Milliseconds: CrawlerService measures with microtime(true) * 1000. */
        public ?float $duration = null,
        public ?int $statusCode = null,
        public ?string $foundOn = null,
        /** Memory in use at the time of the request, in MB. */
        public ?int $memory = null,
        public int $visits = 0,
    ) {
    }

    /** Queued, but not yet crawled. */
    public bool $pending {
        get => !$this->seen;
    }

    /**
     * Crawled, and not skipped for being ignored or already visited -- so it is a real
     * result, worth asserting in a generated test.
     */
    public bool $testable {
        get => $this->seen
            && !in_array($this->linkStatus, [self::STATUS_IGNORED, self::STATUS_ALREADY_VISITED], true);
    }

    public function incVisits(): static
    {
        ++$this->visits;

        return $this;
    }

    public function recordMemory(): static
    {
        $this->memory = (int) round(memory_get_usage() / 1048576, 2);

        return $this;
    }

    /**
     * The crawldata.json payload, listed explicitly so the file's shape is a decision
     * rather than a side effect. $html is far too large for it, and $pending / $testable
     * are derived -- cheaper to re-derive on read than to store.
     *
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'path' => $this->path,
            'seen' => $this->seen,
            'depth' => $this->depth,
            'linkStatus' => $this->linkStatus,
            'username' => $this->username,
            'route' => $this->route,
            'rp' => $this->rp,
            'duration' => $this->duration,
            'statusCode' => $this->statusCode,
            'foundOn' => $this->foundOn,
            'memory' => $this->memory,
            'visits' => $this->visits,
        ];
    }
}
