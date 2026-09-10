<?php

declare(strict_types=1);

namespace App\Tests\Functional\Platform\System;

use App\Platform\System\Infrastructure\Http\HealthController;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

#[CoversClass(HealthController::class)]
final class HealthEndpointTest extends WebTestCase
{
    public function test_it_answers_with_the_state_of_every_dependency(): void
    {
        $client = static::createClient();
        $client->request('GET', '/health');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');

        $payload = self::decode($client->getResponse());

        self::assertSame('healthy', $payload['status']);
        self::assertArrayHasKey('database', $payload['components']);
        self::assertArrayHasKey('cache', $payload['components']);
        self::assertArrayHasKey('schema', $payload['components']);
    }

    public function test_a_health_answer_is_never_cached(): void
    {
        $client = static::createClient();
        $client->request('GET', '/health');

        self::assertResponseHeaderSame('Cache-Control', 'no-store, private');
    }

    /**
     * The endpoint is unauthenticated, so it must not become a reconnaissance
     * tool: no hostnames, no versions, no driver messages.
     */
    public function test_it_does_not_leak_infrastructure_details(): void
    {
        $client = static::createClient();
        $client->request('GET', '/health');

        $body = (string) $client->getResponse()->getContent();

        foreach (['postgres', 'redis', 'password', 'Exception', 'SQLSTATE', '5432'] as $forbidden) {
            self::assertStringNotContainsStringIgnoringCase($forbidden, $body);
        }
    }

    public function test_the_response_is_correlated_with_a_request_id(): void
    {
        $client = static::createClient();
        $client->request('GET', '/health');

        self::assertNotNull($client->getResponse()->headers->get('X-Request-Id'));
    }

    public function test_an_inbound_request_id_is_reused_so_traces_join_up(): void
    {
        $client = static::createClient();
        $client->request('GET', '/health', server: ['HTTP_X_REQUEST_ID' => 'abc-123']);

        self::assertSame('abc-123', $client->getResponse()->headers->get('X-Request-Id'));
    }

    public function test_a_forged_request_id_is_discarded_rather_than_echoed_into_the_logs(): void
    {
        $client = static::createClient();
        $client->request('GET', '/health', server: ['HTTP_X_REQUEST_ID' => "spoofed\ninjected log line"]);

        self::assertNotSame("spoofed\ninjected log line", $client->getResponse()->headers->get('X-Request-Id'));
    }

    /**
     * @return array{status: string, observedAt: string, components: array<string, array{status: string}>}
     */
    private static function decode(Response $response): array
    {
        /** @var array{status: string, observedAt: string, components: array<string, array{status: string}>} $payload */
        $payload = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        return $payload;
    }
}
