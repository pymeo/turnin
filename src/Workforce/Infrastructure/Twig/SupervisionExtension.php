<?php

declare(strict_types=1);

namespace App\Workforce\Infrastructure\Twig;

use App\Workforce\Application\Query\GetSupervisionOverview;
use App\Workforce\Application\Query\SupervisionOverview;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** Lets the home show supervision cards without Identity knowing Workforce. */
final class SupervisionExtension extends AbstractExtension
{
    public function __construct(private readonly MessageBusInterface $queryBus, private readonly RequestStack $requests)
    {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('supervision_overview', $this->overview(...))];
    }

    public function overview(string $userId): SupervisionOverview
    {
        if ('' === $userId) {
            return new SupervisionOverview([], []);
        }
        $overview = $this->queryBus->dispatch(new GetSupervisionOverview($userId, $this->requests->getCurrentRequest()?->getSchemeAndHttpHost() ?? ''))->last(HandledStamp::class)?->getResult();

        return $overview instanceof SupervisionOverview ? $overview : new SupervisionOverview([], []);
    }
}
