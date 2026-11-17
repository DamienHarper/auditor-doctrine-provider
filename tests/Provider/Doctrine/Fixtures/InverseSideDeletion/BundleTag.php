<?php

declare(strict_types=1);

namespace DH\Auditor\Tests\Provider\Doctrine\Fixtures\InverseSideDeletion;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'inverse_side_deletion_bundle_tag')]
class BundleTag
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: Types::INTEGER, options: ['unsigned' => true])]
    private int $id;

    /**
     * @var Collection<int, Bundle>
     */
    #[ORM\ManyToMany(targetEntity: Bundle::class, mappedBy: 'tags')]
    private Collection $bundles;

    public function __construct()
    {
        $this->bundles = new ArrayCollection();
    }

    public function getId(): int
    {
        return $this->id;
    }

    /**
     * @return Collection<int, Bundle>
     */
    public function getBundles(): Collection
    {
        return $this->bundles;
    }
}
