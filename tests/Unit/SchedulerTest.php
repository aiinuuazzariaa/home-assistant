<?php

declare(strict_types=1);

namespace Tests\Unit;

use DateTime;
use PHPUnit\Framework\TestCase;

final class SchedulerTest extends TestCase
{
    private function validateDate(?string $date): bool
    {
        if ($date === null) {
            return true;
        }

        $d = DateTime::createFromFormat('Y-m-d', $date);
        return $d !== false && $d->format('Y-m-d') === $date;
    }

    public function testValidDateAcceptsProperFormat(): void
    {
        $this->assertTrue($this->validateDate('2026-10-12'));
        $this->assertTrue($this->validateDate('2025-01-01'));
        $this->assertTrue($this->validateDate(null));
    }

    public function testInvalidDateRejectsMaliciousOrMalformedInput(): void
    {
        $this->assertFalse($this->validateDate('2026-02-30')); // Nonexistent leap date
        $this->assertFalse($this->validateDate('invalid-date'));
        $this->assertFalse($this->validateDate('12/10/2026'));
        $this->assertFalse($this->validateDate("2026-10-12' OR '1'='1"));
        $this->assertFalse($this->validateDate('../../../etc/passwd'));
    }
}
