<?php
declare(strict_types=1);
namespace KK\Korsac\Cart;
interface ConfigurationSnapshotRepositoryInterface
{
    public function create(ConfigurationSnapshot $snapshot): ConfigurationSnapshotReference;
    public function findByKey(string $snapshotKey): ?ConfigurationSnapshot;
    public function deleteUnattached(ConfigurationSnapshotReference $reference): void;
}
