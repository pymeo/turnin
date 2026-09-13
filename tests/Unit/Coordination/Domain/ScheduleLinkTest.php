<?php

declare(strict_types=1);

namespace App\Tests\Unit\Coordination\Domain;

use App\Coordination\Domain\ScheduleLink;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ScheduleLinkTest extends TestCase
{
    public function test_the_link_is_symmetric_and_either_member_can_revoke_it(): void
    {
        $now = new DateTimeImmutable('2026-09-12');
        $link = ScheduleLink::create('link-a', 'user-z', 'user-a', $now);

        self::assertSame('user-z', $link->other('user-a'));
        self::assertSame('user-a', $link->other('user-z'));
        $link->revoke('user-z', $now->modify('+1 day'));
        self::assertFalse($link->isActive());
    }

    public function test_an_outsider_cannot_revoke_the_link(): void
    {
        $link = ScheduleLink::create('link-a', 'user-a', 'user-b', new DateTimeImmutable('2026-09-12'));

        $this->expectException(InvalidArgumentException::class);
        $link->revoke('user-c', new DateTimeImmutable('2026-09-13'));
    }
}
