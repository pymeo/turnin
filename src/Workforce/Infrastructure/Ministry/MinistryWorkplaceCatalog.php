<?php

declare(strict_types=1);

namespace App\Workforce\Infrastructure\Ministry;

use App\Workforce\Domain\CompleteWorkplaceCatalog;
use App\Workforce\Domain\ImportedWorkplace;
use App\Workforce\Domain\WorkplaceCatalogSource;
use App\Workforce\Domain\WorkplaceCatalogUnavailable;
use App\Workforce\Domain\WorkplaceOwnership;
use App\Workforce\Domain\WorkplaceSource;
use App\Workforce\Domain\WorkplaceType;
use InvalidArgumentException;

final readonly class MinistryWorkplaceCatalog implements WorkplaceCatalogSource
{
    /** @var list<string> */
    private const PUBLIC_HOSPITAL_DEPENDENCIES = [
        'Instituto de Gestión Sanitaria-Ingesa',
        'Servicios e Institutos de Salud de Las Comunidades Autónomas',
        'Otros Centros o Establecimientos Públicos de Dependencia Estatal',
        'Otros Centros o Establecimientos Públicos de Dependencia Autonómica',
        'Diputación o Cabildo',
        'Municipio',
        'Ministerio de Defensa',
        'Otras Entidades u o rganismos Públicos',
    ];

    public function __construct(
        private MinistryCatalogReader $reader,
        private WorkplaceSource $catalogSource,
        private string $csvUrl,
        private string $spreadsheetUrl,
        private string $worksheet,
    ) {
    }

    public function source(): WorkplaceSource
    {
        return $this->catalogSource;
    }

    public function fetch(): CompleteWorkplaceCatalog
    {
        $rows = $this->reader->read($this->csvUrl, $this->spreadsheetUrl, $this->worksheet);
        $workplaces = [];
        $rejected = 0;

        foreach ($rows as $row) {
            try {
                $workplace = $this->map($row);
                if (null === $workplace) {
                    continue;
                }

                if (isset($workplaces[$workplace->externalId])) {
                    if ($workplaces[$workplace->externalId] != $workplace) {
                        ++$rejected;
                    }
                    continue;
                }

                $workplaces[$workplace->externalId] = $workplace;
            } catch (InvalidArgumentException) {
                ++$rejected;
            }
        }

        if ([] === $workplaces) {
            throw new WorkplaceCatalogUnavailable('The official catalog contained no usable public workplaces.');
        }

        return new CompleteWorkplaceCatalog($this->catalogSource, array_values($workplaces), $rejected);
    }

    /** @param array<string, string> $row */
    private function map(array $row): ?ImportedWorkplace
    {
        return match ($this->catalogSource) {
            WorkplaceSource::MINISTRY_PRIMARY_CARE => $this->primaryCare($row),
            WorkplaceSource::MINISTRY_URGENT_CARE => $this->urgentCare($row),
            WorkplaceSource::MINISTRY_HOSPITALS => $this->hospital($row),
        };
    }

    /** @param array<string, string> $row */
    private function primaryCare(array $row): ?ImportedWorkplace
    {
        $management = $this->value($row, 'T_NOMBRE', 'Modalidad de Gestión', 'Modalidad de gestión');
        if ('' !== $management && !str_starts_with($this->fold($management), 'publica')) {
            return null;
        }

        $officialType = $this->fold($this->required($row, 'TIPOCENTRO', 'Tipo de Centro'));
        $type = match ($officialType) {
            'centro salud', 'centro de salud' => WorkplaceType::HEALTH_CENTER,
            'consultorio local', 'consultorio de atencion primaria' => WorkplaceType::LOCAL_CLINIC,
            default => throw new InvalidArgumentException('Unknown primary-care workplace type.'),
        };

        // SIAP's CCN column is blank for hundreds of otherwise valid public
        // centres and even contains duplicate CCNs for distinct centres. Its
        // own IDCENTRO is therefore the stable row identity when numeric; the
        // two rows where IDCENTRO contains the annotation "ALTA 25" fall back
        // to their distinct CCNs.
        $sourceId = $this->value($row, 'IDCENTRO', 'Código Autonómico del Centro', 'Código autonómico');
        $externalId = ctype_digit($sourceId)
            ? 'siap:'.$sourceId
            : 'ccn:'.$this->ccn($this->required($row, 'CODIGO_CCN', 'Código CCN', 'CCN'));

        return new ImportedWorkplace(
            $this->catalogSource,
            $externalId,
            $this->required($row, 'SIAP_CENTROS.NOMBRE', 'Nombre', 'Nombre Centro'),
            $type,
            $this->required($row, 'SIAP_CCAA.NOMBRE', 'Comunidad Autónoma', 'CCAA'),
            $this->required($row, 'SIAP_PROVINCIAS.NOMBRE', 'Provincia'),
            $this->required($row, 'MUNICIPIO', 'Municipio'),
            WorkplaceOwnership::PUBLIC,
            $this->value($row, 'SIAP_AREASALUD_CD.NOMBRE', 'Área de Salud'),
            $this->value($row, 'SIAP_ZONABASICA.NOMBRE', 'Zona Básica de Salud'),
        );
    }

    /** @param array<string, string> $row */
    private function urgentCare(array $row): ImportedWorkplace
    {
        $name = $this->value($row, 'T_UBICACION', 'Ubicación');
        if ('' === $name) {
            $name = $this->required($row, 'T_NOMBRE', 'Dispositivo', 'Nombre');
        }

        return new ImportedWorkplace(
            $this->catalogSource,
            $this->required($row, 'DISP_EXTRA_ID', 'Código', 'Id'),
            $name,
            WorkplaceType::OUT_OF_HOSPITAL_URGENT_CARE,
            $this->required($row, 'SIAP_CCAA.NOMBRE', 'Comunidad Autónoma', 'CCAA'),
            $this->required($row, 'SIAP_PROVINCIAS.NOMBRE', 'Provincia'),
            $this->required($row, 'MUNICIPIO', 'Municipio'),
        );
    }

    /** @param array<string, string> $row */
    private function hospital(array $row): ?ImportedWorkplace
    {
        $dependency = $this->required($row, 'Dependencia Funcional', 'DEPENDENCIA FUNCIONAL');
        $publicDependencies = array_map($this->fold(...), self::PUBLIC_HOSPITAL_DEPENDENCIES);
        if (!\in_array($this->fold($dependency), $publicDependencies, true)) {
            return null;
        }

        return new ImportedWorkplace(
            $this->catalogSource,
            $this->ccn($this->required($row, 'Código CCN', 'CCN')),
            $this->required($row, 'Nombre', 'Nombre Centro'),
            WorkplaceType::HOSPITAL,
            $this->required($row, 'Comunidad Autónoma', 'CCAA'),
            $this->required($row, 'Provincia'),
            $this->required($row, 'Municipio'),
        );
    }

    /** @param array<string, string> $row */
    private function required(array $row, string ...$columns): string
    {
        $value = $this->value($row, ...$columns);
        if ('' === $value) {
            throw new InvalidArgumentException('Missing required official catalog field.');
        }

        return $value;
    }

    /** @param array<string, string> $row */
    private function value(array $row, string ...$columns): string
    {
        foreach ($columns as $column) {
            if (isset($row[$column])) {
                return trim($row[$column]);
            }
        }

        return '';
    }

    private function ccn(string $value): string
    {
        return ctype_digit($value) ? str_pad($value, 10, '0', \STR_PAD_LEFT) : $value;
    }

    private function fold(string $value): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);

        return strtolower(trim(false === $ascii ? $value : $ascii));
    }
}
