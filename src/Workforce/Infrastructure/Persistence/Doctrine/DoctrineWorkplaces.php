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
        $normalizedTerm = false === $folded ? mb_strtolower(trim($term)) : $folded;
        $tokens = preg_split('/\s+/u', $normalizedTerm) ?: [];
        $conditions = [];
        $parameters = [
            'exact' => $normalizedTerm,
            'prefix' => addcslashes($normalizedTerm, '%_\\').'%',
            'contains' => '%'.addcslashes($normalizedTerm, '%_\\').'%',
            'limit' => $limit,
        ];
        $types = ['limit' => \Doctrine\DBAL\ParameterType::INTEGER];

        foreach ($tokens as $index => $token) {
            $parameter = 'token'.$index;
            $conditions[] = "translate(lower(concat_ws(' ', name, municipality, province)), 'áéíóúüñ', 'aeiouun') LIKE :{$parameter} ESCAPE '\\'";
            $parameters[$parameter] = '%'.addcslashes($token, '%_\\').'%';
        }

        $sql = \sprintf(
            <<<'SQL'
                SELECT id
                  FROM workforce_workplaces
                 WHERE active = TRUE AND %s
                 ORDER BY CASE
                    WHEN translate(lower(name), 'áéíóúüñ', 'aeiouun') = :exact THEN 0
                    WHEN translate(lower(municipality), 'áéíóúüñ', 'aeiouun') = :exact AND type = 'hospital' THEN 1
                    WHEN translate(lower(name), 'áéíóúüñ', 'aeiouun') LIKE :prefix ESCAPE '\' THEN 2
                    WHEN translate(lower(municipality), 'áéíóúüñ', 'aeiouun') = :exact THEN 3
                    WHEN translate(lower(name), 'áéíóúüñ', 'aeiouun') LIKE :contains ESCAPE '\' THEN 4
                    WHEN translate(lower(province), 'áéíóúüñ', 'aeiouun') = :exact AND type = 'hospital' THEN 5
                    WHEN translate(lower(province), 'áéíóúüñ', 'aeiouun') = :exact THEN 6
                    ELSE 7
                 END,
                 CASE WHEN type = 'hospital' THEN 0 ELSE 1 END,
                 name,
                 municipality
                 LIMIT :limit
                SQL,
            implode(' AND ', $conditions),
        );

        /** @var list<string> $ids */
        $ids = $this->entityManager->getConnection()->fetchFirstColumn(
            $sql,
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
