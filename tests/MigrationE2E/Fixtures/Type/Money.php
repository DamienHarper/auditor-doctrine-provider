<?php

declare(strict_types=1);

namespace DH\Auditor\Tests\MigrationE2E\Fixtures\Type;

final readonly class Money implements \Stringable
{
    public function __construct(public string $amount, public string $currency) {}

    public function __toString(): string
    {
        return $this->amount.' '.$this->currency;
    }
}
