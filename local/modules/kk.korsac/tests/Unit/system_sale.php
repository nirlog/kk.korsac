<?php

declare(strict_types=1);

use KK\Korsac\System\ProductionStatusState;
use KK\Korsac\System\ProductionStatusHistory;
use KK\Korsac\System\ProductionStatusService;
use KK\Korsac\System\ProductionStatusGatewayInterface;
use KK\Korsac\System\SaleAutomationConfiguration;
use KK\Korsac\System\SystemSettingsGatewayInterface;
use KK\Korsac\System\OrderProjection;
use KK\Korsac\System\OrderBasketLine;
use KK\Korsac\System\OrderSystemSynchronizationService;
use KK\Korsac\System\SaleHandlerRegistration;
use KK\Korsac\Install\ProductionStatusSchemaDefinition;
use KK\Korsac\Health\ProductionStatusSchemaSelfCheck;
use KK\Korsac\Install\Migration\SystemSaleAutomation;

$productionGateway=static fn()=>new class implements ProductionStatusGatewayInterface {
    public array $current=[], $events=[], $historyRows=[];
    public bool $parentExists=true,$failHistory=false;
    public ?ProductionStatusState $raceWinner=null;
    public function transaction(Closure $op):ProductionStatusState {
        $backup=[$this->current,$this->historyRows];$this->events[]='begin';
        try { $result=$op();$this->events[]='commit';return $result; }
        catch (Throwable $error) { [$this->current,$this->historyRows]=$backup;$this->events[]='rollback';throw $error; }
    }
    public function lockCurrent(int $id):?ProductionStatusState {
        $this->events[]='lock';if (!$this->parentExists) throw new \KK\Korsac\System\SystemException('system_not_found');
        if ($this->raceWinner!==null) { $this->current[$id]=$this->raceWinner; $this->raceWinner=null; }
        return $this->current[$id] ?? null;
    }
    public function writeCurrent(ProductionStatusState $s,bool $insert):void { $this->events[]='write';$this->current[$s->systemInternalId]=$s; }
    public function appendHistory(ProductionStatusHistory $h):void { if ($this->failHistory) throw new RuntimeException('SQL password /private/path');$this->events[]='history';$this->historyRows[$h->systemInternalId][]=$h; }
    public function findCurrent(int $id):?ProductionStatusState { return $this->current[$id] ?? null; }
    public function history(int $id):array { return $this->historyRows[$id] ?? []; }
};
$saleSettings=static fn()=>new class implements SystemSettingsGatewayInterface {
    public array $values=[], $orderStatuses=['AG'];
    public function get(string $name,string $default=''):string { return $this->values[$name] ?? $default; }
    public function set(string $name,string $value):void { $this->values[$name]=$value; }
    public function isOrderStatus(string $id):bool { return in_array($id,$this->orderStatuses,true); }
};
$saleFixture=static function() use ($systemRepo,$systemSequence,$systemSnapshots,$systemClock,$productionGateway,$saleSettings):array {
    $systems=$systemRepo();$sequence=$systemSequence();$snapshots=$systemSnapshots();$settings=$saleSettings();$configuration=new SaleAutomationConfiguration($settings);$configuration->configure('AG');
    $snapshots->snapshot=new \KK\Korsac\Cart\ConfigurationSnapshot(str_repeat('a',64),str_repeat('b',64),'s1',2,4,1,'RUB',0,0,0,'{}',[]);
    $gateway=$productionGateway();$production=new ProductionStatusService($gateway,$systemClock);
    $creation=new \KK\Korsac\System\SystemCreationService($systems,new \KK\Korsac\System\SystemIdGenerator($sequence),$snapshots,$systemClock);
    $service=new OrderSystemSynchronizationService($configuration,$systems,$creation,$snapshots,$production);
    return compact('systems','sequence','snapshots','settings','configuration','gateway','production','creation','service');
};
$saleLine=static fn(mixed $q=1,array $props=[],int $id=500,string $module='kk.korsac',string $name='Historical PC'):OrderBasketLine=>new OrderBasketLine($id,$module,4,$name,$q,$props+['KORSAC_CONFIGURED'=>['Y'],'KORSAC_SNAPSHOT_KEY'=>[str_repeat('a',64)],'KORSAC_SNAPSHOT_HASH'=>[str_repeat('b',64)],'KORSAC_SNAPSHOT_VERSION'=>['1']]);
$saleOrder=static fn(array $lines,string $status='AG'):OrderProjection=>new OrderProjection(10,'s1',$status,$lines);

