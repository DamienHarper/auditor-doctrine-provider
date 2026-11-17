<?php

declare(strict_types=1);

namespace DH\Auditor\Tests\Provider\Doctrine\Fixtures\InverseSideDeletion;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'inverse_side_deletion_bundle_cover')]
class BundleCover
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: Types::INTEGER, options: ['unsigned' => true])]
    private int $id;

    public function __construct(
        #[ORM\OneToOne(targetEntity: Bundle::class, inversedBy: 'cover')]
        #[ORM\JoinColumn(nullable: false)]
        private Bundle $bundle,
    ) {
        $bundle->setCover($this);
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getBundle(): Bundle
    {
        return $this->bundle;
    }
}
