<?php

declare(strict_types=1);

namespace KK\Korsac\System;
use DateTimeImmutable;
final class SystemIdGenerator
{
    public function __construct(private readonly SystemSequenceAllocatorInterface $allocator) {}
    public function generate(DateTimeImmutable $createdAt): string
    {
        return self::format($createdAt, $this->allocator->allocate($createdAt->format('ym')));
    }
    public static function format(DateTimeImmutable $createdAt, int $sequence): string
    {
        if ($sequence > 9999) throw new SystemException('system_sequence_exhausted');
        if ($sequence < 1) throw new SystemException('system_sequence_invalid');
        return sprintf('KS-%s-%04d', $createdAt->format('ym'), $sequence);
    }
}