$test('Production initial revision history repeated status and arbitrary valid transitions',static function()use($assert,$productionGateway,$systemClock):void{
    $g=$productionGateway();$s=new ProductionStatusService($g,$systemClock);$one=$s->ensureInitial(1);
    $assert($one->statusCode==='WAREHOUSE' && $one->revision===1 && $g->history(1)[0]->fromStatusCode===null && $g->history(1)[0]->sourceCode==='SALE_AUTOMATION');
    $assert($s->setStatus(1,'WAREHOUSE','MANUAL',7)===$one && count($g->history(1))===1);
    $two=$s->setStatus(1,'PROCUREMENT','MANUAL',2147483647);
    $assert($two->revision===2 && $g->history(1)[1]->fromStatusCode==='WAREHOUSE' && $g->history(1)[1]->actorUserId===2147483647);
    $assert($s->ensureInitial(1)===$two && count($g->history(1))===2);
    $assert($s->setStatus(1,'READY_FOR_DELIVERY','MANUAL')->revision===3);
});
$test('Production validates codes source actors parent existence before writes',static function()use($assert,$productionGateway,$systemError):void{
    $g=$productionGateway();$s=new ProductionStatusService($g);
    $systemError(static fn()=>$s->setStatus(1,'bad','MANUAL'),'system_production_status_invalid');
    $systemError(static fn()=>$s->setStatus(1,'WAREHOUSE','bad'),'system_production_source_invalid');
    foreach([0,-1,2147483648,4294967295] as $id){$systemError(static fn()=>$s->setStatus($id,'WAREHOUSE','MANUAL'),'system_production_source_invalid');$systemError(static fn()=>$s->setStatus(1,'WAREHOUSE','MANUAL',$id),'system_production_source_invalid');}
    $assert($g->events===[]);$g->parentExists=false;$systemError(static fn()=>$s->ensureInitial(1),'system_not_found');$assert($g->current===[]);
});
$test('Production first-row lock observes race winner and does not append duplicate revision',static function()use($assert,$productionGateway,$systemClock):void{
    $g=$productionGateway();$winner=new ProductionStatusState(1,'WAREHOUSE',1,$systemClock());$g->raceWinner=$winner;$g->historyRows[1]=[new ProductionStatusHistory(1,1,null,'WAREHOUSE','SALE_AUTOMATION',null,$systemClock())];
    $assert((new ProductionStatusService($g))->ensureInitial(1)===$winner && count($g->history(1))===1 && $g->events===['begin','lock','commit']);
});
$test('Production rolls back current and history together when append fails',static function()use($assert,$productionGateway,$systemError):void{
    $g=$productionGateway();$s=new ProductionStatusService($g);$one=$s->ensureInitial(1);$g->failHistory=true;
    $systemError(static fn()=>$s->setStatus(1,'ASSEMBLY','MANUAL'),'system_production_persist_failed');
    $assert($g->findCurrent(1)===$one && count($g->history(1))===1 && end($g->events)==='rollback');
    $g->failHistory=false;$assert($s->setStatus(1,'ASSEMBLY','MANUAL')->revision===2);
});
$test('Production history rejects ordinary update and delete',static function()use($systemError):void{
    require_once __DIR__.'/fixtures/system_table_parent.php';
    $systemError(static fn()=>\KK\Korsac\System\ProductionStatusHistoryTable::update(1,['TO_STATUS_CODE'=>'ASSEMBLY']),'system_production_history_immutable');
    $systemError(static fn()=>\KK\Korsac\System\ProductionStatusHistoryTable::delete(1),'system_production_history_immutable');
});
$test('Sale settings default disabled validate real order status and retain manual trigger on disable',static function()use($assert,$saleSettings,$systemError):void{
    $g=$saleSettings();$c=new SaleAutomationConfiguration($g);$assert(!$c->read()->enabled && $c->read()->triggerStatusId==='');
    $systemError(static fn()=>$c->configure('PAYMENT'),'system_sale_status_invalid');$c->configure('AG');$assert($c->read()->enabled);$c->disable();$assert(!$c->read()->enabled && $c->read()->triggerStatusId==='AG');
});
$test('Production label overrides do not change stable codes',static function()use($assert,$saleSettings,$systemError):void{
    $l=new \KK\Korsac\System\ProductionStatusLabels($saleSettings());$assert($l->all()['WAREHOUSE']==='Передан кладовщику');$l->set('WAREHOUSE','На складе');$assert($l->all()['WAREHOUSE']==='На складе' && count($l->all())===5);$systemError(static fn()=>$l->set('RENAMED','test'),'system_production_status_invalid');
});
$test('Sale synchronization ignores ordinary lines and KORSAC lines without marker',static function()use($assert,$saleFixture,$saleLine,$saleOrder):void{
    $f=$saleFixture();$r=$f['service']->synchronize($saleOrder([$saleLine(1,[],500,'catalog'),$saleLine(1,['KORSAC_CONFIGURED'=>['N']],501)]));$assert($r->physicalUnitCount===0 && $f['sequence']->periods===[]);
});
$test('Sale quantity units use historical Basket name and synchronize idempotently',static function()use($assert,$saleFixture,$saleLine,$saleOrder):void{
    $f=$saleFixture();$order=$saleOrder([$saleLine(2)]);$one=$f['service']->synchronize($order);$two=$f['service']->synchronize($order);
    $assert($one->createdSystemCount===2 && $one->physicalUnitCount===2 && $two->createdSystemCount===0 && $two->existingSystemCount===2 && $one->systemIds===$two->systemIds && $f['sequence']->periods===['2610'=>2]);
    $assert(array_map(static fn($s):int=>$s->source->orderUnitIndex,$f['systems']->items)===[1,2]);
    $assert($f['systems']->items[0]->source->modelName==='Historical PC' && $f['systems']->items[0]->source->iblockId===2 && $f['systems']->items[0]->source->snapshotKey===str_repeat('a',64));
    foreach($f['systems']->items as $s)$assert($f['gateway']->findCurrent($s->id)->statusCode==='WAREHOUSE' && count($f['gateway']->history($s->id))===1);
});
$test('Sale quantity increase creates only missing units and preserves advanced production status',static function()use($assert,$saleFixture,$saleLine,$saleOrder):void{
    $f=$saleFixture();$f['service']->synchronize($saleOrder([$saleLine(1)]));$f['production']->setStatus(1,'ASSEMBLY','MANUAL');$r=$f['service']->synchronize($saleOrder([$saleLine(3)]));
    $assert($r->createdSystemCount===2 && $r->existingSystemCount===1 && $f['sequence']->periods===['2610'=>3] && $f['gateway']->findCurrent(1)->statusCode==='ASSEMBLY');
});
foreach(['decreased','removed','ineligible'] as $case)$test('Sale order edit conflict '.$case.' preserves all Systems',static function()use($assert,$saleFixture,$saleLine,$saleOrder,$systemError,$case):void{
    $f=$saleFixture();$f['service']->synchronize($saleOrder([$saleLine(2)]));$lines=match($case){'decreased'=>[$saleLine(1)],'removed'=>[],'ineligible'=>[$saleLine(2,['KORSAC_CONFIGURED'=>['N']])]};
    $systemError(static fn()=>$f['service']->synchronize($saleOrder($lines)),'system_order_unit_conflict');$assert(count($f['systems']->items)===2 && $f['sequence']->periods===['2610'=>2]);
});
$test('Sale rejects malformed fractional unbounded nonfinite quantities before allocation',static function()use($assert,$saleFixture,$saleLine,$saleOrder,$systemError):void{
    foreach([0,-1,1.5,'bad',INF,NAN,1001,true] as $quantity){$f=$saleFixture();$systemError(static fn()=>$f['service']->synchronize($saleOrder([$saleLine($quantity)])),'system_order_quantity_invalid');$assert($f['sequence']->periods===[]);}
    $assert(OrderSystemSynchronizationService::quantity('1000.000')===1000 && OrderSystemSynchronizationService::quantity(2.0)===2);
});
foreach(['KORSAC_SNAPSHOT_KEY','KORSAC_SNAPSHOT_HASH','KORSAC_SNAPSHOT_VERSION'] as $property)$test('Sale requires exactly one '.$property,static function()use($assert,$saleFixture,$saleLine,$saleOrder,$systemError,$property):void{
    foreach([[],['duplicate','duplicate']] as $values){$f=$saleFixture();$systemError(static fn()=>$f['service']->synchronize($saleOrder([$saleLine(1,[$property=>$values])])),'system_order_metadata_invalid');$assert($f['sequence']->periods===[]);}
});
foreach(['missing','hash','version','stored-version','site','product'] as $case)$test('Sale snapshot '.$case.' mismatch precedes allocation',static function()use($assert,$saleFixture,$saleLine,$saleOrder,$systemError,$case):void{
    $f=$saleFixture();$props=[];$code=match($case){'missing'=>'system_snapshot_not_found','hash'=>'system_order_snapshot_hash_mismatch','version','stored-version'=>'system_order_snapshot_version_mismatch',default=>'system_snapshot_mismatch'};
    if($case==='missing')$f['snapshots']->snapshot=null;
    if($case==='hash')$props=['KORSAC_SNAPSHOT_HASH'=>[str_repeat('c',64)]];
    if($case==='version')$props=['KORSAC_SNAPSHOT_VERSION'=>['2']];
    if(in_array($case,['site','product','stored-version'],true))$f['snapshots']->snapshot=new \KK\Korsac\Cart\ConfigurationSnapshot(str_repeat('a',64),str_repeat('b',64),$case==='site'?'other':'s1',2,$case==='product'?5:4,1,'RUB',0,0,0,'{}',[],$case==='stored-version'?2:1);
    $systemError(static fn()=>$f['service']->synchronize($saleOrder([$saleLine(1,$props)])),$code);$assert($f['sequence']->periods===[] && $f['systems']->items===[]);
});
$test('Sale preflights every line before creating first unit and repairs a failed initial status',static function()use($assert,$saleFixture,$saleLine,$saleOrder,$systemError):void{
    $f=$saleFixture();$systemError(static fn()=>$f['service']->synchronize($saleOrder([$saleLine(),$saleLine(1.5,[],501)])),'system_order_quantity_invalid');$assert($f['sequence']->periods===[]);
    $f['gateway']->failHistory=true;$systemError(static fn()=>$f['service']->synchronize($saleOrder([$saleLine()])),'system_production_persist_failed');$assert(count($f['systems']->items)===1 && $f['gateway']->current===[] && $f['sequence']->periods===['2610'=>1]);
    $f['gateway']->failHistory=false;$r=$f['service']->synchronize($saleOrder([$saleLine()]));$assert($r->createdSystemCount===0 && $r->existingSystemCount===1 && $f['gateway']->findCurrent(1)->statusCode==='WAREHOUSE' && $f['sequence']->periods===['2610'=>1]);
});
$test('Sale manual sync requires trigger without force and inspect is read-only without private output',static function()use($assert,$saleFixture,$saleLine,$saleOrder,$systemError):void{
    $f=$saleFixture();$systemError(static fn()=>$f['service']->synchronize($saleOrder([$saleLine()],'N')),'system_order_trigger_mismatch');
    $inspection=$f['service']->inspect($saleOrder([$saleLine()], 'N'));$text=json_encode($inspection);$assert(!str_contains($text,'Historical PC') && !str_contains($text,'SNAPSHOT') && $f['sequence']->periods===[]);
    $f['configuration']->disable();$assert($f['service']->synchronize($saleOrder([$saleLine()]))->createdSystemCount===1);
});

