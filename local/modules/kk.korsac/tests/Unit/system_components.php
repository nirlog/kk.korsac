<?php

declare(strict_types=1);

use KK\Korsac\Component\BitrixComponentGateway;
use KK\Korsac\Component\ComponentGatewayInterface;
use KK\Korsac\Component\ComponentReadService;
use KK\Korsac\Component\ComponentRowMapper;
use KK\Korsac\Component\ComponentSpec;
use KK\Korsac\Component\ComponentSystemState;
use KK\Korsac\Component\ComponentTime;
use KK\Korsac\Component\ComponentTimeline;
use KK\Korsac\Component\SystemComponentService;
use KK\Korsac\Health\SystemComponentDataHealth;
use KK\Korsac\Install\ComponentLifecycleMigrationService;
use KK\Korsac\Install\Migration\SystemComponentLifecycleCore;

$componentError=static function(callable $operation,string $code) use ($assert):void {
    try { $operation(); }
    catch (\KK\Korsac\System\SystemException $error) { $assert($error->getMessage()===$code && $error->diagnostic()===['code'=>$code] && $error->getPrevious()===null); return; }
    throw new RuntimeException('Expected '.$code);
};
$componentSpec=static fn(string $slot='SSD_1',string $name='Public disk A',?string $serial='PRIVATE_SERIAL_A'):ComponentSpec=>new ComponentSpec(explode('_',$slot)[0],$slot,str_replace('_',' ',$slot),$name,$serial);
$componentFixture=static function():array {
    $clock=new class { public int $calls=0; public DateTimeImmutable $at; public function __construct() { $this->at=new DateTimeImmutable('2026-10-16T12:00:00.123456+03:00'); } };
    $origin=ComponentTime::seconds($clock->at->modify('-1 day'));
    $gateway=new class($origin) implements ComponentGatewayInterface {
        public ?ComponentSystemState $parent; public array $records=[],$events=[];
        public int $nextId=1,$inserts=0,$closes=0;
        public ?string $failure=null;
        public function __construct(DateTimeImmutable $origin) { $this->parent=new ComponentSystemState(1,'ACTIVE',$origin); }
        public function transaction(Closure $operation):mixed {
            $backup=$this->records; $this->events[]='begin';
            try { $result=$operation(); if ($this->failure==='commit') throw new RuntimeException('PRIVATE_SERIAL SQL /private/path'); $this->events[]='commit'; return $result; }
            catch (Throwable $error) { $this->records=$backup; $this->events[]='rollback'; throw $error; }
        }
        public function lockSystem(int $id):?ComponentSystemState { $this->events[]='parent'; if ($this->failure==='parent') throw new \KK\Korsac\System\SystemException('system_component_read_failed',['field'=>'PRIVATE_SERIAL /private/path']); return $this->parent; }
        public function lockComponents(int $id,?string $slot=null):array {
            $this->events[]='components'; if ($this->failure==='read') throw new \KK\Korsac\System\SystemException('system_component_read_failed',['field'=>'PRIVATE_SERIAL SQL /private/path']);
            return $this->records;
        }
        public function closeComponent(int $id,int $expected,string $slot,DateTimeImmutable $at):void {
            ++$this->closes; $this->events[]='close';
            foreach ($this->records as $i=>$row) if ($row->id===$expected) $this->records[$i]=new \KK\Korsac\System\SystemComponent($row->id,$id,$row->typeCode,$slot,$row->slotLabel,$row->name,$row->serialNumber,$row->installedAt,$at,$row->createdAt,$at);
            if ($this->failure==='close') throw new RuntimeException('PRIVATE_SERIAL SQL /private/path');
        }
        public function insertComponent(int $id,ComponentSpec $spec,DateTimeImmutable $at):\KK\Korsac\System\SystemComponent {
            ++$this->inserts; $this->events[]='insert';
            $record=new \KK\Korsac\System\SystemComponent($this->nextId++,$id,$spec->typeCode,$spec->slotKey,$spec->slotLabel,$spec->name,$spec->serialNumber,$at,null,$at,$at); $this->records[]=$record;
            if ($this->failure==='insert') throw new RuntimeException('PRIVATE_SERIAL SQL /private/path');
            return $record;
        }
    };
    $service=new SystemComponentService($gateway,static function() use ($clock):DateTimeImmutable { ++$clock->calls; return $clock->at; });
    $reads=new ComponentReadService($gateway);
    return compact('gateway','service','reads','clock','origin');
};
$componentRaw=static function(\KK\Korsac\System\SystemComponent $row,?ComponentSystemState $parent):array {
    return ['ID'=>$row->id,'SYSTEM_INTERNAL_ID'=>$row->systemInternalId,'TYPE_CODE'=>$row->typeCode,'SLOT_KEY'=>$row->slotKey,'SLOT_LABEL'=>$row->slotLabel,'NAME'=>$row->name,'SERIAL_NUMBER'=>$row->serialNumber,'INSTALLED_AT'=>$row->installedAt->format('Y-m-d H:i:s'),'REMOVED_AT'=>$row->removedAt?->format('Y-m-d H:i:s'),'CREATED_AT'=>$row->createdAt->format('Y-m-d H:i:s'),'UPDATED_AT'=>$row->updatedAt->format('Y-m-d H:i:s'),'PARENT_ID'=>$parent?->id,'PARENT_LIFECYCLE_STATE'=>$parent?->lifecycleState,'PARENT_CREATED_AT'=>$parent?->createdAt->format('Y-m-d H:i:s')];
};

