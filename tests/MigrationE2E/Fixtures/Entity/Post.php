<?php

declare(strict_types=1);

namespace DH\Auditor\Tests\MigrationE2E\Fixtures\Entity;

use DH\Auditor\Tests\MigrationE2E\Fixtures\Type\Money;
use DH\Auditor\Tests\MigrationE2E\Fixtures\Type\MoneyType;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Covers a ManyToOne, the owning side of a ManyToMany, a OneToMany and a custom Doctrine type.
 */
#[ORM\Entity]
#[ORM\Table(name: 'e2e_post')]
class Post implements \Stringable
{
    #[ORM\Id]
    #[ORM\Column(type: Types::INTEGER)]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    public ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 255)]
    public string $title = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $body = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public ?\DateTimeImmutable $publishedAt = null;

    #[ORM\Column(type: MoneyType::NAME, nullable: true)]
    public ?Money $price = null;

    #[ORM\ManyToOne(targetEntity: Author::class, inversedBy: 'posts')]
    #[ORM\JoinColumn(name: 'author_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    public ?Author $author = null;

    /** @var Collection<int, Tag> */
    #[ORM\ManyToMany(targetEntity: Tag::class, inversedBy: 'posts')]
    #[ORM\JoinTable(name: 'e2e_post__tag')]
    public Collection $tags;

    /** @var Collection<int, Comment> */
    #[ORM\OneToMany(targetEntity: Comment::class, mappedBy: 'post')]
    public Collection $comments;

    public function __construct(string $title)
    {
        $this->title = $title;
        $this->tags = new ArrayCollection();
        $this->comments = new ArrayCollection();
    }

    public function __toString(): string
    {
        return $this->title;
    }
}
