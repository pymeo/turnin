<?php

declare(strict_types=1);

namespace App\Workforce\Domain;

use InvalidArgumentException;

final readonly class ImportedWorkplace
{
    public string $externalId;
    public string $name;
    public string $autonomousCommunity;
    public string $province;
    public string $municipality;
    public ?string $healthArea;
    public ?string $basicHealthZone;

    public function __construct(
        public WorkplaceSource $source,
        string $externalId,
        string $name,
        public WorkplaceType $type,
        string $autonomousCommunity,
        string $province,
        string $municipality,
        public WorkplaceOwnership $ownership = WorkplaceOwnership::PUBLIC,
        ?string $healthArea = null,
        ?string $basicHealthZone = null,
    ) {
        $this->externalId = self::required($externalId, 'external id');
        $this->name = self::required($name, 'name');
        $this->autonomousCommunity = self::required($autonomousCommunity, 'autonomous community');
        $this->province = self::required($province, 'province');
        $this->municipality = self::required($municipality, 'municipality');
        $this->healthArea = self::optional($healthArea);
        $this->basicHealthZone = self::optional($basicHealthZone);
    }

    private static function required(string $value, string $field): string
    {
        $value = trim($value);
        if ('' === $value) {
            throw new InvalidArgumentException('An imported workplace requires a '.$field.'.');
        }

        return $value;
    }

    private static function optional(?string $value): ?string
    {
        if (null === $value || '' === trim($value)) {
            return null;
        }

        return trim($value);
    }
}
