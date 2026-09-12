<?php

declare(strict_types=1);

namespace App\Tests\Functional\Platform\Identity;

use App\Platform\Identity\Infrastructure\Security\SecurityUser;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

final class AuthenticatedShellTest extends WebTestCase
{
    private ?Connection $connection = null;

    /** @var list<string> */
    private array $userIds = [];

    /** @return iterable<string, array{string, bool, bool}> */
    public static function authenticatedAreas(): iterable
    {
        yield 'onboarding' => ['/onboarding', false, false];
        yield 'worker lobby' => ['/app', true, false];
        yield 'supervisor lobby' => ['/supervisor', false, true];
    }

    #[DataProvider('authenticatedAreas')]
    public function test_logout_is_available_from_every_authenticated_area(string $path, bool $worker, bool $supervisor): void
    {
        $client = $this->authenticatedClient($worker, $supervisor);
        $page = $client->request('GET', $path);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $page->filter('form[action="/logout"][method="post"]'));
        self::assertSelectorTextContains('form[action="/logout"] button', 'Salir');
        self::assertNotSame('', $page->filter('form[action="/logout"] input[name="_csrf_token"]')->attr('value'));
    }

    public function test_the_lobby_is_unreachable_without_a_session(): void
    {
        $client = static::createClient();
        $client->request('GET', '/app');

        self::assertResponseRedirects('http://localhost/login');
    }

    public function test_logout_invalidates_the_turnin_session(): void
    {
        $client = $this->authenticatedClient(true, false);
        $page = $client->request('GET', '/app');
        $logout = $page->filter('form[action="/logout"] button');

        $client->submit($logout->form());

        self::assertResponseRedirects('/');
        $client->request('GET', '/app');
        self::assertResponseRedirects('http://localhost/login');
    }

    protected function tearDown(): void
    {
        if (null !== $this->connection) {
            foreach ($this->userIds as $userId) {
                $this->connection->delete('identity_users', ['id' => $userId]);
            }
        }

        parent::tearDown();
    }

    private function authenticatedClient(bool $worker, bool $supervisor): KernelBrowser
    {
        $client = static::createClient();
        $this->connection = static::getContainer()->get(Connection::class);
        $id = Uuid::v7()->toRfc4122();
        $email = $id.'@example.test';
        $this->userIds[] = $id;
        $this->connection->insert('identity_users', [
            'id' => $id,
            'email' => $email,
            'password_hash' => null,
            'has_worker_profile' => $worker,
            'has_supervisor_profile' => $supervisor,
            'created_at' => '2026-09-10T00:00:00+00:00',
        ], [
            'has_worker_profile' => 'boolean',
            'has_supervisor_profile' => 'boolean',
        ]);
        $client->loginUser(new SecurityUser($id, $email, null, $worker, $supervisor));

        return $client;
    }
}
