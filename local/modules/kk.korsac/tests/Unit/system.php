<?php

declare(strict_types=1);

use KK\Korsac\System\SystemCreationRequest;
use KK\Korsac\System\SystemCreationService;
use KK\Korsac\System\SystemException;
use KK\Korsac\System\SystemIdGenerator;
use KK\Korsac\System\SystemRecord;
use KK\Korsac\System\SystemRepositoryInterface;
use KK\Korsac\System\SystemSequenceAllocatorInterface;
use KK\Korsac\System\SystemComponent;
use KK\Korsac\Install\SystemTableGatewayInterface;
use KK\Korsac\Install\SystemSchemaDefinition;
use KK\Korsac\Install\SystemMigrationService;
use KK\Korsac\Install\Migration\SystemCore;
use KK\Korsac\Health\SystemSchemaSelfCheck;

$systemInput = static fn(): array => ['SITE_ID'=>'s1','ORDER_ID'=>10,'ORDER_BASKET_ID'=>20,'ORDER_UNIT_INDEX'=>1,'SYSTEM_TYPE'=>'MODEL','MODEL_NAME'=>'KORSAC PLAY 1440'];
$systemError = static function (callable $action, string $code) use ($assert): void {
    try { $action(); } catch (SystemException $e) { $diagnostic=$e->diagnostic(); $assert($diagnostic['code'] === $code && array_diff(array_keys($diagnostic),['code','table','field','index']) === []); $assert($e->getMessage() === $code); return; }
    throw new RuntimeException('Expected '.$code);
};
$systemRepo = static fn() => new class implements SystemRepositoryInterface {
    public array $items = [];
    public bool $race = false;
    public bool $fail = false;
    public bool $currentReadUsed = false;
    public function create(SystemRecord $s): SystemRecord {
        if ($this->fail) throw new RuntimeException('SQL credentials /absolute/path');
        $stored = new SystemRecord(count($this->items)+1, $s->systemId, $s->source, $s->userId, $s->lifecycleState, $s->createdAt, $s->updatedAt);
        $this->items[] = $stored;
        if ($this->race) throw new RuntimeException('Duplicate order unit');
        return $stored;
    }
    public function findById(int $id): ?SystemRecord { foreach ($this->items as $s) if ($s->id === $id) return $s; return null; }
    public function findBySystemId(string $id): ?SystemRecord { foreach ($this->items as $s) if ($s->systemId === $id) return $s; return null; }
    public function findByOrderUnit(int $orderId, int $basketId, int $unitIndex, bool $currentRead = false): ?SystemRecord {
        $this->currentReadUsed = $this->currentReadUsed || $currentRead;
        foreach ($this->items as $s) if ([$s->source->orderId,$s->source->orderBasketId,$s->source->orderUnitIndex] === [$orderId,$basketId,$unitIndex]) return $s;
        return null;
    }
};
$systemSequence = static fn() => new class implements SystemSequenceAllocatorInterface {
    public array $periods = [];
    public function allocate(string $period): int { return $this->periods[$period] = ($this->periods[$period] ?? 0)+1; }
};
$systemSnapshots = static fn() => new class implements \KK\Korsac\Cart\ConfigurationSnapshotRepositoryInterface {
    public ?\KK\Korsac\Cart\ConfigurationSnapshot $snapshot = null;
    public int $reads = 0;
    public function findByKey(string $key): ?\KK\Korsac\Cart\ConfigurationSnapshot { ++$this->reads; return $this->snapshot; }
    public function create(\KK\Korsac\Cart\ConfigurationSnapshot $s): \KK\Korsac\Cart\ConfigurationSnapshotReference { throw new RuntimeException('Not used'); }
    public function deleteUnattached(\KK\Korsac\Cart\ConfigurationSnapshotReference $r): void { throw new RuntimeException('Not used'); }
};
$systemClock = static fn(): DateTimeImmutable => new DateTimeImmutable('2026-10-12 10:30:00', new DateTimeZone('Europe/Moscow'));
$systemGateway = static fn() => new class implements SystemTableGatewayInterface {
    public array $tables = [];
    public int $writes = 0;
    public function describe(string $table): ?array { return $this->tables[$table] ?? null; }
    public function createTable(string $table): void {
        $definition = SystemSchemaDefinition::tables()[$table];
        foreach ($definition['columns'] as &$c) if (isset($c['size'])) $c['collation'] = 'utf8mb4_unicode_ci';
        $this->tables[$table] = ['engine'=>'InnoDB','columns'=>$definition['columns'],'indexes'=>['PRIMARY'=>$definition['indexes']['PRIMARY']]];
        ++$this->writes;
    }
    public function createIndex(string $table, string $name, array $index): void { $this->tables[$table]['indexes'][$name] = $index; ++$this->writes; }
};

