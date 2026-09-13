<?php

declare(strict_types=1);

namespace App\Tests\Functional\Swap;

use App\Platform\Identity\Infrastructure\Security\SecurityUser;
use App\Tests\Support\Swap\SwapWorldSeed;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * "Me interesa" → which of my own shifts do I ask for in return.
 *
 * María works Monday to Thursday next week and has the Friday, Saturday and
 * Sunday off, so handing Pedro the Thursday buys her four days in a row. Every
 * assertion below is something the previous screen — a vertical list of
 * near-identical cards — could not answer.
 *
 * Dates are relative to the Monday of the real current week because the screen
 * shows four rolling weeks and the kernel runs on the real clock.
 */
final class ExchangeComposerTest extends WebTestCase
{
    private ?SwapWorldSeed $seed = null;

    private ?Connection $connection = null;

    /** @var array<string, array{id: string, assignment: string, email: string}> */
    private array $workers = [];

    private string $uciPool = '';

    private string $portersPool = '';

    private string $requestId = '';

    protected function tearDown(): void
    {
        $this->seed?->cleanUp();
        parent::tearDown();
    }

    public function test_the_screen_asks_the_right_question_over_the_right_calendar(): void
    {
        $client = $this->world();
        $this->signIn($client, 'maria');
        $page = $client->request('GET', $this->composerUrl());

        self::assertResponseIsSuccessful();
        $html = $page->html();
        self::assertStringContainsString('¿Qué turno quieres que Pedro haga por ti?', $html);
        self::assertStringContainsString('Te mostramos también qué días trabajas alrededor', $html);
        self::assertStringContainsString('Tú harías a Pedro', $html);
        self::assertStringContainsString('24 H', $html, 'A 08:00 → 08:00 guardia must never read as zero.');

        // Four weeks, twenty-eight days, and a word on every one of them.
        self::assertCount(4, $page->filter('.shift-calendar-week'));
        self::assertCount(28, $page->filter('.shift-day'));
        self::assertGreaterThan(0, $page->filter('.shift-day[data-state="rest"]')->count());
        self::assertGreaterThan(0, $page->filter('.shift-day[data-state="unknown"]')->count());
        self::assertStringContainsString('Libre', $html);
        self::assertStringContainsString('Sin datos', $html);

        // The day she would cover is marked, and it is not one of her own.
        self::assertCount(1, $page->filter(\sprintf('.shift-day[data-date="%s"][data-state="incoming"]', $this->day(19))));
    }

    /** The detector already in the codebase, surfaced where the choice is made. */
    public function test_the_shift_that_buys_a_rest_block_is_recommended_with_its_reason(): void
    {
        $client = $this->world();
        $this->signIn($client, 'maria');
        $page = $client->request('GET', $this->composerUrl());

        self::assertStringContainsString('Mejor opción', $page->html());
        self::assertStringContainsString('Conseguirías 4 días seguidos libres', $page->html());
        self::assertCount(1, $page->filter('.reco-card'), 'One suggestion, not one per shift.');

        $starred = $page->filter('.shift-day .shift-day-badge');
        self::assertCount(1, $starred);
        self::assertCount(1, $page->filter(\sprintf('.shift-day[data-date="%s"] .shift-day-badge', $this->day(10))));
    }

    /**
     * The calendar does not build a selection of its own: it ticks the same
     * radio the list renders, so both views post the same command.
     */
    public function test_calendar_and_list_offer_exactly_the_same_shift(): void
    {
        $client = $this->world();
        $this->signIn($client, 'maria');
        $page = $client->request('GET', $this->composerUrl());

        $expected = $this->workers['maria']['assignment'].'|'.$this->day(10);
        self::assertSame($expected, $page->filter(\sprintf('.shift-day[data-date="%s"]', $this->day(10)))->attr('data-shift-key'));
        self::assertCount(1, $page->filter(\sprintf('input[name="offeredShift"][value="%s"]', $expected)));

        $client->request('POST', '/app/changes/'.$this->requestId.'/propuestas', [
            '_token' => $this->tokenFrom($client),
            'kind' => 'exchange',
            'offeredShift' => $expected,
        ]);

        self::assertResponseRedirects('/app/changes/proposals?sent=1');
        self::assertSame($this->day(10), $this->scalar('SELECT offered_work_date FROM swap_proposals WHERE proposer_id = :worker', ['worker' => $this->workers['maria']['id']]));
        self::assertSame('exchange', $this->scalar('SELECT kind FROM swap_proposals WHERE proposer_id = :worker', ['worker' => $this->workers['maria']['id']]));
    }

