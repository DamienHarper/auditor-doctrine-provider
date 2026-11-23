<?php

declare(strict_types=1);

namespace DH\Auditor\Tests\MigrationE2E\Fixtures\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Inverse side of a ManyToMany, audited in the secondary storage.
 */
#[ORM\Entity]
#[ORM\Table(name: 'e2e_tag')]
class Tag implements \Stringable
{
    #[ORM\Id]
    #[ORM\Column(type: Types::INTEGER)]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    public ?int $id = null;

    /** @var Collection<int, Post> */
    #[ORM\ManyToMany(targetEntity: Post::class, mappedBy: 'tags')]
    public Collection $posts;

    public function __construct(#[ORM\Column(type: Types::STRING, length: 255)]
        public string $title)
    {
        $this->posts = new ArrayCollection();
    }

    public function __toString(): string
    {
        return $this->title;
    }
}