$registrationGateway=static fn(int $rows=0)=>new class($rows) implements \KK\Korsac\System\SaleHandlerRegistrationGatewayInterface {
    public int $writes=0;
    public function __construct(public int $rows) {}
    public function count():int { return $this->rows; }
    public function register():void { ++$this->writes;$this->rows=1; }
    public function unregister():void { ++$this->writes;$this->rows=0; }
};
$productionSchemaGateway=static fn()=>new class implements \KK\Korsac\Install\SystemTableGatewayInterface {
    public array $tables=[];public int $writes=0;
    public function describe(string $t):?array { return $this->tables[$t] ?? null; }
    public function createTable(string $t):void {
        $d=ProductionStatusSchemaDefinition::tables()[$t];foreach($d['columns'] as &$c)if(isset($c['size']))$c['collation']='utf8mb4_unicode_ci';
        $this->tables[$t]=['engine'=>'InnoDB','columns'=>$d['columns'],'indexes'=>['PRIMARY'=>$d['indexes']['PRIMARY']]];++$this->writes;
    }
    public function createIndex(string $t,string $name,array $index):void { $this->tables[$t]['indexes'][$name]=$index;++$this->writes; }
};
$test('Production migration 007 fresh upgrade and handler repair are idempotent',static function()use($assert,$productionSchemaGateway,$registrationGateway,$fakeStore):void{
    $tables=$productionSchemaGateway();$event=$registrationGateway();$registration=new SaleHandlerRegistration($event);$store=$fakeStore(['2026_10_12_006_system_core']);
    $service=new \KK\Korsac\Install\ProductionStatusMigrationService($store,$tables,$registration);
    $assert($service->migrate()===['2026_10_12_007_system_sale_automation'] && count($tables->tables)===2 && $event->rows===1);
    $writes=$tables->writes;$eventWrites=$event->writes;$assert($service->migrate()===[] && $tables->writes===$writes && $event->writes===$eventWrites);
    $event->rows=0;$assert($service->migrate()===[] && $event->rows===1 && $tables->writes===$writes);
    $assert($store->has('2026_10_12_006_system_core') && (new ProductionStatusSchemaSelfCheck($tables))->run()['ok']);
});
$test('Production health refuses incompatible tables before migration DDL and repairs only missing indexes',static function()use($assert,$productionSchemaGateway,$systemError):void{
    $g=$productionSchemaGateway();$m=new SystemSaleAutomation($g);$assert(!(new ProductionStatusSchemaSelfCheck($g))->run()['ok']);$m->up();$writes=$g->writes;
    unset($g->tables[ProductionStatusSchemaDefinition::HISTORY]['indexes']['ux_kk_korsac_production_revision']);$assert(!(new ProductionStatusSchemaSelfCheck($g))->run()['ok']);$m->up();$assert($g->writes===$writes+1);
    $g->tables[ProductionStatusSchemaDefinition::CURRENT]['columns']['REVISION']['type']='int unsigned';$before=$g->writes;
    $systemError(static fn()=>$m->up(),'production_schema_incompatible');$assert($g->writes===$before);
});
$test('Persistent Sale registration is exactly once health detects absence and removal retains business tables',static function()use($assert,$registrationGateway,$productionSchemaGateway):void{
    $g=$registrationGateway(2);$r=new SaleHandlerRegistration($g);$assert(!$r->check()['ok']);$r->ensure();$assert($g->rows===1);$writes=$g->writes;$r->ensure();$assert($g->writes===$writes);
    $tables=$productionSchemaGateway();(new SystemSaleAutomation($tables))->up();$retained=$tables->tables;$before=$tables->writes;$r->remove();$assert($g->rows===0 && !$r->check()['ok'] && $tables->tables===$retained && $tables->writes===$before);
    $installer=(string)file_get_contents(dirname(__DIR__,2).'/install/index.php');$uninstall=explode('public function DoUninstall(): void',$installer)[1];$uninstall=explode('public function InstallFiles()',$uninstall)[0];
    $assert(str_contains($uninstall,'SystemServiceFactory::registration()->remove()') && !str_contains($uninstall,'DROP') && !str_contains($uninstall,'delete('));
});
$test('Snapshot health now verifies existing snapshot indexes without changing migration 004',static function()use($assert):void{
    $d=\KK\Korsac\Health\SnapshotSchemaSelfCheck::definition();$actual=['engine'=>'InnoDB','columns'=>$d['columns'],'indexes'=>$d['indexes']];
    $actual['columns']['FINAL_PRICE_MINOR']['type']='bigint(20)';$actual['columns']['ID']['type']='int(10) unsigned';
    $checker=new \KK\Korsac\Health\SnapshotSchemaSelfCheck(static fn(string $t):?array=>$actual);$assert($checker->run()['ok']);
    unset($actual['indexes']['ux_kk_korsac_snapshot_key']);$assert(!(new \KK\Korsac\Health\SnapshotSchemaSelfCheck(static fn(string $t):?array=>$actual))->run()['ok']);
});
foreach(['disabled','wrong-status','enabled','domain-failure','driver-failure','logging-failure'] as $case)$test('Sale saved handler '.$case.' never re-saves Order',static function()use($assert,$saleSettings,$saleOrder,$case):void{
    $g=$saleSettings();$configuration=new SaleAutomationConfiguration($g);if($case!=='disabled')$configuration->configure('AG');
    $order=new class($case==='wrong-status'?'N':'AG') {
        public int $saves=0;public function __construct(private string $status){} public function getId():int{return 10;}public function getField(string $name):string{return $this->status;}public function save():void{++$this->saves;throw new RuntimeException('Unexpected Order mutation');}
    };
    $adapter=new class($saleOrder([])) implements \KK\Korsac\System\OrderProjectionAdapterInterface {public int $calls=0;public function __construct(private OrderProjection $projection){}public function project(object $order):OrderProjection{++$this->calls;return $this->projection;}};
    $sync=new class($case) implements \KK\Korsac\System\OrderSystemSynchronizationServiceInterface {
        public int $calls=0;public function __construct(private string $case){}
        public function synchronize(OrderProjection $order):\KK\Korsac\System\OrderSynchronizationResult{
            ++$this->calls;if($this->case==='domain-failure')throw new \KK\Korsac\System\SystemException('system_snapshot_mismatch');
            if(in_array($this->case,['driver-failure','logging-failure'],true))throw new RuntimeException('SQL secret /private/path payload');
            return new \KK\Korsac\System\OrderSynchronizationResult(10,0,0,0,0,[]);
        }
    };
    $logger=new class($case) implements \KK\Korsac\System\SaleSynchronizationLoggerInterface {
        public array $entries=[];public function __construct(private string $case){}public function log(int $id,string $code):void{$this->entries[]=[$id,$code];if($this->case==='logging-failure')throw new RuntimeException('log failed');}
    };
    (new \KK\Korsac\System\SaleOrderAutomation($configuration,$adapter,$sync,$logger))->handle($order);
    $expected=in_array($case,['disabled','wrong-status'],true)?0:1;$assert($sync->calls===$expected && $adapter->calls===$expected && $order->saves===0);
    if(str_contains($case,'failure'))$assert($logger->entries===[[10,$case==='domain-failure'?'system_snapshot_mismatch':'system_order_sync_failed']]);else $assert($logger->entries===[]);
});
$test('Bitrix Order adapter preserves duplicate property values without exposing customer fields',static function()use($assert):void{
    $item=new class {
        public function getId():int{return 500;}public function getProductId():int{return 4;}public function getQuantity():float{return 2.0;}public function getField(string $code):string{return $code==='MODULE'?'kk.korsac':'Historic name';}
        public function getPropertyCollection():array{return array_map(static fn(string $value):object=>new class($value){public function __construct(private string $value){}public function getField(string $field):string{return $field==='CODE'?'KORSAC_SNAPSHOT_KEY':$this->value;}},['a','b']);}
    };
    $order=new class($item){public function __construct(private object $item){}public function getId():int{return 10;}public function getSiteId():string{return's1';}public function getField(string $field):string{return'AG';}public function getBasket():array{return[$this->item];}};
    $p=(new \KK\Korsac\System\BitrixOrderProjectionAdapter())->project($order);$assert($p->lines[0]->properties['KORSAC_SNAPSHOT_KEY']===['a','b'] && $p->lines[0]->name==='Historic name' && $p->lines[0]->quantity===2.0);
});
$test('System creation outcome counts race winners as existing without changing create semantics',static function()use($assert,$saleFixture):void{
    $f=$saleFixture();$input=['SITE_ID'=>'s1','ORDER_ID'=>10,'ORDER_BASKET_ID'=>500,'ORDER_UNIT_INDEX'=>1,'SYSTEM_TYPE'=>'MODEL','MODEL_NAME'=>'PC'];
    $f['systems']->race=true;$outcome=$f['creation']->createWithResult($input);$assert(!$outcome->created && $outcome->system===$f['systems']->items[0]);$assert($f['creation']->create($input)===$outcome->system);
});

