<?php

namespace Tests\Unit\Community;

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Unit tests for Circles API slug generation helpers.
 */
class CirclesControllerTest extends CIUnitTestCase
{
    // ── CirclesController::makeSlug ───────────────────────────────────────────

    public function testMakeSlugBasicNoCollision(): void
    {
        $slug = \App\Controllers\Api\Circles\CirclesController::makeSlug('Hello World', $this->mockDb(0));
        $this->assertSame('hello-world', $slug);
    }

    public function testMakeSlugStripsSpecialChars(): void
    {
        $slug = \App\Controllers\Api\Circles\CirclesController::makeSlug('Black & Bold!', $this->mockDb(0));
        $this->assertSame('black-bold', $slug);
    }

    public function testMakeSlugAppendsTimestampOnCollision(): void
    {
        $before = time();
        $slug   = \App\Controllers\Api\Circles\CirclesController::makeSlug('Test', $this->mockDb(1));
        $after  = time();

        $this->assertStringStartsWith('test-', $slug);
        $ts = (int) substr($slug, 5);
        $this->assertGreaterThanOrEqual($before, $ts);
        $this->assertLessThanOrEqual($after, $ts);
    }

    // ── MovementsController::makeSlug ─────────────────────────────────────────

    public function testMovementMakeSlugNoCollision(): void
    {
        $slug = \App\Controllers\Api\Movements\MovementsController::makeSlug('Community First', $this->mockDbMovements(0));
        $this->assertSame('community-first', $slug);
    }

    public function testMovementMakeSlugAppendsNumberOnCollision(): void
    {
        // First check returns 1 (collision), second returns 0 (free)
        $db   = $this->mockDbMovements(1, 0);
        $slug = \App\Controllers\Api\Movements\MovementsController::makeSlug('Test', $db);
        $this->assertSame('test-1', $slug);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function mockDb(int ...$counts)
    {
        $sequence = $counts;
        return new class($sequence) {
            private array $seq;
            private int $idx = 0;
            public function __construct(array $seq) { $this->seq = $seq; }
            public function table(string $name): static { return $this; }
            public function where(string $col, mixed $val): static { return $this; }
            public function countAllResults(): int { return $this->seq[$this->idx++] ?? 0; }
        };
    }

    private function mockDbMovements(int ...$counts)
    {
        $sequence = $counts;
        return new class($sequence) {
            private array $seq;
            private int $idx = 0;
            public function __construct(array $seq) { $this->seq = $seq; }
            public function table(string $name): static { return $this; }
            public function where(string $col, mixed $val): static { return $this; }
            public function countAllResults(): int { return $this->seq[$this->idx++] ?? 0; }
        };
    }
}
