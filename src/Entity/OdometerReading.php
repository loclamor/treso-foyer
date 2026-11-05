<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\OdometerReadingRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/** Relevé de compteur kilométrique d'un véhicule à une date donnée. */
#[ORM\Entity(repositoryClass: OdometerReadingRepository::class)]
#[ORM\Table(name: 'odometer_reading')]
#[ORM\UniqueConstraint(name: 'uq_odometer_reading_vehicle_date', columns: ['vehicle_id', 'date'])]
class OdometerReading
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Vehicle::class, inversedBy: 'readings')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Vehicle $vehicle;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $date;

    #[ORM\Column]
    #[Assert\PositiveOrZero(message: 'Le kilométrage doit être positif ou nul.')]
    private int $km;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $note = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(Vehicle $vehicle, \DateTimeImmutable $date, int $km)
    {
        $this->vehicle = $vehicle;
        $this->date = $date;
        $this->km = $km;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getVehicle(): Vehicle
    {
        return $this->vehicle;
    }

    public function setVehicle(Vehicle $vehicle): static
    {
        $this->vehicle = $vehicle;

        return $this;
    }

    public function getDate(): \DateTimeImmutable
    {
        return $this->date;
    }

    public function setDate(\DateTimeImmutable $date): static
    {
        $this->date = $date;

        return $this;
    }

    public function getKm(): int
    {
        return $this->km;
    }

    public function setKm(int $km): static
    {
        $this->km = $km;

        return $this;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): static
    {
        $this->note = $note;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
