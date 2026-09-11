<?php

declare(strict_types=1);

namespace Cluion\Migrafold\Schema\Definition;

final readonly class CapabilityReport
{
    /** @var list<string> */
    public array $supported;

    /** @var list<string> */
    public array $unsupported;

    /**
     * @param list<string> $supported
     * @param list<string> $unsupported
     */
    public function __construct(array $supported, array $unsupported)
    {
        $this->supported = $this->normalize($supported);
        $this->unsupported = $this->normalize($unsupported);
    }

    /**
     * @return array{supported: list<string>, unsupported: list<string>}
     */
    public function toArray(): array
    {
        return [
            'supported' => $this->supported,
            'unsupported' => $this->unsupported,
        ];
    }

    /**
     * @param list<string> $values
     * @return list<string>
     */
    private function normalize(array $values): array
    {
        $values = array_values(array_unique($values));
        sort($values, SORT_STRING);

        return $values;
    }
}
