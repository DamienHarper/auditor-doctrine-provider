<?php

declare(strict_types=1);

namespace DH\Auditor\Tests\Provider\Doctrine\Fixtures\InverseSideDeletion;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'inverse_side_deletion_bundle')]
class Bundle
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: Types::INTEGER, options: ['unsigned' => true])]
    private int $id;

    /**
     * @var Collection<int, BundleItem>
     */
    #[ORM\OneToMany(targetEntity: BundleItem::class, mappedBy: 'bundle', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $items;

    #[ORM\OneToOne(targetEntity: BundleCover::class, mappedBy: 'bundle')]
    private ?BundleCover $cover = null;

    /**
     * @var Collection<int, BundleTag>
     */
    #[ORM\ManyToMany(targetEntity: BundleTag::class, inversedBy: 'bundles')]
    #[ORM\JoinTable(name: 'inverse_side_deletion_bundle__tag')]
    private Collection $tags;

    public function __construct(
        #[ORM\Column(type: Types::STRING, length: 255)]
        private string $name,
    ) {
        $this->items = new ArrayCollection();
        $this->tags = new ArrayCollection();
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function addItem(BundleItem $item): self
    {
        if (!$this->items->contains($item)) {
            $this->items->add($item);
        }

        return $this;
    }

    public function removeItem(BundleItem $item): self
    {
        $this->items->removeElement($item);

        return $this;
    }

    /**
     * @return Collection<int, BundleItem>
     */
    public function getItems(): Collection
    {
        return $this->items;
    }

    public function getCover(): ?BundleCover
    {
        return $this->cover;
    }

    public function setCover(?BundleCover $cover): self
    {
        $this->cover = $cover;

        return $this;
    }

    public function addTag(BundleTag $tag): self
    {
        if (!$this->tags->contains($tag)) {
            $this->tags->add($tag);
            $tag->getBundles()->add($this);
        }

        return $this;
    }

    public function replaceTagsWithEmptyCollection(): self
    {
        foreach ($this->tags as $tag) {
            $tag->getBundles()->removeElement($this);
        }

        $this->tags = new ArrayCollection();

        return $this;
    }

    public function removeTag(BundleTag $tag): self
    {
        $this->tags->removeElement($tag);
        $tag->getBundles()->removeElement($this);

        return $this;
    }

    /**
     * @return Collection<int, BundleTag>
     */
    public function getTags(): Collection
    {
        return $this->tags;
    }
}
