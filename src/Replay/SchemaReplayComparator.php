<?php

declare(strict_types=1);

namespace Cluion\Migrafold\Replay;

use Cluion\Migrafold\Replay\Exception\ReplayVerificationFailed;
use Cluion\Migrafold\Schema\Definition\SchemaSnapshot;

final readonly class SchemaReplayComparator
{
    public function assertEquivalent(
        SchemaSnapshot $expected,
        SchemaSnapshot $actual,
        string $expectedLabel = 'source',
        string $actualLabel = 'baseline',
    ): void {
        if ($expected->driver !== $actual->driver) {
            throw ReplayVerificationFailed::because(
                "{$expectedLabel} driver [{$expected->driver}] does not match {$actualLabel} driver [{$actual->driver}].",
            );
        }

        $expectedFingerprint = $expected->fingerprint();
        $actualFingerprint = $actual->fingerprint();

        if (! hash_equals($expectedFingerprint, $actualFingerprint)) {
            throw ReplayVerificationFailed::because(
                "{$expectedLabel} schema fingerprint [{$expectedFingerprint}] does not match {$actualLabel} schema fingerprint [{$actualFingerprint}].",
            );
        }
    }
}
