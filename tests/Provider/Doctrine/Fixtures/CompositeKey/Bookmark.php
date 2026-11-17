<?php

declare(strict_types=1);

namespace DH\Auditor\Tests\Provider\Doctrine\Fixtures\CompositeKey;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Audited entity holding a ManyToOne to an entity with a composite primary key.
 */
#[ORM\Entity]
#[ORM\Table(name: 'composite_key_bookmark')]
class Bookmark
{
    #[ORM\Id]
    #[ORM\Column(type: Types::INTEGER)]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ArticleTag::class)]
    #[ORM\JoinColumn(name: 'article_tag_article_id', referencedColumnName: 'article_id', nullable: true)]
    #[ORM\JoinColumn(name: 'article_tag_tag_id', referencedColumnName: 'tag_id', nullable: true)]
    private ?ArticleTag $articleTag = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setArticleTag(?ArticleTag $articleTag): self
    {
        $this->articleTag = $articleTag;

        return $this;
    }
}