$test('Components first install uses parent lock and exact second-precision lifecycle timestamps',static function() use ($assert,$componentFixture,$componentSpec):void {
    $f=$componentFixture(); $parent=$f['gateway']->parent; $spec=$componentSpec(); $result=$f['service']->install(1,$spec); $row=$f['gateway']->records[0];
    $assert($result->created && $result->component->componentId===$row->id && $row->systemInternalId===1 && $spec->matches($row));
    $assert($row->installedAt==ComponentTime::seconds($f['clock']->at) && $row->installedAt==$row->createdAt && $row->updatedAt==$row->createdAt && $row->removedAt===null && $row->installedAt->format('u')==='000000');
    $assert($f['gateway']->events===['begin','parent','components','insert','commit'] && $parent===$f['gateway']->parent);
    $assert(!str_contains(json_encode($result,JSON_THROW_ON_ERROR),'PRIVATE_SERIAL') && !property_exists($result->component,'serialNumber'));
});
$test('Components exact install retry preserves the existing row and never reads the clock',static function() use ($assert,$componentFixture,$componentSpec,$componentError):void {
    $f=$componentFixture(); $spec=$componentSpec(); $first=$f['service']->install(1,$spec); $before=$f['gateway']->records; $f['clock']->at=$f['clock']->at->modify('+1 day');
    $again=$f['service']->install(1,$spec);
    $assert(!$again->created && $again->component->componentId===$first->component->componentId && $again->component->installedAt===$first->component->installedAt && $before===$f['gateway']->records && $f['clock']->calls===1 && $f['gateway']->inserts===1);
    foreach ([$componentSpec(name:'Other public disk'),$componentSpec(serial:null),new ComponentSpec('GPU','SSD_1','SSD 1','Public disk A','PRIVATE_SERIAL_A'),new ComponentSpec('SSD','SSD_1','Changed label','Public disk A','PRIVATE_SERIAL_A')] as $other) $componentError(static fn()=>$f['service']->install(1,$other),'system_component_slot_occupied');
    $assert($before===$f['gateway']->records && $f['gateway']->closes===0);
});
foreach (['missing','cancelled','backdated'] as $case) {
    $test('Components install rejects '.$case.' parent/time before mutation',static function() use ($assert,$componentFixture,$componentSpec,$componentError,$case):void {
        $f=$componentFixture();
        if ($case==='missing') $f['gateway']->parent=null;
        if ($case==='cancelled') $f['gateway']->parent=new ComponentSystemState(1,'CANCELLED',$f['origin']);
        if ($case==='backdated') $f['clock']->at=$f['origin']->modify('-1 second');
        $componentError(static fn()=>$f['service']->install(1,$componentSpec()),match ($case) {'missing'=>'system_component_not_found','cancelled'=>'system_component_system_inactive',default=>'system_component_state_invalid'});
        $assert($f['gateway']->records===[] && $f['gateway']->inserts===0 && $f['gateway']->closes===0);
    });
}
foreach ([0,-1,2147483648,4294967295] as $id) {
    $test('Components invalid signed identity '.$id.' is rejected before transaction',static function() use ($assert,$componentFixture,$componentSpec,$componentError,$id):void {
        $f=$componentFixture(); $componentError(static fn()=>$f['service']->install($id,$componentSpec()),'system_component_invalid');
        $componentError(static fn()=>$f['service']->replace(1,$id,$componentSpec()),'system_component_invalid'); $assert($f['gateway']->events===[]);
    });
}
foreach (['type-lower','type-long','slot-lower','slot-long','label-empty','label-trim','label-control','label-long','name-utf8','name-empty','name-trim','name-control','name-long','serial-empty','serial-trim','serial-unicode-trim','serial-control','serial-c1','serial-long','serial-utf8'] as $case) {
    $test('Components spec rejects '.$case.' without normalizing public or private data',static function() use ($componentError,$case):void {
        $data=['SSD','SSD_1','SSD 1','Disk','Private Serial'];
        [$index,$value]=match ($case) {
            'type-lower'=>[0,'ssd'],'type-long'=>[0,str_repeat('A',33)],'slot-lower'=>[1,'ssd_1'],'slot-long'=>[1,str_repeat('A',65)],
            'label-empty'=>[2,''],'label-trim'=>[2,' SSD 1'],'label-control'=>[2,"SSD\n1"],'label-long'=>[2,str_repeat('я',256)],
            'name-utf8'=>[3,"\xff"],'name-empty'=>[3,''],'name-trim'=>[3,'Disk '],'name-control'=>[3,"Disk\0"],'name-long'=>[3,str_repeat('я',256)],
            'serial-empty'=>[4,''],'serial-trim'=>[4,' SN '],'serial-unicode-trim'=>[4,"SN\u{00a0}"],'serial-control'=>[4,"SN\t1"],'serial-c1'=>[4,"SN\u{0085}1"],'serial-long'=>[4,str_repeat('я',256)],'serial-utf8'=>[4,"\xff"],
        };
        $data[$index]=$value; $componentError(static fn()=>new ComponentSpec(...$data),'system_component_invalid');
    });
}
$test('Components accept UTF8 limits extensible codes nullable serial and signed INT32 maximum',static function() use ($assert,$componentFixture):void {
    $f=$componentFixture(); $at=$f['origin']; $spec=new ComponentSpec(str_repeat('A',32),str_repeat('S',64),str_repeat('я',255),str_repeat('名',255),str_repeat('Б',255));
    $row=new \KK\Korsac\System\SystemComponent(2147483647,2147483647,$spec->typeCode,$spec->slotKey,$spec->slotLabel,$spec->name,$spec->serialNumber,$at,null,$at,$at);
    $assert($spec->matches($row)); new \KK\Korsac\System\SystemComponent(0,1,'FUTURE_TYPE','EXTENSIBLE_SLOT_42','Label','Name',null,$at,null,$at,$at);
});
$test('Components replacement closes only removal fields and inserts linked immutable hardware history',static function() use ($assert,$componentFixture,$componentSpec):void {
    $f=$componentFixture(); $first=$f['service']->install(1,$componentSpec()); $old=$f['gateway']->records[0]; $f['clock']->at=$f['clock']->at->modify('+1 hour'); $f['gateway']->events=[];
    $replacement=$componentSpec(name:'Public disk B',serial:'PRIVATE_SERIAL_B'); $result=$f['service']->replace(1,$first->component->componentId,$replacement); [$closed,$active]=$f['gateway']->records;
    $assert($result->replaced && $active->id!==$old->id && $closed->removedAt==$active->installedAt && $closed->updatedAt==$closed->removedAt && $active->createdAt==$active->installedAt && $active->updatedAt==$active->installedAt && $active->removedAt===null);
    foreach (['id','systemInternalId','typeCode','slotKey','slotLabel','name','serialNumber','installedAt','createdAt'] as $field) $assert($closed->$field===$old->$field);
    $assert($replacement->matches($active) && count($f['reads']->history(1))===2 && count($f['reads']->current(1))===1 && $f['gateway']->events[3]==='close' && $f['gateway']->events[4]==='insert');
});
$test('Components replacement retry requires immediate predecessor and does not read clock or add history',static function() use ($assert,$componentFixture,$componentSpec,$componentError):void {
    $f=$componentFixture(); $a=$f['service']->install(1,$componentSpec()); $bSpec=$componentSpec(name:'Disk B',serial:'PRIVATE_B'); $b=$f['service']->replace(1,$a->component->componentId,$bSpec); $before=$f['gateway']->records;
    $f['clock']->at=$f['clock']->at->modify('+1 day'); $again=$f['service']->replace(1,$a->component->componentId,$bSpec);
    $assert(!$again->replaced && $again->component->componentId===$b->component->componentId && $again->component->installedAt===$b->component->installedAt && $before===$f['gateway']->records && $f['clock']->calls===2);
    $componentError(static fn()=>$f['service']->replace(1,999,$bSpec),'system_component_replace_conflict');
    $componentError(static fn()=>$f['service']->replace(1,$a->component->componentId,$componentSpec(name:'Other',serial:'OTHER')),'system_component_replace_conflict');
    $cSpec=$componentSpec(name:'Disk C',serial:'PRIVATE_C'); $f['service']->replace(1,$b->component->componentId,$cSpec); $before=$f['gateway']->records;
    $componentError(static fn()=>$f['service']->replace(1,$a->component->componentId,$cSpec),'system_component_replace_conflict');
    $componentError(static fn()=>$f['service']->replace(1,$a->component->componentId,$bSpec),'system_component_replace_conflict'); $assert($before===$f['gateway']->records && count($before)===3);
});
foreach (['type','slot','label'] as $field) {
    $test('Components replacement cannot change slot '.$field,static function() use ($assert,$componentFixture,$componentSpec,$componentError,$field):void {
        $f=$componentFixture(); $a=$f['service']->install(1,$componentSpec()); $before=$f['gateway']->records;
        $spec=new ComponentSpec($field==='type' ? 'GPU' : 'SSD',$field==='slot' ? 'SSD_2' : 'SSD_1',$field==='label' ? 'Another label' : 'SSD 1','Replacement',null);
        $componentError(static fn()=>$f['service']->replace(1,$a->component->componentId,$spec),'system_component_replace_conflict'); $assert($before===$f['gateway']->records);
    });
}
$test('Components same model without serial is a physical replacement but same known serial is rejected',static function() use ($assert,$componentFixture,$componentSpec,$componentError):void {
    $f=$componentFixture(); $a=$f['service']->install(1,$componentSpec(serial:null)); $b=$f['service']->replace(1,$a->component->componentId,$componentSpec(serial:null));
    $assert($b->replaced && count($f['gateway']->records)===2 && $b->component->componentId!==$a->component->componentId);
    $f=$componentFixture(); $a=$f['service']->install(1,$componentSpec()); $componentError(static fn()=>$f['service']->replace(1,$a->component->componentId,$componentSpec()),'system_component_invalid');
    $assert(count($f['gateway']->records)===1 && $f['gateway']->closes===0);
});
$test('Components active serial conflicts are private exact-case and allow historical reuse',static function() use ($assert,$componentFixture,$componentSpec,$componentError):void {
    $f=$componentFixture(); $a=$f['service']->install(1,$componentSpec());
    $componentError(static fn()=>$f['service']->install(1,$componentSpec('SSD_2')),'system_component_serial_conflict');
    $f['service']->install(1,$componentSpec('SSD_2',serial:'private_serial_a'));
    $componentError(static fn()=>$f['service']->replace(1,$a->component->componentId,$componentSpec(serial:'private_serial_a')),'system_component_serial_conflict');
    $f['service']->replace(1,$a->component->componentId,$componentSpec(serial:'PRIVATE_NEW'));
    $f['service']->install(1,$componentSpec('SSD_3')); $assert(count($f['reads']->current(1))===3);
});
foreach (['close','insert','commit'] as $failure) {
    $test('Components replacement '.$failure.' failure rolls back close and insert with sanitized errors',static function() use ($assert,$componentFixture,$componentSpec,$componentError,$failure):void {
        $f=$componentFixture(); $a=$f['service']->install(1,$componentSpec()); $before=$f['gateway']->records; $f['gateway']->failure=$failure;
        $componentError(static fn()=>$f['service']->replace(1,$a->component->componentId,$componentSpec(name:'Disk B',serial:'PRIVATE_B')),'system_component_persist_failed');
        $assert($before===$f['gateway']->records && $f['gateway']->records[0]->removedAt===null && end($f['gateway']->events)==='rollback');
        if ($failure==='close') $assert($f['gateway']->inserts===1);
    });
}
$test('Components replacement clock cannot predate current installation and cancelled Systems stay readable',static function() use ($assert,$componentFixture,$componentSpec,$componentError):void {
    $f=$componentFixture(); $a=$f['service']->install(1,$componentSpec()); $f['clock']->at=$f['clock']->at->modify('-1 second');
    $componentError(static fn()=>$f['service']->replace(1,$a->component->componentId,$componentSpec(serial:'B')),'system_component_state_invalid');
    $f['gateway']->parent=new ComponentSystemState(1,'CANCELLED',$f['origin']);
    $componentError(static fn()=>$f['service']->replace(1,$a->component->componentId,$componentSpec(serial:'B')),'system_component_system_inactive');
    $assert(count($f['reads']->current(1))===1 && count($f['reads']->history(1))===1);
});
$test('Components current and history read order stays by slot after replacing an earlier slot',static function() use ($assert,$componentFixture,$componentSpec):void {
    $f=$componentFixture(); $ssd=$f['service']->install(1,$componentSpec()); $f['service']->install(1,$componentSpec('SSD_2',serial:'SECOND')); $f['service']->install(1,$componentSpec('GPU_1',serial:null)); $f['service']->install(1,$componentSpec('RAM_1',serial:null));
    $f['clock']->at=$f['clock']->at->modify('+1 hour'); $f['service']->replace(1,$ssd->component->componentId,$componentSpec(serial:'NEW'));
    $current=$f['reads']->current(1); $history=$f['reads']->history(1);
    $assert(array_column($current,'slotKey')===['GPU_1','RAM_1','SSD_1','SSD_2'] && array_column($history,'slotKey')===['GPU_1','RAM_1','SSD_1','SSD_1','SSD_2']);
    $json=json_encode(array_map(static fn($view):array=>$view->toArray(),$history),JSON_THROW_ON_ERROR);
    foreach (['serialNumber','SERIAL_NUMBER','PRIVATE_SERIAL_A','SECOND','NEW'] as $private) $assert(!str_contains($json,$private));
    $assert(array_keys($current[0]->toArray())===['componentId','typeCode','slotKey','slotLabel','name','installedAt','removedAt','active']);
});
$test('Components serialized identical and competing installers preserve one active row in either order',static function() use ($assert,$componentFixture,$componentSpec,$componentError):void {
    foreach ([false,true] as $reverse) {
        $f=$componentFixture(); $a=$componentSpec(name:$reverse ? 'B' : 'A'); $b=$componentSpec(name:$reverse ? 'A' : 'B');
        $one=$f['service']->install(1,$a); $two=$f['service']->install(1,$a); $assert($one->created && !$two->created);
        $componentError(static fn()=>$f['service']->install(1,$b),'system_component_slot_occupied'); $assert(count($f['gateway']->records)===1);
    }
});
$test('Components serialized replacement contenders produce one replacement and exact retries converge',static function() use ($assert,$componentFixture,$componentSpec,$componentError):void {
    foreach ([false,true] as $reverse) {
        $f=$componentFixture(); $a=$f['service']->install(1,$componentSpec()); $winner=$componentSpec(name:$reverse ? 'C' : 'B',serial:$reverse ? 'C' : 'B'); $loser=$componentSpec(name:$reverse ? 'B' : 'C',serial:$reverse ? 'B' : 'C');
        $assert($f['service']->replace(1,$a->component->componentId,$winner)->replaced && !$f['service']->replace(1,$a->component->componentId,$winner)->replaced);
        $componentError(static fn()=>$f['service']->replace(1,$a->component->componentId,$loser),'system_component_replace_conflict'); $assert(count($f['gateway']->records)===2);
    }
});
$test('Components infrastructure read failures and private contexts do not escape reads or mutation',static function() use ($assert,$componentFixture,$componentSpec,$componentError):void {
    foreach (['parent','read'] as $failure) {
        $f=$componentFixture(); $f['gateway']->failure=$failure;
        $componentError(static fn()=>$f['reads']->history(1),'system_component_read_failed');
        $componentError(static fn()=>$f['service']->install(1,$componentSpec()),'system_component_read_failed');
        $assert($f['gateway']->records===[]);
    }
});

