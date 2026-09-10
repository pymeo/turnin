<?php

declare(strict_types=1);

namespace App\Workforce\Infrastructure\Console;

use App\Workforce\Application\Command\ImportWorkplaces;
use App\Workforce\Application\Command\WorkplaceImportReport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\HandleTrait;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(
    name: 'turnin:workplaces:import',
    description: 'Synchronize public Spanish healthcare workplaces from official Ministry catalogs.',
)]
final class ImportWorkplacesCommand extends Command
{
    use HandleTrait;

    public function __construct(MessageBusInterface $commandBus)
    {
        parent::__construct();
        $this->messageBus = $commandBus;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $report = $this->import();
        $io = new SymfonyStyle($input, $output);

        foreach ($report->sources as $source) {
            $io->section($source->source->label().':');
            if (null !== $source->failure) {
                $io->error($source->failure);
                continue;
            }

            $io->writeln('  created '.$source->created);
            $io->writeln('  updated '.$source->updated);
            $io->writeln('  unchanged '.$source->unchanged);
            $io->writeln('  deactivated '.$source->deactivated);
            $io->writeln('  rejected '.$source->rejected);
        }

        $io->success('Total active workplaces: '.$report->totalActive);

        return $report->succeeded() ? Command::SUCCESS : Command::FAILURE;
    }

    private function import(): WorkplaceImportReport
    {
        return $this->handle(new ImportWorkplaces());
    }
}
