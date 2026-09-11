<?php

declare(strict_types=1);

namespace App\Tests\Functional\Workforce;

use App\Platform\Identity\Infrastructure\Security\SecurityUser;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

final class OnboardingFlowTest extends WebTestCase
{
    private Connection $connection;
    private KernelBrowser $client;
    private string $userId;
    private string $csrf;

    /** @var list<string> Two centres this test owns, seeded inside its transaction. */
    private array $workplaceIds;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $connection = static::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->connection->beginTransaction();
        $this->userId = Uuid::v7()->toRfc4122();
        $email = $this->userId.'@example.test';
        $this->connection->insert('identity_users', [
            'id' => $this->userId,
            'email' => $email,
            'password_hash' => null,
            'has_worker_profile' => false,
            'has_supervisor_profile' => false,
            'created_at' => '2026-09-11T08:00:00+00:00',
        ], ['has_worker_profile' => 'boolean', 'has_supervisor_profile' => 'boolean']);
        $this->workplaceIds = [$this->seedWorkplace('Hospital de pruebas Uno'), $this->seedWorkplace('Hospital de pruebas Dos')];
        $this->client->loginUser(new SecurityUser($this->userId, $email, null, false, false));
        $page = $this->client->request('GET', '/onboarding');
        self::assertResponseIsSuccessful();
        self::assertCount(7, $page->filter('[data-onboarding-target="step"]'));
        $this->csrf = (string) $page->filter('[data-onboarding-csrf-value]')->attr('data-onboarding-csrf-value');
        self::assertNotSame('', $this->csrf);
    }

    protected function tearDown(): void
    {
        if (isset($this->connection) && $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function test_onboarding_is_resumable_protects_identity_and_can_update_the_assignment(): void
    {
        $workplaceId = $this->workplaceIds[0];
        $categoryId = $this->scalar('SELECT id FROM workforce_staff_categories WHERE active = TRUE AND specialty_required = FALSE ORDER BY name LIMIT 1');

        $this->post('/onboarding/name', ['given_name' => 'Ana', 'family_name' => 'García']);
        $this->post('/onboarding/identity', ['identity_document' => '12 345 678-z', 'phone' => '600 123 123']);
        $this->post('/onboarding/progress', [
            'workplace_id' => $workplaceId,
            'staff_category_id' => $categoryId,
            'primary_destination_id' => 'reference:emergency',
            'additional_destination_ids' => ['reference:intensive_care'],
        ]);

        self::assertSame(1, $this->countRows('SELECT COUNT(*) FROM workforce_onboarding_drafts WHERE worker_id = :worker', ['worker' => $this->userId]));
        $page = $this->client->request('GET', '/onboarding');
        self::assertResponseIsSuccessful();
        self::assertSame('Ana', $page->filter('input[name="given_name"]')->attr('value'));
        self::assertSame($workplaceId, $page->filter('input[name="workplace_id"]')->attr('value'));
        self::assertSame('true', $page->filter('[data-step="identity"]')->attr('data-complete'));

        $payload = $this->post('/onboarding/complete');

        $result = $payload['result'] ?? null;
        self::assertIsArray($result);
        self::assertSame('/app', $result['redirect'] ?? null);
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM workforce_onboarding_drafts WHERE worker_id = :worker', ['worker' => $this->userId]));
        self::assertTrue((bool) $this->connection->fetchOne('SELECT has_worker_profile FROM identity_users WHERE id = :worker', ['worker' => $this->userId]));
        self::assertSame(1, $this->countRows('SELECT COUNT(*) FROM workforce_worker_assignments WHERE worker_id = :worker AND active = TRUE', ['worker' => $this->userId]));
        self::assertSame(2, $this->countRows('SELECT COUNT(*) FROM workforce_swap_pool_memberships WHERE worker_id = :worker AND active = TRUE', ['worker' => $this->userId]));
        $memberships = $this->connection->fetchAllAssociative(
            <<<'SQL'
				SELECT unit.name, membership.is_primary, membership.source
				  FROM workforce_swap_pool_memberships membership
				  JOIN workforce_swap_pools pool ON pool.id = membership.swap_pool_id
				  JOIN workforce_organizational_units unit ON unit.id = pool.organizational_unit_id
				 WHERE membership.worker_id = :worker AND membership.active = TRUE
				 ORDER BY membership.is_primary DESC, unit.name
				SQL,
            ['worker' => $this->userId],
        );
        self::assertSame([
            ['name' => 'Urgencias', 'is_primary' => true, 'source' => 'self_declared'],
            ['name' => 'UCI', 'is_primary' => false, 'source' => 'self_declared'],
        ], $memberships);
        self::assertNotSame('600123123', $this->scalar('SELECT phone_encrypted FROM identity_personal_profiles WHERE user_id = :worker', ['worker' => $this->userId]));
        self::assertNotSame('12345678Z', $this->scalar('SELECT identity_document_fingerprint FROM identity_usage_identities WHERE user_id = :worker', ['worker' => $this->userId]));

        $this->post('/onboarding/progress', [
            'workplace_id' => $workplaceId,
            'staff_category_id' => $categoryId,
            'primary_destination_id' => 'reference:intensive_care',
        ]);
        $this->post('/onboarding/complete');

        self::assertSame(1, $this->countRows('SELECT COUNT(*) FROM workforce_worker_assignments WHERE worker_id = :worker AND active = TRUE', ['worker' => $this->userId]));
        self::assertSame(1, $this->countRows('SELECT COUNT(*) FROM workforce_swap_pool_memberships WHERE worker_id = :worker AND active = TRUE AND is_primary = TRUE', ['worker' => $this->userId]));
    }

    public function test_mutations_require_a_valid_csrf_token(): void
    {
        $this->client->request('POST', '/onboarding/name', ['given_name' => 'Ana', 'family_name' => 'García'], [], ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(419);
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM identity_personal_profiles WHERE user_id = :worker', ['worker' => $this->userId]));
    }

    public function test_a_worker_can_add_a_second_active_workplace_without_reentering_identity(): void
    {
        $workplaces = $this->workplaceIds;
        $categoryId = $this->scalar('SELECT id FROM workforce_staff_categories WHERE active = TRUE AND specialty_required = FALSE ORDER BY name LIMIT 1');
        $this->post('/onboarding/name', ['given_name' => 'Ana', 'family_name' => 'García']);
        $this->post('/onboarding/identity', ['identity_document' => '12 345 678-z', 'phone' => '600 123 123']);
        $this->post('/onboarding/progress', ['workplace_id' => $workplaces[0], 'staff_category_id' => $categoryId, 'primary_destination_id' => 'reference:emergency']);
        $this->post('/onboarding/complete');

        $page = $this->client->request('GET', '/onboarding?mode=add');
        self::assertResponseIsSuccessful();
        self::assertSame('true', $page->filter('[data-step="identity"]')->attr('data-complete'));
        $this->post('/onboarding/progress', ['workplace_id' => $workplaces[1], 'staff_category_id' => $categoryId, 'primary_destination_id' => 'reference:intensive_care']);
        $result = $this->post('/onboarding/add-assignment');

        $added = $result['result'] ?? null;
        self::assertIsArray($added);
        self::assertArrayHasKey('redirect', $added);
        self::assertIsString($added['redirect']);
        self::assertStringStartsWith('/app/calendar?assignment=', $added['redirect']);
        self::assertSame(2, $this->countRows('SELECT COUNT(*) FROM workforce_worker_assignments WHERE worker_id = :worker AND active = TRUE', ['worker' => $this->userId]));
        self::assertSame(1, $this->countRows('SELECT COUNT(*) FROM workforce_worker_assignments WHERE worker_id = :worker AND active = TRUE AND primary_assignment = TRUE', ['worker' => $this->userId]));
        self::assertSame(2, $this->countRows('SELECT COUNT(*) FROM workforce_swap_pool_memberships WHERE worker_id = :worker AND active = TRUE AND is_primary = TRUE', ['worker' => $this->userId]));
    }

    /** @param array<string, mixed> $parameters
     * @return array<string, mixed>
     */
    private function post(string $path, array $parameters = []): array
    {
        $this->client->request('POST', $path, $parameters, [], ['HTTP_X_CSRF_TOKEN' => $this->csrf, 'HTTP_ACCEPT' => 'application/json']);
        self::assertResponseIsSuccessful();
        /** @var array<string, mixed> $payload */
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        return $payload;
    }

    /**
     * The catalogue is imported from the Ministry, so it is not there after a
     * migrations-only reset. Each run seeds the two centres it needs inside its
     * own transaction rather than relying on rows another test left behind —
     * phpunit.dist.xml runs in random order precisely to catch that.
     */
    private function seedWorkplace(string $name): string
    {
        $id = Uuid::v7()->toRfc4122();
        $this->connection->insert('workforce_workplaces', [
            'id' => $id,
            'source' => 'ministry_hospitals',
            'external_id' => 'onboarding-'.$id,
            'name' => $name,
            'type' => 'hospital',
            'autonomous_community' => 'Región de Murcia',
            'province' => 'Murcia',
            'municipality' => 'Murcia',
            'ownership' => 'public',
            'active' => true,
            'source_updated_at' => '2026-09-10T00:00:00+00:00',
            'created_at' => '2026-09-10T00:00:00+00:00',
            'updated_at' => '2026-09-10T00:00:00+00:00',
        ], ['active' => 'boolean']);

        return $id;
    }

    /** @param array<string, mixed> $parameters */
    private function scalar(string $sql, array $parameters = []): string
    {
        $value = $this->connection->fetchOne($sql, $parameters);
        self::assertIsString($value);

        return $value;
    }

    /** @param array<string, mixed> $parameters */
    private function countRows(string $sql, array $parameters = []): int
    {
        $value = $this->connection->fetchOne($sql, $parameters);
        self::assertTrue(\is_int($value) || \is_string($value));

        return (int) $value;
    }
}
