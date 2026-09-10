<?php

declare(strict_types=1);

namespace App\Workforce\Infrastructure\Persistence\Doctrine;

use App\Workforce\Domain\Workplace;
use App\Workforce\Domain\WorkplaceReader;
use App\Workforce\Domain\Workplaces;
use App\Workforce\Domain\WorkplaceSource;
use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineWorkplaces implements Workplaces, WorkplaceReader
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function ofSourceAndExternalId(WorkplaceSource $source, string $externalId): ?Workplace
    {
        return $this->entityManager->getRepository(Workplace::class)->findOneBy([
            'source' => $source,
            'externalId' => $externalId,
        ]);
    }

    public function byId(\App\Workforce\Domain\WorkplaceId $id): ?Workplace
    {
        return $this->entityManager->find(Workplace::class, $id);
    }

    public function save(Workplace $workplace): void
    {
        $this->entityManager->persist($workplace);
    }

    public function deactivateMissingFrom(WorkplaceSource $source, array $presentExternalIds, DateTimeImmutable $now): int
    {
        $this->entityManager->flush();

        return (int) $this->entityManager->getConnection()->executeStatement(
            <<<'SQL'
                UPDATE workforce_workplaces
                   SET active = FALSE,
                       source_updated_at = :updated_at,
                       updated_at = :updated_at
                 WHERE source = :source
                   AND active = TRUE
                   AND external_id NOT IN (:external_ids)
                SQL,
            [
                'updated_at' => $now,
                'source' => $source->value,
                'external_ids' => $presentExternalIds,
            ],
            [
                'updated_at' => 'datetime_immutable',
                'external_ids' => ArrayParameterType::STRING,
            ],
        );
    }

    public function searchActive(string $term, int $limit): array
    {
        $folded = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', mb_strtolower(trim($term)));
        $tokens = preg_split('/\s+/u', false === $folded ? mb_strtolower(trim($term)) : $folded) ?: [];
        $conditions = [];
        $parameters = ['limit' => $limit];
        $types = ['limit' => \Doctrine\DBAL\ParameterType::INTEGER];

        foreach ($tokens as $index => $token) {
            $parameter = 'token'.$index;
            $conditions[] = "translate(lower(concat_ws(' ', name, municipality, province)), 'áéíóúüñ', 'aeiouun') LIKE :{$parameter} ESCAPE '\\'";
            $parameters[$parameter] = '%'.addcslashes($token, '%_\\').'%';
        }

        /** @var list<string> $ids */
        $ids = $this->entityManager->getConnection()->fetchFirstColumn(
            'SELECT id FROM workforce_workplaces WHERE active = TRUE AND '.implode(' AND ', $conditions).' ORDER BY name, municipality LIMIT :limit',
            $parameters,
            $types,
        );

        $repository = $this->entityManager->getRepository(Workplace::class);
        $results = [];
        foreach ($ids as $id) {
            $workplace = $repository->find(new \App\Workforce\Domain\WorkplaceId($id));
            if ($workplace instanceof Workplace) {
                $results[] = $workplace;
            }
        }

        return $results;
    }

    public function countActive(): int
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(workplace.id)')
            ->from(Workplace::class, 'workplace')
            ->where('workplace.active = true')
            ->getQuery()
            ->getSingleScalarResult();
    }
}
