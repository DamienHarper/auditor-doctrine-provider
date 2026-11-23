<?php

declare(strict_types=1);

namespace DH\Auditor\Tests\MigrationE2E\Fixtures\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The volume table: enough rows and transactions to span several conversion batches.
 * (Provider 1.x refuses to audit entities with a composite primary key, so there is no such v1 data to migrate.)
 */
#[ORM\Entity]
#[ORM\Table(name: 'e2e_translation')]
class Translation implements \Stringable
{
    #[ORM\Id]
    #[ORM\Column(type: Types::INTEGER)]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    public ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 8)]
    public string $locale;

    #[ORM\Column(name: 'message_key', type: Types::STRING, length: 64)]
    public string $key;

    #[ORM\Column(type: Types::TEXT)]
    public string $message;

    public function __construct(string $locale, string $key, string $message)
    {
        $this->locale = $locale;
        $this->key = $key;
        $this->message = $message;
    }

    public function __toString(): string
    {
        return $this->locale.':'.$this->key;
    }
}
