<?php

declare(strict_types=1);

namespace App\Swap\Application\Query;

use App\Swap\Domain\AgreementSegment;
use App\Swap\Domain\SwapAgreementSnapshot;
use App\Swap\Domain\SwapProposal;
use App\Swap\Domain\SwapProposalStatus;
use App\Swap\Domain\WorkDate;
use App\Swap\Domain\WorkDateLabel;
use App\Swap\Domain\WorkerDisplayNames;
use DateTimeZone;

final readonly class SwapAgreementViewFactory
{
    public function __construct(private WorkerDisplayNames $names)
    {
    }

    public function create(SwapAgreementSnapshot $snapshot, SwapProposal $proposal): SwapAgreementView
    {
        $names = $this->names->forWorkers(array_values(array_filter([$proposal->requestOwnerId(), $proposal->proposerId(), $proposal->approvedBy()])));
        $owner = $names[$proposal->requestOwnerId()] ?? 'Un compañero';
        $proposer = $names[$proposal->proposerId()] ?? 'Un compañero';
        [$statusTitle, $statusDetail, $cancelled] = $this->status($proposal->status(), null !== $snapshot->revokedAt());
        $approvedBy = null === $proposal->approvedBy() ? null : ($names[$proposal->approvedBy()] ?? 'el responsable');
        if (null !== $approvedBy && SwapProposalStatus::EXECUTED === $proposal->status()) {
            $statusDetail = 'Aprobado por '.$approvedBy.' y registrado en Turnin.';
        }
        $requestedMinutes = array_sum(array_map(static fn (AgreementSegment $segment): int => $segment->durationMinutes, $snapshot->requestedSegments()));
        $returnMinutes = array_sum(array_map(static fn (AgreementSegment $segment): int => $segment->durationMinutes, $snapshot->returnSegments()));

        return new SwapAgreementView(
            $proposal->id(), $owner, $proposer,
            $this->leg(mb_strtoupper($proposer.' hará por '.$owner), $snapshot->requestedDate(), $snapshot->requestedSegments(), $snapshot),
            null === $snapshot->returnDate() ? null : $this->leg(mb_strtoupper($owner.' hará por '.$proposer), $snapshot->returnDate(), $snapshot->returnSegments(), $snapshot),
            $this->difference($owner, $proposer, $requestedMinutes, $returnMinutes),
            $proposal->status()->value, $statusTitle, $statusDetail, SwapProposalStatus::PENDING_APPROVAL === $proposal->status(), $cancelled,
            $snapshot->reachedAt()->setTimezone(new DateTimeZone('Europe/Madrid'))->format('d/m/Y · H:i'), $snapshot->reference(), $snapshot->publicToken(), null !== $snapshot->revokedAt(),
            $approvedBy,
        );
    }

    /** @param list<AgreementSegment> $segments */
    private function leg(string $eyebrow, string $date, array $segments, SwapAgreementSnapshot $snapshot): AgreementLegView
    {
        return new AgreementLegView($eyebrow, WorkDateLabel::headline(WorkDate::fromString($date)), $date, array_map(fn (AgreementSegment $segment): AgreementSegmentView => new AgreementSegmentView($segment->start.' → '.$segment->end, $this->duration($segment->durationMinutes), $segment->label, $segment->endsNextDay), $segments), $snapshot->groupLabel().' · '.$snapshot->workplaceName());
    }

    /** @return array{string, string, bool} */
    private function status(SwapProposalStatus $status, bool $revoked): array
    {
        if ($revoked) {
            return ['Enlace revocado', 'Esta copia ya no puede compartirse.', true];
        }

        return match ($status) {
            SwapProposalStatus::PENDING_APPROVAL => ['Acordado entre compañeros', 'Falta la aprobación/registro del responsable.', false],
            SwapProposalStatus::EXECUTED, SwapProposalStatus::ACCEPTED => ['Cambio confirmado', 'El cambio está aprobado y registrado en Turnin.', false],
            SwapProposalStatus::APPROVAL_REJECTED => ['Cambio no aprobado', 'El responsable ha rechazado este cambio.', true],
            SwapProposalStatus::REJECTED, SwapProposalStatus::WITHDRAWN, SwapProposalStatus::EXPIRED => ['Cambio cancelado', 'Este cambio ya no está vigente.', true],
            SwapProposalStatus::PENDING => ['Propuesta pendiente', 'Todavía no existe un acuerdo entre ambos profesionales.', true],
        };
    }

    private function difference(string $owner, string $proposer, int $requested, int $return): string
    {
        $delta = $requested - $return;
        if (0 === $delta) {
            return 'Ambos harán el mismo número de horas.';
        }
        $name = $delta > 0 ? $proposer : $owner;

        return $name.' trabajará +'.$this->duration(abs($delta)).'.';
    }

    private function duration(int $minutes): string
    {
        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;

        return 0 === $rest ? $hours.' h' : $hours.' h '.$rest.' min';
    }
}
