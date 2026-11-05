<?php

namespace App\Services\Updates;

use App\Enums\UpdateChannel;

readonly class AvailableUpdate
{
    public function __construct(
        public string $version,
        public string $summary,
        public string $url,
        public string $published_at,
        public UpdateChannel $channel,
        public ?string $commit = null,
    ) {}

    /**
     * Plain-array form for caching: the cache refuses to unserialize objects
     * (`cache.serializable_classes` is false), so only scalars may be stored.
     *
     * @return array{version: string, summary: string, url: string, published_at: string, channel: string, commit?: string}
     */
    public function toArray(): array
    {
        $data = [
            'version' => $this->version,
            'summary' => $this->summary,
            'url' => $this->url,
            'published_at' => $this->published_at,
            'channel' => $this->channel->value,
        ];

        if ($this->commit !== null) {
            $data['commit'] = $this->commit;
        }

        return $data;
    }

    /**
     * Rebuild from `toArray()` output, or return null if the data is malformed.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): ?self
    {
        foreach (['version', 'summary', 'url', 'published_at', 'channel'] as $key) {
            if (! isset($data[$key]) || ! is_string($data[$key])) {
                return null;
            }
        }

        $channel = UpdateChannel::tryFrom($data['channel']);

        if ($channel === null) {
            return null;
        }

        return new self(
            version: $data['version'],
            summary: $data['summary'],
            url: $data['url'],
            published_at: $data['published_at'],
            channel: $channel,
            commit: isset($data['commit']) && is_string($data['commit']) ? $data['commit'] : null,
        );
    }
}
