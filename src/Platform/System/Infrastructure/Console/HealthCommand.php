<?php

declare(strict_types=1);

namespace App\Platform\System\Infrastructure\Console;

use App\Platform\System\Application\Query\CheckSystemHealth;
use App\Platform\System\Domain\HealthReport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\HandleTrait;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The same health query, reachable without HTTP.
 *
 * This is what the production container's HEALTHCHECK runs, so a broken
 * dependency fails the container even if Caddy is not up yet.
 */
#[AsCommand(
    name: 'turnin:health',
    description: 'Report whether Turnin and its dependencies are serviceable.',
)]
final class HealthCommand extends Command
{
    use HandleTrait;

    public function __construct(MessageBusInterface $queryBus)
    {
        parent::__construct();

        $this->messageBus = $queryBus;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $report = $this->currentHealth();

        $io = new SymfonyStyle($input, $output);
        $io->definitionList(
            ['status' => $report->status()->value],
            ...array_map(
                static fn ($component): array => [
                    $component->name->value => $component->status()->value.(null !== $component->detail ? ' ('.$component->detail.')' : ''),
                ],
                $report->components,
            ),
        );

        return $report->isServiceable() ? Command::SUCCESS : Command::FAILURE;
    }

    private function currentHealth(): HealthReport
    {
        return $this->handle(new CheckSystemHealth());
    }
}
