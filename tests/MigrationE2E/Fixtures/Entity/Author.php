<?php

declare(strict_types=1);

namespace DH\Auditor\Tests\MigrationE2E\Fixtures\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Covers the scalar types of an audit diff: strings, long and multibyte text, dates, booleans, decimals,
 * floats, big integers, a backed enum, a JSON field (deep diffs) and an ignored column.
 */
#[ORM\Entity]
#[ORM\Table(name: 'e2e_author')]
class Author implements \Stringable
{
    #[ORM\Id]
    #[ORM\Column(type: Types::INTEGER)]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    public ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    public ?string $email = null;

    /** Ignored by the provider configuration. */
    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    public ?string $password = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $bio = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    public ?\DateTimeImmutable $birthDate = null;

    #[ORM\Column(type: Types::BOOLEAN)]
    public bool $active = true;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    public ?string $rating = null;

    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    public ?float $score = null;

    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    public int|string|null $followers = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    public ?array $settings = null;

    #[ORM\Column(type: Types::STRING, length: 16, enumType: Status::class)]
    public Status $status = Status::Draft;

    /** @var Collection<int, Post> */
    #[ORM\OneToMany(targetEntity: Post::class, mappedBy: 'author')]
    public Collection $posts;

    public function __construct(#[ORM\Column(type: Types::STRING, length: 255)]
        public string $fullname)
    {
        $this->posts = new ArrayCollection();
    }

    public function __toString(): string
    {
        return $this->fullname;
    }
}
