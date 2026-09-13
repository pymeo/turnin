<?php

declare(strict_types=1);

namespace App\Tests\Functional\Coordination;

use App\Platform\Identity\Infrastructure\Security\SecurityUser;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

final class ScheduleCoordinationFlowTest extends WebTestCase
{
    private ?Connection $connection = null;

    /** @var array<string, array{id: string, email: string}> */
    private array $users = [];

    protected function tearDown(): void
    {
        if (null !== $this->connection) {
            foreach ($this->users as $user) {
                $this->connection->delete('identity_users', ['id' => $user['id']]);
            }
        }
        parent::tearDown();
    }

    public function test_a_secure_single_use_invitation_creates_a_bilateral_private_link(): void
    {
        $client = $this->world();
        $this->signIn($client, 'pedro');
        $page = $client->request('GET', '/app/together');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Invita a alguien', $page->html());
        $created = $client->submit($page->filter('form[action="/app/together/invite"] button')->form());
        self::assertResponseIsSuccessful();
        $url = (string) $created->filter('input[readonly]')->attr('value');
        self::assertMatchesRegularExpression('#/app/together/join/[A-Za-z0-9_-]{40,}#', $url);
        $token = basename(parse_url($url, \PHP_URL_PATH) ?: '');
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM coordination_schedule_invitations WHERE token_hash = :plain', ['plain' => $token]), 'Only the SHA-256 digest is persisted.');

        $this->signIn($client, 'amanda');
        $join = $client->request('GET', '/app/together/join/'.$token);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Pedro quiere comparar horarios contigo', $join->html());
        $client->submit($join->filter('form[action^="/app/together/join/"] button')->form());
        self::assertResponseRedirects('/app/together');

        $partnerPage = $client->followRedirect();
        self::assertStringContainsString('Tú + Pedro', $partnerPage->html());
        self::assertSame(1, $this->countRows('SELECT COUNT(*) FROM coordination_schedule_links WHERE revoked_at IS NULL', []));

        $this->signIn($client, 'pedro');
        $ownerPage = $client->request('GET', '/app/together?month=2026-09');
        self::assertStringContainsString('Tú + Amanda', $ownerPage->html());

        $this->signIn($client, 'marta');
        $client->request('POST', '/app/together/join/'.$token, parameters: ['_token' => $this->csrf($client)]);
        self::assertResponseStatusCodeSame(422, 'A third user cannot reuse an accepted invitation.');
    }

    public function test_either_member_can_unlink_and_access_disappears_immediately(): void
    {
        $client = $this->world();
        $this->signIn($client, 'pedro');
        $page = $client->request('GET', '/app/together');
        $created = $client->submit($page->filter('form[action="/app/together/invite"] button')->form());
        $token = basename(parse_url((string) $created->filter('input[readonly]')->attr('value'), \PHP_URL_PATH) ?: '');
        $this->signIn($client, 'amanda');
        $join = $client->request('GET', '/app/together/join/'.$token);
        $client->submit($join->filter('form[action^="/app/together/join/"] button')->form());
        $linked = $client->followRedirect();
        $client->submit($linked->filter('form[action="/app/together/unlink"] button')->form());
        self::assertResponseRedirects('/app/together');
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM coordination_schedule_links WHERE revoked_at IS NULL', []));

        $this->signIn($client, 'pedro');
        self::assertStringContainsString('Invita a alguien', $client->request('GET', '/app/together')->html());
    }

    private function world(): KernelBrowser
    {
        $client = static::createClient();
        $connection = static::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        foreach (['pedro' => 'Pedro', 'amanda' => 'Amanda', 'marta' => 'Marta'] as $key => $name) {
            $this->users[$key] = $this->seedUser($name);
        }

        return $client;
    }

    private function signIn(KernelBrowser $client, string $key): void
    {
        $user = $this->users[$key];
        $client->loginUser(new SecurityUser($user['id'], $user['email'], null, true, false));
    }

    private function csrf(KernelBrowser $client): string
    {
        return (string) $client->request('GET', '/app/together')->filter('input[name="_token"]')->attr('value');
    }

    /** @return array{id: string, email: string} */
    private function seedUser(string $name): array
    {
        self::assertNotNull($this->connection);
        $id = Uuid::v7()->toRfc4122();
        $email = $id.'@example.test';
        $this->connection->insert('identity_users', ['id' => $id, 'email' => $email, 'password_hash' => null, 'has_worker_profile' => true, 'has_supervisor_profile' => false, 'created_at' => '2026-09-10T00:00:00+00:00'], ['has_worker_profile' => 'boolean', 'has_supervisor_profile' => 'boolean']);
        $this->connection->insert('identity_personal_profiles', ['user_id' => $id, 'given_name' => $name, 'family_name' => 'de Prueba', 'phone_encrypted' => null, 'identity_evidence' => null, 'created_at' => '2026-09-10T00:00:00+00:00', 'updated_at' => '2026-09-10T00:00:00+00:00']);

        return ['id' => $id, 'email' => $email];
    }

    /** @param array<string, mixed> $parameters */
    private function countRows(string $sql, array $parameters): int
    {
        self::assertNotNull($this->connection);
        $value = $this->connection->fetchOne($sql, $parameters);
        self::assertIsNumeric($value);

        return (int) $value;
    }
}
