<?php

namespace App\Support\ThreeD;

/**
 * One past 3D draw, normalized away from the vendor's field names.
 *
 * The upstream calls these `result` and `datetime`; clients see `threed` and
 * `stock_date`, matching the shape they already consume from the admin-entered
 * ThreeDResult records so a page can render either source.
 */
class ThreeDHistoryEntry
{
    public function __construct(
        public readonly string $threed,
        public readonly string $stockDate,
    ) {}

    /**
     * @return array{threed: string, stock_date: string}
     */
    public function toArray(): array
    {
        return [
            'threed' => $this->threed,
            'stock_date' => $this->stockDate,
        ];
    }
}