$test('System ID formats the complete monthly range and rejects exhaustion', static function () use ($assert,$systemError,$systemClock): void {
    foreach ([1=>'KS-2610-0001',12=>'KS-2610-0012',9999=>'KS-2610-9999'] as $number=>$expected) $assert(SystemIdGenerator::format($systemClock(), $number) === $expected);
    $systemError(static fn() => SystemIdGenerator::format($systemClock(),10000),'system_sequence_exhausted');
    $systemError(static fn() => SystemIdGenerator::format($systemClock(),0),'system_sequence_invalid');
});
$test('System ID namespaces follow the server creation month and timezone', static function () use ($assert,$systemSequence): void {
    $sequence = $systemSequence(); $ids = new SystemIdGenerator($sequence);
    $assert($ids->generate(new DateTimeImmutable('2026-10-31 23:59:59')) === 'KS-2610-0001');
    $assert($ids->generate(new DateTimeImmutable('2026-11-01 00:00:00')) === 'KS-2611-0001');
    $assert($ids->generate(new DateTimeImmutable('2026-10-31 23:59:59')) === 'KS-2610-0002');
    $assert($ids->generate(new DateTimeImmutable('2026-10-31 22:00:00+00:00')->setTimezone(new DateTimeZone('Europe/Moscow'))) === 'KS-2611-0002');
});
$test('System creation is idempotent and starts unowned ACTIVE with a display snapshot', static function () use ($assert,$systemRepo,$systemSequence,$systemSnapshots,$systemClock,$systemInput): void {
    $repo=$systemRepo(); $seq=$systemSequence(); $snapshots=$systemSnapshots(); $service=new SystemCreationService($repo,new SystemIdGenerator($seq),$snapshots,$systemClock);
    $input=$systemInput(); $one=$service->create($input); $input['MODEL_NAME']='Renamed catalog product'; $two=$service->create($input);
    $assert($one === $two && $one->systemId === 'KS-2610-0001' && count($repo->items) === 1);
    $assert($one->userId === null && $one->lifecycleState === 'ACTIVE' && $one->source->modelName === 'KORSAC PLAY 1440');
    $assert($seq->periods === ['2610'=>1] && $snapshots->reads === 0);
    $input['ORDER_UNIT_INDEX']=2; $second=$service->create($input); $assert($second->systemId === 'KS-2610-0002' && $second->id !== $one->id);
});
$test('System creation resolves concurrent order-unit inserts and preserves sequence gaps', static function () use ($assert,$systemRepo,$systemSequence,$systemSnapshots,$systemClock,$systemInput,$systemError): void {
    $repo=$systemRepo(); $repo->race=true; $seq=$systemSequence(); $service=new SystemCreationService($repo,new SystemIdGenerator($seq),$systemSnapshots(),$systemClock);
    $s=$service->create($systemInput()); $assert($s === $repo->items[0] && count($repo->items) === 1 && $repo->currentReadUsed);
    $repo->race=false; $repo->fail=true; $input=$systemInput(); $input['ORDER_UNIT_INDEX']=2;
    $systemError(static fn()=>$service->create($input),'system_persist_failed');
    $repo->fail=false; $assert($service->create($input)->systemId === 'KS-2610-0003');
});
$test('System request accepts MODEL and CUSTOM with coherent optional product identity', static function () use ($assert,$systemInput): void {
    foreach (['MODEL','CUSTOM'] as $type) { $input=$systemInput(); $input['SYSTEM_TYPE']=$type; $assert(SystemCreationRequest::fromArray($input)->systemType === $type); }
    $input=$systemInput()+['IBLOCK_ID'=>2,'PRODUCT_ID'=>4]; $assert(SystemCreationRequest::fromArray($input)->productId === 4);
});
$test('System request rejects malformed source, bounds, product pairs and snapshot keys', static function () use ($systemInput,$systemError): void {
    foreach (['ORDER_ID','ORDER_BASKET_ID','ORDER_UNIT_INDEX'] as $key) foreach ([0,-1,'1',1.5,true,4294967296,null] as $value) {
        $input=$systemInput(); $input[$key]=$value; $systemError(static fn()=>SystemCreationRequest::fromArray($input),'system_source_invalid');
    }
    foreach (['SITE_ID'=>['',str_repeat('a',11),"s1\0"], 'MODEL_NAME'=>[' ',str_repeat('я',256),"bad\nname",[]]] as $key=>$values) foreach ($values as $value) {
        $input=$systemInput(); $input[$key]=$value; $systemError(static fn()=>SystemCreationRequest::fromArray($input),'system_source_invalid');
    }
    foreach ([['IBLOCK_ID'=>2],['PRODUCT_ID'=>4],['IBLOCK_ID'=>0,'PRODUCT_ID'=>4],['CONFIG_SNAPSHOT_KEY'=>'bad'],['CONFIG_SNAPSHOT_KEY'=>str_repeat('A',64)]] as $extra) $systemError(static fn()=>SystemCreationRequest::fromArray($systemInput()+$extra),'system_source_invalid');
    $input=$systemInput(); $input['SYSTEM_TYPE']='computer'; $systemError(static fn()=>SystemCreationRequest::fromArray($input),'system_type_invalid');
});
$test('System caller cannot override identifier owner lifecycle or other later-stage fields', static function () use ($systemInput,$systemError): void {
    foreach (['SYSTEM_ID','USER_ID','LIFECYCLE_STATE','PUBLIC_ID','SECRET_CODE'] as $field) $systemError(static fn()=>SystemCreationRequest::fromArray($systemInput()+[$field=>'override']),'system_source_invalid');
});
$test('System snapshot validation rejects missing site and product mismatches before allocation', static function () use ($assert,$systemRepo,$systemSequence,$systemSnapshots,$systemClock,$systemInput,$systemError): void {
    $repo=$systemRepo(); $seq=$systemSequence(); $snapshots=$systemSnapshots(); $service=new SystemCreationService($repo,new SystemIdGenerator($seq),$snapshots,$systemClock);
    $key=str_repeat('a',64); $input=$systemInput()+['CONFIG_SNAPSHOT_KEY'=>$key,'IBLOCK_ID'=>2,'PRODUCT_ID'=>4];
    $systemError(static fn()=>$service->create($input),'system_snapshot_not_found');
    foreach ([['other',2,4],['s1',3,4],['s1',2,5]] as [$site,$iblock,$product]) {
        $snapshots->snapshot=new \KK\Korsac\Cart\ConfigurationSnapshot($key,str_repeat('b',64),$site,$iblock,$product,1,'RUB',0,0,0,'{}',[]);
        $systemError(static fn()=>$service->create($input),'system_snapshot_mismatch');
    }
    $assert($seq->periods === [] && $repo->items === []);
    $snapshots->snapshot=new \KK\Korsac\Cart\ConfigurationSnapshot($key,str_repeat('b',64),'s1',2,4,1,'RUB',0,0,0,'{}',[]);
    $s=$service->create($input); $assert($s->source->snapshotKey === $key);
    $snapshots->snapshot=null; $assert($service->create($input) === $s); // Existing units do not reconstruct/revalidate their immutable snapshot.
});
$test('System component records distinguish physical slots and retain removed history and internal serials', static function () use ($assert,$systemClock,$systemError): void {
    $now=$systemClock(); $old=new SystemComponent(1,10,'SSD','SSD_1','SSD 1','Old disk','private-SN',$now,$now->modify('+1 day'),$now,$now);
    $new=new SystemComponent(2,10,'SSD','SSD_1','SSD 1','New disk',null,$now->modify('+1 day'),null,$now,$now);
    $second=new SystemComponent(3,10,'SSD','SSD_2','SSD 2','Second disk',null,$now,null,$now,$now);
    $assert($old->removedAt !== null && $new->removedAt === null && $second->slotKey !== $new->slotKey && $old->serialNumber === 'private-SN');
    $systemError(static fn()=>new SystemComponent(0,0,'SSD','SSD_1','SSD','Disk',null,$now,null,$now,$now),'system_component_invalid');
    $systemError(static fn()=>new SystemComponent(0,1,'SSD','SSD_1','SSD','Disk',null,$now,$now->modify('-1 day'),$now,$now),'system_component_invalid');
});
$test('System migration installs all tables and indexes idempotently and health detects incompatibility', static function () use ($assert,$systemGateway): void {
    $gateway=$systemGateway(); $migration=new SystemCore($gateway); $migration->up(); $writes=$gateway->writes;
    $assert(count($gateway->tables) === 3 && (new SystemSchemaSelfCheck($gateway))->run()['ok']);
    $migration->up(); $assert($gateway->writes === $writes);
    $table=SystemSchemaDefinition::SYSTEM; unset($gateway->tables[$table]['indexes']['ux_kk_korsac_system_order_unit']);
    $assert(!(new SystemSchemaSelfCheck($gateway))->run()['ok']); $migration->up(); $assert($gateway->writes === $writes+1);
    $gateway->tables[$table]['indexes']['ux_kk_korsac_system_id']['unique']=false;
    $assert(in_array('system_index_mismatch',array_column((new SystemSchemaSelfCheck($gateway))->run()['errors'],'code'),true));
});
$test('System migration refuses incompatible schema before writing any other table', static function () use ($assert,$systemGateway,$systemError): void {
    foreach (['type','nullable','engine','primary'] as $broken) {
        $g=$systemGateway(); $g->createTable(SystemSchemaDefinition::SEQUENCE); $t=&$g->tables[SystemSchemaDefinition::SEQUENCE];
        if ($broken==='type') $t['columns']['LAST_VALUE']['type']='bigint unsigned';
        if ($broken==='nullable') $t['columns']['LAST_VALUE']['nullable']=true;
        if ($broken==='engine') $t['engine']='MyISAM';
        if ($broken==='primary') $t['indexes']['PRIMARY']['columns']=['LAST_VALUE'];
        $before=$g->writes; $systemError(static fn()=>(new SystemCore($g))->up(),'system_schema_incompatible'); $assert($g->writes === $before);
    }
});
$test('System health detects missing columns prefix indexes and MariaDB integer widths correctly', static function () use ($assert,$systemGateway): void {
    $g=$systemGateway(); (new SystemCore($g))->up(); $t=&$g->tables[SystemSchemaDefinition::SYSTEM];
    $t['columns']['ID']['type']='int(10) unsigned'; $assert((new SystemSchemaSelfCheck($g))->run()['ok']);
    $t['indexes']['ux_kk_korsac_system_id']['partial']=true; unset($t['columns']['SITE_ID']);
    $codes=array_column((new SystemSchemaSelfCheck($g))->run()['errors'],'code'); $assert(in_array('system_column_missing',$codes,true) && in_array('system_index_mismatch',$codes,true));
});
$test('System upgrade records only migration 006 once without rewriting previous history', static function () use ($assert,$systemGateway): void {
    $store=new class implements \KK\Korsac\Install\MigrationStoreInterface {
        public array $ids=['2026_10_02_004_configuration_snapshot'=>true,'2026_10_03_005_option_image'=>true];
        public function has(string $id): bool { return isset($this->ids[$id]); }
        public function markApplied(string $id): void { $this->ids[$id]=true; }
    };
    $service=new SystemMigrationService($store,$systemGateway()); $assert($service->migrate() === [SystemCore::ID]); $assert($service->migrate() === [] && count($store->ids) === 3);
});

