<?php

declare(strict_types=1);

namespace DH\Auditor\Tests\Provider\Doctrine\InvalidFixtures\MaskedCollection;

use DH\Auditor\Attribute\Auditable;
use DH\Auditor\Attribute\Mask;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Kept out of the Fixtures directory: loading its attributes must fail (a collection cannot be masked).
 */
#[ORM\Entity]
#[ORM\Table(name: 'masked_collection_entity')]
#[Auditable]
class MaskedCollectionEntity
{
    /**
     * @var Collection<int, self>
     */
    #[ORM\ManyToMany(targetEntity: self::class)]
    #[ORM\JoinTable(name: 'masked_collection_entity_children')]
    #[Mask]
    private Collection $children;

    #[ORM\Id, ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: Types::INTEGER)]
    private int $id;

    public function __construct()
    {
        $this->children = new ArrayCollection();
    }
}
