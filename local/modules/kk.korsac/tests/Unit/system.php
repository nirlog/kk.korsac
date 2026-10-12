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
    public function findByOrder(int $orderId): array { return array_values(array_filter($this->items,static fn(SystemRecord $s):bool=>$s->source->orderId===$orderId)); }
    public function findByOrderBasket(int $orderId,int $basketId): array { return array_values(array_filter($this->findByOrder($orderId),static fn(SystemRecord $s):bool=>$s->source->orderBasketId===$basketId)); }
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
    foreach (['ORDER_ID','ORDER_BASKET_ID','ORDER_UNIT_INDEX'] as $key) foreach ([0,-1,'1',1.5,true,2147483648,4294967295,null] as $value) {
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
    $assert($old->systemInternalId === 10 && $new->systemInternalId === 10 && $second->systemInternalId === 10);
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
    $t['columns']['ID']['type']='int(11)'; $assert((new SystemSchemaSelfCheck($g))->run()['ok']);
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

// Keep the expected domain contract explicit, independently of the production allowlist.
foreach (['ID','SYSTEM_ID','SITE_ID','SYSTEM_TYPE','MODEL_NAME','IBLOCK_ID','PRODUCT_ID','ORDER_ID','ORDER_BASKET_ID','ORDER_UNIT_INDEX','CONFIG_SNAPSHOT_KEY','CREATED_AT'] as $immutableField) {
    $test('System ORM update rejects immutable '.$immutableField, static function () use ($assert,$systemError,$immutableField): void {
        require_once __DIR__ . '/fixtures/system_table_parent.php';
        \Bitrix\Main\Entity\DataManager::$updates = [];
        foreach ([null, 'replacement'] as $value) {
            $systemError(static fn()=>\KK\Korsac\System\SystemTable::update(1, [$immutableField=>$value]), 'system_source_immutable');
            $systemError(static fn()=>\KK\Korsac\System\SystemTable::update(1, ['USER_ID'=>7, $immutableField=>$value]), 'system_source_immutable');
        }
        $assert(\Bitrix\Main\Entity\DataManager::$updates === [], 'Immutable source update reached ORM');
    });
}
$test('System ORM update delegates only intended mutable fields unchanged', static function () use ($assert): void {
    require_once __DIR__ . '/fixtures/system_table_parent.php';
    \Bitrix\Main\Entity\DataManager::$updates = [];
    $now = new DateTimeImmutable('2026-10-12 10:00:00');
    foreach ([['USER_ID'=>null], ['USER_ID'=>7], ['LIFECYCLE_STATE'=>'CANCELLED'], ['UPDATED_AT'=>$now], ['USER_ID'=>7,'LIFECYCLE_STATE'=>'ACTIVE','UPDATED_AT'=>$now]] as $data) {
        $assert(\KK\Korsac\System\SystemTable::update(1, $data) === ['delegated'=>true]);
        $assert(end(\Bitrix\Main\Entity\DataManager::$updates) === ['primary'=>1,'data'=>$data]);
    }
});
$test('System component schema reserves SYSTEM_ID for the human business identity', static function () use ($assert,$systemGateway): void {
    $tables = SystemSchemaDefinition::tables();
    $component = $tables[SystemSchemaDefinition::COMPONENT];
    $assert(!isset($component['columns']['SYSTEM_ID']));
    $assert($component['columns']['SYSTEM_INTERNAL_ID']['type'] === 'int' && !$component['columns']['SYSTEM_INTERNAL_ID']['nullable']);
    foreach (['ix_kk_korsac_component_system'=>['SYSTEM_INTERNAL_ID'], 'ix_kk_korsac_component_slot'=>['SYSTEM_INTERNAL_ID','SLOT_KEY'], 'ix_kk_korsac_component_active'=>['SYSTEM_INTERNAL_ID','REMOVED_AT']] as $name=>$columns) {
        $assert($component['indexes'][$name]['columns'] === $columns);
    }
    $assert($tables[SystemSchemaDefinition::SYSTEM]['columns']['SYSTEM_ID']['type'] === 'varchar(12)');
    $sql = SystemSchemaDefinition::createSql(SystemSchemaDefinition::COMPONENT);
    $assert(str_contains($sql, '`SYSTEM_INTERNAL_ID` INT NOT NULL') && !str_contains($sql, '`SYSTEM_ID`'));
    $gateway=$systemGateway(); (new SystemCore($gateway))->up();
    $description=&$gateway->tables[SystemSchemaDefinition::COMPONENT];
    $description['columns']['SYSTEM_ID']=$description['columns']['SYSTEM_INTERNAL_ID'];
    unset($description['columns']['SYSTEM_INTERNAL_ID']);
    $description['indexes']['ix_kk_korsac_component_system']['columns']=['SYSTEM_ID'];
    $errors=(new SystemSchemaSelfCheck($gateway))->run()['errors'];
    $assert(in_array(['code'=>'system_column_missing','table'=>SystemSchemaDefinition::COMPONENT,'field'=>'SYSTEM_INTERNAL_ID'],$errors,true));
    $assert(in_array(['code'=>'system_index_mismatch','table'=>SystemSchemaDefinition::COMPONENT,'index'=>'ix_kk_korsac_component_system'],$errors,true));
});

foreach (['ORDER_ID'=>'orderId','ORDER_BASKET_ID'=>'orderBasketId','ORDER_UNIT_INDEX'=>'orderUnitIndex','IBLOCK_ID'=>'iblockId','PRODUCT_ID'=>'productId'] as $field=>$property) {
    $test('System '.$field.' honors positive signed INT32 before any allocation', static function () use ($assert,$systemInput,$systemError,$systemRepo,$systemSequence,$systemSnapshots,$systemClock,$field,$property): void {
        $input = $systemInput()+['IBLOCK_ID'=>2,'PRODUCT_ID'=>4];
        $input[$field] = 2147483647;
        $assert(SystemCreationRequest::fromArray($input)->{$property} === 2147483647);
        $repo = $systemRepo(); $sequence = $systemSequence(); $snapshots = $systemSnapshots();
        $service = new SystemCreationService($repo,new SystemIdGenerator($sequence),$snapshots,$systemClock);
        foreach ([2147483648,4294967295] as $invalid) {
            $input[$field] = $invalid;
            $systemError(static fn()=>$service->create($input),'system_source_invalid');
            $assert($sequence->periods === [] && $repo->items === [] && $snapshots->reads === 0);
        }
        $input[$field] = 2147483647;
        $created = $service->create($input);
        $assert($created->source->{$property} === 2147483647 && $sequence->periods === ['2610'=>1]);
        $assert($service->create($input)->id === $created->id && $sequence->periods === ['2610'=>1]);
    });
}
$test('System component parent and stored ID honor positive signed INT32', static function () use ($assert,$systemClock,$systemError): void {
    $now = $systemClock();
    $component = new SystemComponent(2147483647,2147483647,'SSD','SSD_1','SSD 1','Disk',null,$now,null,$now,$now);
    $assert($component->systemInternalId === 2147483647 && $component->id === 2147483647);
    foreach ([2147483648,4294967295] as $invalid) {
        $systemError(static fn()=>new SystemComponent(0,$invalid,'SSD','SSD_1','SSD 1','Disk',null,$now,null,$now,$now),'system_component_invalid');
        $systemError(static fn()=>new SystemComponent($invalid,1,'SSD','SSD_1','SSD 1','Disk',null,$now,null,$now,$now),'system_component_invalid');
    }
});
$test('System migration uses signed D7 INT fields and rejects the unsigned review schema', static function () use ($assert,$systemGateway,$systemError): void {
    $identityFields = [
        SystemSchemaDefinition::SYSTEM=>['ID','IBLOCK_ID','PRODUCT_ID','ORDER_ID','ORDER_BASKET_ID','ORDER_UNIT_INDEX','USER_ID'],
        SystemSchemaDefinition::COMPONENT=>['ID','SYSTEM_INTERNAL_ID'],
        SystemSchemaDefinition::SEQUENCE=>['LAST_VALUE'],
    ];
    $definitions = SystemSchemaDefinition::tables();
    $gateway = $systemGateway(); (new SystemCore($gateway))->up();
    foreach ($identityFields as $table=>$fields) {
        $assert(!str_contains(SystemSchemaDefinition::createSql($table),'UNSIGNED'));
        foreach ($fields as $field) {
            $assert($definitions[$table]['columns'][$field]['type'] === 'int');
            $gateway->tables[$table]['columns'][$field]['type'] = 'int(11)';
        }
    }
    $assert((new SystemSchemaSelfCheck($gateway))->run()['ok']);
    foreach ($identityFields as $table=>$fields) foreach ($fields as $field) {
        $gateway->tables[$table]['columns'][$field]['type'] = 'int(10) unsigned';
        $errors = (new SystemSchemaSelfCheck($gateway))->run()['errors'];
        $assert(in_array(['code'=>'system_column_mismatch','table'=>$table,'field'=>$field],$errors,true));
        $writes = $gateway->writes;
        $systemError(static fn()=>(new SystemCore($gateway))->up(),'system_schema_incompatible');
        $assert($gateway->writes === $writes);
        $gateway->tables[$table]['columns'][$field]['type'] = 'int(11)';
    }
});
