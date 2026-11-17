<?php

declare(strict_types=1);

namespace DH\Auditor\Tests\Provider\Doctrine\Fixtures\CompositeKey;

use Doctrine\ORM\Mapping as ORM;

/**
 * Identity through foreign entities: the primary key is the article plus the tag.
 */
#[ORM\Entity]
#[ORM\Table(name: 'composite_key_article_tag')]
class ArticleTag implements \Stringable
{
    public function __construct(
        #[ORM\Id]
        #[ORM\ManyToOne(targetEntity: Article::class, inversedBy: 'tags')]
        #[ORM\JoinColumn(nullable: false)]
        private Article $article,
        #[ORM\Id]
        #[ORM\ManyToOne(targetEntity: Tag::class)]
        #[ORM\JoinColumn(nullable: false)]
        private Tag $tag,
    ) {}

    public function __toString(): string
    {
        return 'article tag';
    }
}
