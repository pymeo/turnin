<?php

declare(strict_types=1);

namespace App\Tests\Integration\Scheduling\Persistence;

use App\Scheduling\Application\ExternalCalendar\ExternalCalendarConnections;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class DoctrineExternalCalendarsTest extends KernelTestCase
{
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $database = static::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $database);
        $this->database = $database;
        $this->database->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (isset($this->database) && $this->database->isTransactionActive()) {
            $this->database->rollBack();
        }
        parent::tearDown();
    }

    public function test_calendar_tokens_are_encrypted_and_disconnect_removes_reusable_credentials(): void
    {
        $userId = Uuid::v7()->toRfc4122();
        $this->database->insert('identity_users', ['id' => $userId, 'email' => $userId.'@example.test', 'password_hash' => null, 'has_worker_profile' => true, 'has_supervisor_profile' => false, 'created_at' => '2026-09-11T00:00:00+00:00'], ['has_worker_profile' => 'boolean', 'has_supervisor_profile' => 'boolean']);
        $connections = static::getContainer()->get(ExternalCalendarConnections::class);
        self::assertInstanceOf(ExternalCalendarConnections::class, $connections);
        $connections->connect($userId, 'google-subject', 'plain-access-token', 'plain-refresh-token', new DateTimeImmutable('2026-09-11T12:00:00+00:00'), ['calendar.events.readonly']);

        $row = $this->database->fetchAssociative('SELECT encrypted_access_token, encrypted_refresh_token FROM scheduling_external_calendar_connections WHERE user_id = :user', ['user' => $userId]);
        self::assertIsArray($row);
        self::assertNotSame('plain-access-token', $row['encrypted_access_token']);
        self::assertNotSame('plain-refresh-token', $row['encrypted_refresh_token']);
        self::assertSame('plain-refresh-token', $connections->activeFor($userId)?->refreshToken);

        $connections->disconnect($userId);

        // The row survives as an audit trail, but the reusable credential does
        // not: the refresh token is gone and the connection reads as revoked,
        // so nothing can act on the account again without a fresh consent.
        self::assertNull($connections->activeFor($userId));
        $revoked = $this->database->fetchAssociative('SELECT encrypted_refresh_token, revoked_at FROM scheduling_external_calendar_connections WHERE user_id = :user', ['user' => $userId]);
        self::assertIsArray($revoked);
        self::assertNull($revoked['encrypted_refresh_token']);
        self::assertNotNull($revoked['revoked_at']);
    }
}
