<?php

declare(strict_types=1);

namespace App\Entity;

class Artist implements \JsonSerializable
{
    public function __construct(
        private ?int $idArtist = null,
        private string $name = '',
        private ?int $annee = null,
        private ?string $description = null,
    ) {}

    public static function fromArray(array $row): self
    {
        return new self(
            isset($row['idArtist']) ? (int) $row['idArtist'] : null,
            $row['Name'] ?? '',
            isset($row['Annee']) ? (int) $row['Annee'] : null,
            $row['Description'] ?? null,
        );
    }

    public function toArray(): array
    {
        return [
            'idArtist'    => $this->idArtist,
            'Name'        => $this->name,
            'Annee'       => $this->annee,
            'Description' => $this->description,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function getId(): ?int { return $this->idArtist; }
    public function getName(): string { return $this->name; }
}
