<?php

declare(strict_types=1);

namespace DH\Auditor\Tests\MigrationE2E\Fixtures\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Audited in the secondary storage.
 */
#[ORM\Entity]
#[ORM\Table(name: 'e2e_comment')]
class Comment implements \Stringable
{
    #[ORM\Id]
    #[ORM\Column(type: Types::INTEGER)]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Post::class, inversedBy: 'comments')]
    #[ORM\JoinColumn(name: 'post_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    public ?Post $post = null;

    public function __construct(
        #[ORM\Column(type: Types::TEXT)]
        public string $body
    ) {}

    public function __toString(): string
    {
        return mb_substr($this->body, 0, 20);
    }
}
