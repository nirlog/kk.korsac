<?php
declare(strict_types=1);
namespace KK\Korsac\Cart;
final readonly class ConfigurationSnapshotReference
{
    public function __construct(public int $id, public string $key) {}
}
