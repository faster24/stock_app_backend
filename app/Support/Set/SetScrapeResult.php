<?php

namespace App\Support\Set;

/**
 * Normalized result of one SET scrape, decoded from the Node scraper's JSON.
 * All numeric fields are kept as strings so the PHP 2D formula parses them
 * exactly (no float coercion).
 */
final class SetScrapeResult
{
    public function __construct(
        public readonly int $httpStatus,
        public readonly ?string $marketStatus,
        public readonly ?string $marketDateTime,
        public readonly ?string $indexLast,
        public readonly ?string $indexOpen,
        public readonly ?string $value,
        public readonly ?string $computed2d,
        public readonly bool $stabilized,
        public readonly int $attempts,
        public readonly array $raw,
    ) {}

    public static function fromNode(array $json): self
    {
        $index = is_array($json['index'] ?? null) ? $json['index'] : [];

        return new self(
            httpStatus: (int) ($json['httpStatus'] ?? 0),
            marketStatus: isset($json['marketStatus']) ? (string) $json['marketStatus'] : null,
            marketDateTime: isset($json['marketDateTime']) ? (string) $json['marketDateTime'] : null,
            indexLast: isset($index['last']) ? (string) $index['last'] : null,
            indexOpen: isset($index['open']) ? (string) $index['open'] : null,
            value: isset($index['value']) ? (string) $index['value'] : null,
            computed2d: isset($json['computed2d']) ? (string) $json['computed2d'] : null,
            stabilized: (bool) ($json['stabilized'] ?? false),
            attempts: (int) ($json['attempts'] ?? 0),
            raw: $json,
        );
    }

    /** The index figure for a session's field ('open' or 'last'). */
    public function indexValue(string $field): ?string
    {
        return $field === 'open' ? $this->indexOpen : $this->indexLast;
    }
}
