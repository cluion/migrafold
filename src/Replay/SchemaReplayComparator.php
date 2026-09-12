<?php

declare(strict_types=1);

namespace Cluion\Migrafold\Replay;

use Cluion\Migrafold\Replay\Exception\ReplayVerificationFailed;
use Cluion\Migrafold\Schema\Definition\SchemaSnapshot;

final readonly class SchemaReplayComparator
{
    public function assertEquivalent(SchemaSnapshot $source, SchemaSnapshot $baseline): void
    {
        if ($source->driver !== $baseline->driver) {
            throw ReplayVerificationFailed::because(
                "source driver [{$source->driver}] does not match baseline driver [{$baseline->driver}].",
            );
        }

        $sourceFingerprint = $source->fingerprint();
        $baselineFingerprint = $baseline->fingerprint();

        if (! hash_equals($sourceFingerprint, $baselineFingerprint)) {
            throw ReplayVerificationFailed::because(
                "source schema fingerprint [{$sourceFingerprint}] does not match baseline schema fingerprint [{$baselineFingerprint}].",
            );
        }
    }
}