$test('Sale malformed metadata and duplicate PC marker fail before any allocation',static function()use($assert,$saleFixture,$saleLine,$saleOrder,$systemError):void{
    foreach([['KORSAC_SNAPSHOT_KEY'=>['not-hex']],['KORSAC_SNAPSHOT_HASH'=>['not-hex']],['KORSAC_CONFIGURED'=>['Y','Y']]] as $props){$f=$saleFixture();$systemError(static fn()=>$f['service']->synchronize($saleOrder([$saleLine(1,$props)])),'system_order_metadata_invalid');$assert($f['sequence']->periods===[]);}
});
$test('Sale immutable snapshot edits conflict rather than reassign existing physical units',static function()use($assert,$saleFixture,$saleLine,$saleOrder,$systemError):void{
    $f=$saleFixture();$f['service']->synchronize($saleOrder([$saleLine()]));
    $f['snapshots']->snapshot=new \KK\Korsac\Cart\ConfigurationSnapshot(str_repeat('c',64),str_repeat('b',64),'s1',2,4,1,'RUB',0,0,0,'{}',[]);
    $systemError(static fn()=>$f['service']->synchronize($saleOrder([$saleLine(2,['KORSAC_SNAPSHOT_KEY'=>[str_repeat('c',64)]])])),'system_order_unit_conflict');$assert($f['sequence']->periods===['2610'=>1] && count($f['systems']->items)===1);
});
$test('Production revision exhaustion never mutates current or history',static function()use($assert,$productionGateway,$systemClock,$systemError):void{
    $g=$productionGateway();$g->current[1]=new ProductionStatusState(1,'WAREHOUSE',2147483647,$systemClock());$s=new ProductionStatusService($g);
    $assert($s->ensureInitial(1)===$g->current[1]);$systemError(static fn()=>$s->setStatus(1,'ASSEMBLY','MANUAL'),'system_production_revision_exhausted');$assert($g->current[1]->revision===2147483647 && $g->historyRows===[]);
});