$componentLegacyRows=static function(string $defect='valid') use ($componentFixture,$componentSpec,$componentRaw):array {
    $f=$componentFixture(); $a=$f['service']->install(1,$componentSpec()); $f['clock']->at=$f['clock']->at->modify('+1 hour'); $f['service']->replace(1,$a->component->componentId,$componentSpec(name:'Disk B',serial:'PRIVATE_B'));
    $rows=array_map(static fn($row):array=>$componentRaw($row,$f['gateway']->parent),$f['gateway']->records);
    if ($defect==='orphan') foreach ($rows as &$row) $row['PARENT_ID']=null;
    if ($defect==='duplicate-active' || $defect==='active-not-latest' || $defect==='serial-conflict') { $rows[0]['REMOVED_AT']=null; $rows[0]['UPDATED_AT']=$rows[0]['INSTALLED_AT']; }
    if ($defect==='overlap') { $rows[0]['REMOVED_AT']='2026-10-16 14:00:00'; $rows[0]['UPDATED_AT']=$rows[0]['REMOVED_AT']; }
    if ($defect==='removed-before-install') { $rows[0]['REMOVED_AT']='2026-10-15 00:00:00'; $rows[0]['UPDATED_AT']=$rows[0]['REMOVED_AT']; }
    if ($defect==='type') $rows[1]['TYPE_CODE']='GPU';
    if ($defect==='label') $rows[1]['SLOT_LABEL']='Another label';
    if ($defect==='active-not-latest') { $rows[1]['REMOVED_AT']='2026-10-16 15:00:00'; $rows[1]['UPDATED_AT']=$rows[1]['REMOVED_AT']; }
    if ($defect==='serial-conflict') { $rows[1]['SLOT_KEY']='SSD_2'; $rows[1]['SLOT_LABEL']='SSD 2'; $rows[1]['SERIAL_NUMBER']=$rows[0]['SERIAL_NUMBER']; }
    if ($defect==='shape') $rows[1]['SERIAL_NUMBER']=' PRIVATE_B ';
    if ($defect==='invalid-date') $rows[1]['INSTALLED_AT']='2026-02-30 00:00:00';
    if ($defect==='bad-id') $rows[1]['ID']='2147483648';
    if ($defect==='before-parent') foreach ($rows as &$row) $row['PARENT_CREATED_AT']='2026-10-17 00:00:00';
    return $rows;
};
foreach (['orphan','duplicate-active','overlap','removed-before-install','type','label','active-not-latest','serial-conflict','shape','invalid-date','bad-id','before-parent'] as $defect) {
    $test('Components legacy '.$defect.' fails health and migration without diagnostics or repair',static function() use ($assert,$componentError,$componentLegacyRows,$fakeStore,$defect):void {
        $rows=$componentLegacyRows($defect); $before=$rows; $health=new SystemComponentDataHealth(static fn():array=>$rows); $result=$health->run(); $store=$fakeStore(['2026_10_14_009_system_ownership_core']);
        $category=match ($defect) {'orphan'=>'orphan_parent','duplicate-active'=>'duplicate_active_slot','overlap'=>'temporal_overlap','type','label'=>'slot_identity','active-not-latest'=>'active_not_latest','serial-conflict'=>'active_serial_conflict',default=>'malformed'};
        $assert($result===['ok'=>false,'errors'=>[['code'=>'system_component_state_invalid','category'=>$category,'count'=>1]]]);
        $encoded=json_encode($result,JSON_THROW_ON_ERROR); foreach (['PRIVATE','SERIAL_NUMBER','SSD_1','Disk B','SQL','/private/'] as $private) $assert(!str_contains($encoded,$private));
        $componentError(static fn()=>(new ComponentLifecycleMigrationService($store,$health))->migrate(),'system_component_legacy_state_invalid');
        $assert(!$store->has(SystemComponentLifecycleCore::ID) && $before===$rows && $store->has('2026_10_14_009_system_ownership_core'));
    });
}
$test('Components migration 010 adopts empty and valid legacy history and skips already-applied preflight',static function() use ($assert,$componentLegacyRows,$fakeStore):void {
    $legacyTimes=$componentLegacyRows(); $legacyTimes[0]['CREATED_AT']='2026-10-15 14:00:00'; $legacyTimes[0]['UPDATED_AT']='2026-10-17 00:00:00';
    foreach ([[], $componentLegacyRows(),$legacyTimes] as $rows) {
        foreach ([[],['2026_10_14_009_system_ownership_core']] as $existing) {
            $scan=new class($rows) { public int $calls=0; public bool $fail=false; public function __construct(public array $rows) {} public function read():array { ++$this->calls; if ($this->fail) throw new RuntimeException('Must not rerun'); return $this->rows; } };
            $store=$fakeStore($existing); $health=new SystemComponentDataHealth(static fn():array=>$scan->read()); $migration=new ComponentLifecycleMigrationService($store,$health);
            $assert($migration->migrate()===['2026_10_16_010_system_component_lifecycle_core'] && $scan->rows===$rows && $scan->calls===1);
            $scan->fail=true; $assert($migration->migrate()===[] && $scan->calls===1);
        }
    }
});
$test('Components health read errors remain operational and cannot certify or mark the migration',static function() use ($assert,$componentError,$fakeStore):void {
    $health=new SystemComponentDataHealth(static fn()=>throw new RuntimeException('SQL PRIVATE_SERIAL /private/path')); $store=$fakeStore();
    $assert($health->run()===['ok'=>false,'errors'=>[['code'=>'system_component_read_failed','category'=>'read','count'=>1]]]);
    $componentError(static fn()=>(new ComponentLifecycleMigrationService($store,$health))->migrate(),'system_component_read_failed'); $assert(!$store->has(SystemComponentLifecycleCore::ID));
});
$test('Components corrupt slot history blocks reads install and replacement instead of repairing it',static function() use ($assert,$componentFixture,$componentSpec,$componentLegacyRows,$componentError):void {
    foreach (['duplicate-active','overlap','type','label','active-not-latest','serial-conflict'] as $defect) {
        $f=$componentFixture(); $f['gateway']->records=array_map(static fn(array $row)=>ComponentRowMapper::component($row),$componentLegacyRows($defect)); $before=$f['gateway']->records;
        $componentError(static fn()=>$f['reads']->current(1),'system_component_state_invalid');
        $componentError(static fn()=>$f['service']->install(1,$componentSpec()),'system_component_state_invalid');
        $componentError(static fn()=>$f['service']->replace(1,2,$componentSpec(serial:'OTHER')),'system_component_state_invalid');
        $assert($before===$f['gateway']->records && $f['gateway']->inserts===0 && $f['gateway']->closes===0 && $f['clock']->calls===0);
    }
});
$test('Components adopt removed-only slot history but preserve slot meaning and temporal boundary on install',static function() use ($assert,$componentFixture,$componentLegacyRows,$componentSpec,$componentError):void {
    $f=$componentFixture(); $raw=$componentLegacyRows()[0]; $f['gateway']->records=[ComponentRowMapper::component($raw)]; $f['gateway']->nextId=2;
    $f['clock']->at=new DateTimeImmutable('2026-10-16 12:30:00');
    $componentError(static fn()=>$f['service']->install(1,$componentSpec(serial:'OTHER')),'system_component_state_invalid');
    $f['clock']->at=new DateTimeImmutable('2026-10-16 14:00:00');
    $componentError(static fn()=>$f['service']->install(1,new ComponentSpec('SSD','SSD_1','Different label','New',null)),'system_component_state_invalid');
    $result=$f['service']->install(1,$componentSpec(serial:'OTHER')); $assert($result->created && count($f['gateway']->records)===2);
});
$test('Components replacement retry rejects a non-linking immediate predecessor',static function() use ($assert,$componentFixture,$componentLegacyRows,$componentSpec,$componentError):void {
    $f=$componentFixture(); $rows=$componentLegacyRows(); $rows[0]['REMOVED_AT']='2026-10-16 12:30:00'; $rows[0]['UPDATED_AT']=$rows[0]['REMOVED_AT'];
    $f['gateway']->records=array_map(static fn(array $row)=>ComponentRowMapper::component($row),$rows); $before=$f['gateway']->records;
    $componentError(static fn()=>$f['service']->replace(1,1,$componentSpec(name:'Disk B',serial:'PRIVATE_B')),'system_component_replace_conflict'); $assert($before===$f['gateway']->records);
});
$test('Components ordinary ORM cannot add update or delete and read repository has no create API',static function() use ($assert,$componentError):void {
    require_once __DIR__.'/fixtures/system_table_parent.php'; \Bitrix\Main\Entity\DataManager::$updates=[]; \Bitrix\Main\Entity\DataManager::$writes=[];
    $componentError(static fn()=>\KK\Korsac\System\SystemComponentTable::add(['NAME'=>'arbitrary']),'system_component_immutable');
    $componentError(static fn()=>\KK\Korsac\System\SystemComponentTable::addMulti([['NAME'=>'arbitrary']]),'system_component_immutable');
    foreach (['SYSTEM_INTERNAL_ID','TYPE_CODE','SLOT_KEY','SLOT_LABEL','NAME','SERIAL_NUMBER','INSTALLED_AT','REMOVED_AT','CREATED_AT','UPDATED_AT'] as $field) {
        $componentError(static fn()=>\KK\Korsac\System\SystemComponentTable::update(1,[$field=>null]),'system_component_immutable');
        $componentError(static fn()=>\KK\Korsac\System\SystemComponentTable::updateMulti([1,2],[$field=>null]),'system_component_immutable');
    }
    $componentError(static fn()=>\KK\Korsac\System\SystemComponentTable::delete(1),'system_component_immutable');
    $assert(\Bitrix\Main\Entity\DataManager::$updates===[] && \Bitrix\Main\Entity\DataManager::$writes===[] && !method_exists(\KK\Korsac\System\SystemComponentRepositoryInterface::class,'create') && !method_exists(\KK\Korsac\System\BitrixSystemComponentRepository::class,'create'));
});
$test('Components Objectify collection batch dispatch cannot bypass authority with either ignoreEvents mode',static function() use ($assert,$componentError):void {
    require_once __DIR__.'/fixtures/system_table_parent.php'; \Bitrix\Main\Entity\DataManager::$updates=[]; \Bitrix\Main\Entity\DataManager::$writes=[];
    $dataClass=\KK\Korsac\System\SystemComponentTable::class;
    // Exact call/payload shapes from Collection::save(): RAW __object rows and equal CHANGED data.
    // Inert objects suffice: a guard must reject before ORM interprets them. This is not a fake Collection.
    $addObjects=[['__object'=>new stdClass()],['__object'=>new stdClass()]];
    $primaries=[['ID'=>1],['ID'=>2]]; $dataSample=['NAME'=>'arbitrary','REMOVED_AT'=>null];
    foreach ([false,true] as $ignoreEvents) {
        $componentError(static fn()=>$dataClass::addMulti($addObjects,$ignoreEvents),'system_component_immutable');
        $componentError(static fn()=>$dataClass::updateMulti($primaries,$dataSample,$ignoreEvents),'system_component_immutable');
        $componentError(static fn()=>$dataClass::addMulti([],$ignoreEvents),'system_component_immutable');
        $componentError(static fn()=>$dataClass::updateMulti([],[],$ignoreEvents),'system_component_immutable');
    }
    $assert(\Bitrix\Main\Entity\DataManager::$writes===[] && \Bitrix\Main\Entity\DataManager::$updates===[]);
});