    /**
     * A shift she has already published cannot be offered — but it stays on the
     * calendar, because taking it away would take away whether she works that
     * day, which is the whole point of the screen.
     */
    public function test_a_shift_that_cannot_be_offered_stays_visible_with_its_reason(): void
    {
        $client = $this->world();
        $this->signIn($client, 'maria');
        $client->request('POST', '/app/changes/publicar', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $this->tokenFrom($client),
        ], content: json_encode(['assignmentId' => $this->workers['maria']['assignment'], 'date' => $this->day(9)], \JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();

        $page = $client->request('GET', $this->composerUrl());
        $blocked = $this->workers['maria']['assignment'].'|'.$this->day(9);

        self::assertStringContainsString('Ya lo has publicado para que alguien lo cubra.', $page->html());
        self::assertCount(0, $page->filter(\sprintf('input[name="offeredShift"][value="%s"]', $blocked)));
        self::assertCount(1, $page->filter(\sprintf('.shift-day[data-date="%s"][data-state="working"]', $this->day(9))));
        self::assertCount(1, $page->filter(\sprintf('.shift-day[data-date="%s"][data-blocked]', $this->day(9))));
    }

    public function test_paging_a_week_keeps_the_shift_already_chosen(): void
    {
        $client = $this->world();
        $this->signIn($client, 'maria');
        $chosen = $this->workers['maria']['assignment'].'|'.$this->day(10);
        $page = $client->request('GET', $this->composerUrl().'?semana=2&turno='.urlencode($chosen));

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Propones', $page->html());
        self::assertCount(1, $page->filter(\sprintf('input[name="offeredShift"][value="%s"][checked]', $chosen)));
        self::assertStringContainsString('+17 h', $page->html(), 'A 24 h guardia against a 7 h morning.');
    }

    /** This is her calendar. His other days are none of her business. */
    public function test_the_colleagues_other_shifts_never_reach_the_screen(): void
    {
        $client = $this->world();
        $this->signIn($client, 'maria');
        $page = $client->request('GET', $this->composerUrl());

        self::assertStringNotContainsString('Refuerzo de tarde', $page->html());
        self::assertStringNotContainsString($this->workers['pedro']['email'], $page->html());
    }

    public function test_a_worker_from_another_pool_cannot_open_the_composer(): void
    {
        $client = $this->world();
        $this->signIn($client, 'antonio');
        $client->request('GET', $this->composerUrl());

        self::assertResponseStatusCodeSame(404);
    }

    public function test_the_author_cannot_compose_against_their_own_shift(): void
    {
        $client = $this->world();
        $this->signIn($client, 'pedro');
        $client->request('GET', $this->composerUrl());

        self::assertResponseStatusCodeSame(404);
    }

    /* ── fixture ──────────────────────────────────────────────────────── */

    private function world(): KernelBrowser
    {
        $client = static::createClient();
        $connection = static::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->seed = new SwapWorldSeed($connection);

        $this->uciPool = $this->seed->pool('UCI');
        $this->portersPool = $this->seed->pool('Celadores');
        $this->workers['pedro'] = $this->seed->worker('Pedro', $this->uciPool);
        $this->workers['maria'] = $this->seed->worker('María', $this->uciPool);
        $this->workers['antonio'] = $this->seed->worker('Antonio', $this->portersPool);

        // María: Monday to Thursday next week, then three days off.
        foreach ([7, 8, 9, 10] as $offset) {
            $this->seed->shift($this->workers['maria']['assignment'], $this->day($offset));
        }
        foreach ([11, 12, 13, 19] as $offset) {
            $this->seed->restDay($this->workers['maria']['assignment'], $this->day($offset));
        }
        // Already behind her, so it can be seen but never offered.
        $this->seed->shift($this->workers['maria']['assignment'], $this->day(0));

        // Pedro's guardia, and one more shift of his that must stay private.
        $this->seed->shift($this->workers['pedro']['assignment'], $this->day(19), 'Guardia', 'G', '08:00', '08:00', 'on_call');
        $this->seed->shift($this->workers['pedro']['assignment'], $this->day(12), 'Refuerzo de tarde', 'R', '15:00', '22:00', 'evening');

        $this->signIn($client, 'pedro');
        $client->request('POST', '/app/changes/publicar', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $this->tokenFrom($client),
        ], content: json_encode(['assignmentId' => $this->workers['pedro']['assignment'], 'date' => $this->day(19)], \JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($payload);
        self::assertIsArray($payload['result'] ?? null);
        self::assertIsString($payload['result']['requestId'] ?? null);
        $this->requestId = $payload['result']['requestId'];

        return $client;
    }

    private function composerUrl(): string
    {
        return '/app/changes/'.$this->requestId.'/intercambio';
    }

    /** Days counted from the Monday of the current week, like the screen does. */
    private function day(int $offset): string
    {
        return (new DateTimeImmutable('today', new DateTimeZone('Europe/Madrid')))
            ->modify('monday this week')
            ->modify('+'.$offset.' days')
            ->format('Y-m-d');
    }

    private function signIn(KernelBrowser $client, string $who): void
    {
        $worker = $this->workers[$who];
        $client->loginUser(new SecurityUser($worker['id'], $worker['email'], null, true, false));
    }

    private function tokenFrom(KernelBrowser $client): string
    {
        $page = $client->request('GET', '/app/changes');

        return (string) $page->filter('[data-changes-csrf-value]')->attr('data-changes-csrf-value');
    }

    /** @param array<string, mixed> $parameters */
    private function scalar(string $sql, array $parameters): string
    {
        self::assertNotNull($this->connection);
        $value = $this->connection->fetchOne($sql, $parameters);
        self::assertIsString($value);

        return $value;
    }
}
