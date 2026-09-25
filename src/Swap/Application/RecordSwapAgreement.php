<?php

declare(strict_types=1);

namespace App\Swap\Application;

use App\Swap\Domain\AgreementSegment;
use App\Swap\Domain\AgreementShareTokenGenerator;
use App\Swap\Domain\RosteredDays;
use App\Swap\Domain\RosteredShift;
use App\Swap\Domain\SwapAgreementSnapshot;
use App\Swap\Domain\SwapAgreementSnapshots;
use App\Swap\Domain\SwapGroups;
use App\Swap\Domain\SwapProposal;
use App\Swap\Domain\SwapProposalKind;
use App\Swap\Domain\SwapRequest;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class RecordSwapAgreement
{
    public function __construct(private SwapAgreementSnapshots $agreements, private AgreementShareTokenGenerator $tokens, private RosteredDays $days, private SwapGroups $groups)
    {
    }

    public function record(SwapProposal $proposal, SwapRequest $request, DateTimeImmutable $now): SwapAgreementSnapshot
    {
        $existing = $this->agreements->byProposal($proposal->id());
        if (null !== $existing) {
            return $existing;
        }
        $requested = $this->days->shiftsFor([[$request->workerAssignmentId(), (string) $request->workDate()]]);
        if ([] === $requested) {
            throw new InvalidArgumentException('El turno solicitado ya no existe.');
        }
        $returnDate = null;
        $return = [];
        if (SwapProposalKind::EXCHANGE === $proposal->kind()) {
            $option = $proposal->chosenOption() ?? throw new InvalidArgumentException('Elige uno de los turnos ofrecidos.');
            $returnDate = (string) $option->workDate;
            $return = $this->days->shiftsFor([[$option->assignmentId, $returnDate]]);
            if ([] === $return) {
                throw new InvalidArgumentException('El turno ofrecido ya no existe.');
            }
        }
        $group = $this->groups->membership($request->workerId(), $request->swapPoolId()) ?? throw new InvalidArgumentException('El equipo del cambio ya no está disponible.');
        $secret = $this->tokens->next();

        return $this->agreements->saveIfMissing(SwapAgreementSnapshot::record(
            $proposal->id(), $secret['token'], $secret['reference'], (string) $request->workDate(), $this->segments($requested),
            $returnDate, $this->segments($return), $group->workplaceName, $group->label(), $now,
        ));
    }

    /** @param list<RosteredShift> $shifts
     * @return list<AgreementSegment>
     */
    private function segments(array $shifts): array
    {
        return array_map(static fn (RosteredShift $shift): AgreementSegment => new AgreementSegment($shift->startsAtLocal, $shift->endsAtLocal, $shift->durationMinutes(), $shift->label, $shift->abbreviation, $shift->colorKey, $shift->endsNextDay), $shifts);
    }
}