// A small SQL/connection seam exercises the real gateway, not a Bitrix ORM emulator.
$componentConnection=static function(array $rows=[]):object {
    return new class($rows) {
        public array $queries=[]; public int $commits=0,$rollbacks=0,$affected=1,$insertId=100;
        public ?string $failure=null; private array $backup=[];
        public function __construct(public array $rows) {}
        public function startTransaction():void { $this->backup=$this->rows; }
        public function commitTransaction():void { if ($this->failure==='commit') throw new RuntimeException('PRIVATE_SERIAL SQL /private/path'); ++$this->commits; }
        public function rollbackTransaction():void { ++$this->rollbacks; $this->rows=$this->backup; }
        public function query(string $sql):object {
            $this->queries[]=$sql;
            if ($this->failure==='read') throw new RuntimeException('PRIVATE_SERIAL SQL /private/path');
            $rows=str_contains($sql,'system_component') ? $this->rows : [['ID'=>1,'LIFECYCLE_STATE'=>'ACTIVE','CREATED_AT'=>'2026-10-15 12:00:00']];
            return new class($rows) { public function __construct(private array $rows) {} public function fetch():array|false { return array_shift($this->rows) ?? false; } };
        }
        public function queryExecute(string $sql):void {
            $this->queries[]=$sql; $operation=str_starts_with($sql,'UPDATE') ? 'close' : 'insert';
            if ($this->failure===$operation) throw new RuntimeException('PRIVATE_SERIAL SQL /private/path');
            if ($operation==='close' && $this->affected===1 && preg_match("/SET `REMOVED_AT`='([^']+)'/",$sql,$m)) { $this->rows[0]['REMOVED_AT']=$m[1]; $this->rows[0]['UPDATED_AT']=$m[1]; }
        }
        public function getAffectedRowsCount():int { return $this->affected; }
        public function getInsertedId():int { return $this->insertId; }
        public function getSqlHelper():object { return new class { public function forSql(string $text):string { return addslashes($text); } }; }
    };
};
foreach (['outside','before-parent','different-system','different-slot','read-only-scope','after-completion'] as $misuse) {
    $test('Components real gateway rejects '.$misuse.' close and insert',static function() use ($assert,$componentConnection,$componentSpec,$componentError,$misuse):void {
        foreach (['close','insert'] as $operation) {
            $connection=$componentConnection(); $gateway=new BitrixComponentGateway(static fn():object=>$connection); $at=new DateTimeImmutable('2026-10-16 12:00:00');
            $write=static function() use ($gateway,$componentSpec,$at,$misuse,$operation):void {
                $id=$misuse==='different-system' ? 2 : 1; $slot=$misuse==='different-slot' ? 'SSD_2' : 'SSD_1';
                if ($operation==='close') $gateway->closeComponent($id,1,$slot,$at); else $gateway->insertComponent($id,$componentSpec($slot),$at);
            };
            if ($misuse==='outside' || $misuse==='after-completion') {
                if ($misuse==='after-completion') $gateway->transaction(static function() use ($gateway):void { $gateway->lockSystem(1); $gateway->lockComponents(1,'SSD_1'); });
                $componentError($write,'system_component_persist_failed');
            } else $componentError(static fn()=>$gateway->transaction(static function() use ($gateway,$misuse,$write):void {
                if ($misuse!=='before-parent') { $gateway->lockSystem(1); $gateway->lockComponents(1,$misuse==='read-only-scope' ? null : 'SSD_1'); } $write();
            }),'system_component_state_invalid');
            $assert(count(array_filter($connection->queries,static fn(string $sql):bool=>str_starts_with($sql,'INSERT') || str_starts_with($sql,'UPDATE')))===0);
        }
    });
}
$test('Components real gateway fixed insert uses parent then component locks and SQL-safe values',static function() use ($assert,$componentConnection):void {
    $connection=$componentConnection(); $service=new SystemComponentService(new BitrixComponentGateway(static fn():object=>$connection),static fn()=>new DateTimeImmutable('2026-10-16 12:00:00.456789'));
    $result=$service->install(1,new ComponentSpec('SSD','SSD_1','SSD 1',"O'Brien disk",'PRIVATE_SERIAL'));
    $assert($result->created && $result->component->componentId===100 && $connection->commits===1 && $connection->rollbacks===0 && count($connection->queries)===3);
    $assert(str_contains($connection->queries[0],'FOR UPDATE') && str_contains($connection->queries[1],'FOR UPDATE') && str_starts_with($connection->queries[2],'INSERT INTO `b_kk_korsac_system_component`') && str_contains($connection->queries[2],"O\\'Brien"));
});
foreach (['close','insert','commit','affected'] as $failure) {
    $test('Components real SQL gateway '.$failure.' failure preserves old active row',static function() use ($assert,$componentConnection,$componentLegacyRows,$componentSpec,$componentError,$failure):void {
        $row=$componentLegacyRows()[0]; $row['REMOVED_AT']=null; $row['UPDATED_AT']=$row['INSTALLED_AT']; $connection=$componentConnection([$row]); $before=$connection->rows;
        if ($failure==='affected') $connection->affected=0; else $connection->failure=$failure;
        $service=new SystemComponentService(new BitrixComponentGateway(static fn():object=>$connection),static fn()=>new DateTimeImmutable('2026-10-16 14:00:00'));
        $componentError(static fn()=>$service->replace(1,1,$componentSpec(name:'Disk B',serial:'PRIVATE_B')),'system_component_persist_failed');
        $assert($connection->rows===$before && $connection->commits===0 && $connection->rollbacks===1);
    });
}
$test('Components real gateway refuses close-only commit and does not reopen a closed historical row',static function() use ($assert,$componentConnection,$componentLegacyRows,$componentError):void {
    $row=$componentLegacyRows()[0]; $row['REMOVED_AT']=null; $row['UPDATED_AT']=$row['INSTALLED_AT']; $connection=$componentConnection([$row]); $gateway=new BitrixComponentGateway(static fn():object=>$connection);
    $componentError(static fn()=>$gateway->transaction(static function() use ($gateway):void { $gateway->lockSystem(1); $gateway->lockComponents(1,'SSD_1'); $gateway->closeComponent(1,1,'SSD_1',new DateTimeImmutable('2026-10-16 14:00:00')); }),'system_component_persist_failed');
    $assert($connection->rows===[$row] && $connection->commits===0 && $connection->rollbacks===1);
    $connection=$componentConnection([$componentLegacyRows()[0]]); $gateway=new BitrixComponentGateway(static fn():object=>$connection);
    $componentError(static fn()=>$gateway->transaction(static function() use ($gateway):void { $gateway->lockSystem(1); $gateway->lockComponents(1,'SSD_1'); $gateway->closeComponent(1,1,'SSD_1',new DateTimeImmutable('2026-10-16 14:00:00')); }),'system_component_state_invalid');
});
$test('Components real gateway read exceptions are sanitized operational failures',static function() use ($componentConnection,$componentSpec,$componentError):void {
    $connection=$componentConnection(); $connection->failure='read'; $service=new SystemComponentService(new BitrixComponentGateway(static fn():object=>$connection));
    $componentError(static fn()=>$service->install(1,$componentSpec()),'system_component_read_failed');
});
$test('Components real SQL replacement updates only closing timestamps under the full expected-row condition',static function() use ($assert,$componentConnection,$componentLegacyRows,$componentSpec):void {
    $row=$componentLegacyRows()[0]; $row['REMOVED_AT']=null; $row['UPDATED_AT']=$row['INSTALLED_AT']; $connection=$componentConnection([$row]);
    $service=new SystemComponentService(new BitrixComponentGateway(static fn():object=>$connection),static fn()=>new DateTimeImmutable('2026-10-16 14:00:00'));
    $result=$service->replace(1,1,$componentSpec(name:'Disk B',serial:'PRIVATE_B'));
    $assert($result->replaced && $result->component->componentId===100 && $connection->commits===1 && $connection->rollbacks===0 && count($connection->queries)===4);
    $assert($connection->queries[2]==="UPDATE `b_kk_korsac_system_component` SET `REMOVED_AT`='2026-10-16 14:00:00',`UPDATED_AT`='2026-10-16 14:00:00' WHERE `ID`=1 AND `SYSTEM_INTERNAL_ID`=1 AND `SLOT_KEY`='SSD_1' AND `REMOVED_AT` IS NULL");
    foreach (['NAME','SERIAL_NUMBER','TYPE_CODE','SLOT_KEY','SLOT_LABEL','INSTALLED_AT','CREATED_AT'] as $field) $assert($connection->rows[0][$field]===$row[$field]);
});
$test('Components persisted timestamps must have SQL second precision',static function() use ($componentFixture,$componentError):void {
    $f=$componentFixture(); $at=$f['origin'];
    foreach ([0,1,2,3] as $field) {
        $times=[$at,null,$at,$at]; $times[$field]=new DateTimeImmutable('2026-10-16 12:00:00.123456');
        $componentError(static fn()=>new \KK\Korsac\System\SystemComponent(1,1,'SSD','SSD_1','SSD 1','Disk',null,...$times),'system_component_invalid');
    }
});
$test('Components current actual Passport projection replaces A with B and leaks no private timeline data in DTO or HTML',static function() use ($assert,$componentFixture,$componentSpec,$publicPassportFixture,$renderPassport):void {
    $f=$componentFixture(); $p=$publicPassportFixture(); $f['gateway']->nextId=8930111;
    $repository=new class($f['gateway']) implements \KK\Korsac\System\SystemComponentRepositoryInterface {
        public function __construct(private object $gateway) {}
        public function findForSystem(int $id,bool $activeOnly=false):array { return ComponentTimeline::ordered(array_values(array_filter($this->gateway->records,static fn($row):bool=>$row->systemInternalId===$id && (!$activeOnly || $row->removedAt===null)))); }
    };
    $public=new \KK\Korsac\System\PublicPassportService($p['repo'],$p['systems'],$repository); $passport=$p['repo']->records[1];
    $a=$f['service']->install(1,$componentSpec()); $assert($public->find($passport->publicId)->toArray()['components']===[['typeCode'=>'SSD','slotLabel'=>'SSD 1','name'=>'Public disk A']]);
    $b=$f['service']->replace(1,$a->component->componentId,$componentSpec(name:'Public disk B',serial:'PRIVATE_SERIAL_B'));
    $dto=$public->find($passport->publicId); $array=$dto->toArray(); $html=$renderPassport((new \KK\Korsac\Passport\PublicPassportHttpPresenter(static fn(string $id)=>$public->find($id)))->present($passport->publicId));
    $assert($array['components']===[['typeCode'=>'SSD','slotLabel'=>'SSD 1','name'=>'Public disk B']] && str_contains($html,'Public disk B') && count($f['gateway']->records)===2 && $passport===$p['repo']->records[1]);
    $assert(array_keys($array)===['publicId','systemId','systemType','modelName','transferredAt','warrantyStartedAt','components']);
    foreach ([(string)$a->component->componentId,(string)$b->component->componentId,'Public disk A','PRIVATE_SERIAL_A','PRIVATE_SERIAL_B','SSD_1','componentId','serialNumber','installedAt','removedAt'] as $private) { $assert(!str_contains(json_encode($array,JSON_THROW_ON_ERROR),$private)); $assert(!str_contains($html,$private)); }
});
$test('Components gateway rolls back a swallowed insert failure rather than committing partial writes',static function() use ($assert,$componentConnection,$componentSpec,$componentError):void {
    $connection=$componentConnection(); $connection->failure='insert'; $gateway=new BitrixComponentGateway(static fn():object=>$connection);
    $componentError(static fn()=>$gateway->transaction(static function() use ($gateway,$componentSpec):void {
        $gateway->lockSystem(1); $gateway->lockComponents(1,'SSD_1');
        try { $gateway->insertComponent(1,$componentSpec(),new DateTimeImmutable('2026-10-16 12:00:00')); } catch (\KK\Korsac\System\SystemException) {}
    }),'system_component_persist_failed'); $assert($connection->commits===0 && $connection->rollbacks===1);
});
$test('Components CLI commands project safe current and history data without Sale or serial parameters',static function() use ($assert,$removeHttpFixture):void {
    $root=sys_get_temp_dir().'/korsac-component-cli-'.bin2hex(random_bytes(6)); mkdir($root.'/bitrix/modules/main/include',0700,true);
    try {
        $bootstrap=<<<'PHP'
<?php
namespace Bitrix\Main { final class Loader { public static function includeModule(string $name):bool { return $name==='kk.korsac'; } } }
namespace KK\Korsac\System {
    final class BitrixSystemRepository { public function findBySystemId(string $id):object { return (object)['id'=>1]; } }
    final class SystemServiceFactory {
        public static function configuration():object { return new \stdClass(); }
        public static function componentReads():object {
            return new class {
                public function current(int $id):array { $at=new \DateTimeImmutable('2026-10-16 12:00:00'); return [\KK\Korsac\Component\ComponentCurrentView::of(new SystemComponent(1930111,$id,'SSD','PRIVATE_SLOT','SSD 1','Public disk','PRIVATE_SERIAL_CLI',$at,null,$at,$at))]; }
                public function history(int $id):array { $at=new \DateTimeImmutable('2026-10-15 12:00:00'); $end=$at->modify('+1 day'); return [\KK\Korsac\Component\ComponentCurrentView::of(new SystemComponent(1930110,$id,'SSD','PRIVATE_SLOT','SSD 1','Removed disk','PRIVATE_SERIAL_CLI_OLD',$at,$end,$at,$end)),...$this->current($id)]; }
            };
        }
    }
}
PHP;
        file_put_contents($root.'/bitrix/modules/main/include/prolog_before.php',$bootstrap);
        $runner='<?php require '.var_export(dirname(__DIR__).'/bootstrap.php',true).'; $_SERVER[\'DOCUMENT_ROOT\']='.var_export($root,true).'; require '.var_export(dirname(__DIR__,2).'/tools/system.php',true).';'; file_put_contents($root.'/run.php',$runner);
        foreach (['component-current'=>1,'component-history'=>2] as $command=>$count) {
            $arguments=[PHP_BINARY]; if (php_ini_loaded_file()) array_push($arguments,'-c',php_ini_loaded_file()); array_push($arguments,$root.'/run.php',$command,'--system=KS-2610-0010');
            $pipes=[]; $process=proc_open($arguments,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes); $assert(is_resource($process)); fclose($pipes[0]);
            $json=stream_get_contents($pipes[1]); $stderr=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); $status=proc_close($process);
            $assert($status===0 && $stderr==='', 'CLI subprocess failed: '.$stderr.$json);
            $assert(!str_contains($json,'PRIVATE_SERIAL') && !str_contains($json,'serialNumber')); $result=json_decode($json,true,512,JSON_THROW_ON_ERROR);
            $assert($result['ok'] && $result['result']['systemId']==='KS-2610-0010' && count($result['result']['components'])===$count);
            foreach ($result['result']['components'] as $view) $assert(array_keys($view)===['componentId','typeCode','slotKey','slotLabel','name','installedAt','removedAt','active']);
        }
    } finally { $removeHttpFixture($root); }
});