$sequenceConnection = static fn(int $value = 0) => new class($value) {
    public array $events = [];
    public bool $fail = false;
    public function __construct(public int $value) {}
    public function startTransaction(): void { $this->events[]='begin'; }
    public function queryExecute(string $sql): void {
        if ($this->fail) throw new RuntimeException('SQL secret connection credentials');
        if (str_starts_with($sql,'INSERT')) { $this->events[]='upsert'; return; }
        if (!preg_match('/SET `LAST_VALUE`=([0-9]+),/', $sql, $matches)) throw new RuntimeException('Unexpected SQL');
        $this->value = (int)$matches[1]; $this->events[]='update';
    }
    public function query(string $sql): object {
        if (!str_ends_with($sql,'FOR UPDATE')) throw new RuntimeException('Unprotected sequence read');
        $this->events[]='lock';
        return new class($this->value) { public function __construct(private int $value) {} public function fetch(): array { return ['LAST_VALUE'=>$this->value]; } };
    }
    public function commitTransaction(): void { $this->events[]='commit'; }
    public function rollbackTransaction(): void { $this->events[]='rollback'; }
    public function disconnect(): void { $this->events[]='disconnect'; }
};
$test('System SQL allocator locks increments and commits before returning a durable number', static function () use ($assert,$sequenceConnection): void {
    $connection=$sequenceConnection(11);
    $allocator=new \KK\Korsac\System\BitrixSystemSequenceAllocator(static fn()=>$connection);
    $assert($allocator->allocate('2610') === 12);
    $assert($connection->events === ['begin','upsert','lock','update','commit','disconnect']);
});
$test('System SQL allocator rejects exhaustion without changing or reclaiming the last value', static function () use ($assert,$sequenceConnection,$systemError): void {
    $connection=$sequenceConnection(9999);
    $allocator=new \KK\Korsac\System\BitrixSystemSequenceAllocator(static fn()=>$connection);
    $systemError(static fn()=>$allocator->allocate('2610'),'system_sequence_exhausted');
    $assert($connection->value === 9999 && $connection->events === ['begin','upsert','lock','rollback','disconnect']);
});
$test('System SQL allocator validates periods before connecting and sanitizes driver failures', static function () use ($assert,$sequenceConnection,$systemError): void {
    $connection=$sequenceConnection(); $allocator=new \KK\Korsac\System\BitrixSystemSequenceAllocator(static fn()=>$connection);
    foreach (['2613','2600','26ab',"2610'",'202610'] as $period) $systemError(static fn()=>$allocator->allocate($period),'system_sequence_invalid');
    $assert($connection->events === []);
    $connection->fail=true; $systemError(static fn()=>$allocator->allocate('2610'),'system_sequence_failed');
    $assert($connection->events === ['begin','rollback','disconnect']);
});
