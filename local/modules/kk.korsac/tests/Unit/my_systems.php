<?php

declare(strict_types=1);

use KK\Korsac\MySystems\BitrixMySystemsGateway;
use KK\Korsac\MySystems\MySystemComponentView;
use KK\Korsac\MySystems\MySystemReadRecord;
use KK\Korsac\MySystems\MySystemsCliInput;
use KK\Korsac\MySystems\MySystemsGatewayInterface;
use KK\Korsac\MySystems\MySystemsPageRequest;
use KK\Korsac\MySystems\MySystemsService;
use KK\Korsac\Ownership\OwnershipHistoryRecord;
use KK\Korsac\System\PassportRecord;
use KK\Korsac\System\SystemComponent;
use KK\Korsac\System\SystemException;

$myError=static function(callable $operation,string $code) use ($assert):void {
    try { $operation(); }
    catch (SystemException $error) { $assert($error->machineCode===$code && $error->getMessage()===$code && $error->diagnostic()===['code'=>$code] && $error->getPrevious()===null); return; }
    throw new RuntimeException('Expected '.$code);
};
$myRecord=static function(array $options=[]):MySystemReadRecord {
    $id=$options['id'] ?? 1; $user=array_key_exists('user',$options) ? $options['user'] : 7;
    $created=$options['created'] ?? new DateTimeImmutable('2026-10-15 09:00:00',new DateTimeZone('Europe/Moscow')); $updated=$options['updated'] ?? $created->modify('+1 hour');
    $history=array_key_exists('history',$options) ? $options['history'] : ($user===null ? null : new OwnershipHistoryRecord(70000+$id,$id,1,null,$user,'CLAIMED','SECRET_CLAIM',$user,$updated));
    return new MySystemReadRecord($id,$user,$options['systemId'] ?? sprintf('KS-2610-%04d',100+$id),$options['type'] ?? 'MODEL',$options['model'] ?? 'Immutable KORSAC model',$options['lifecycle'] ?? 'ACTIVE',$created,$updated,$options['passport'] ?? null,$options['components'] ?? [],$history);
};
$myFixture=static function(array $records=[]):array {
    $gateway=new class($records) implements MySystemsGatewayInterface {
        public int $reads=0; public array $requests=[]; public ?Throwable $failure=null; public bool $raw=false;
        // Unrelated private fixtures deliberately never participate in membership selection.
        public array $orderUsers=[],$formerOwners=[];
        public function __construct(public array $records) {}
        public function findForUser(int $userId,MySystemsPageRequest $page):array {
            ++$this->reads; $this->requests[]=[$userId,$page]; if ($this->failure!==null) throw $this->failure;
            if ($this->raw) return $this->records;
            $rows=array_values(array_filter($this->records,static fn(MySystemReadRecord $row):bool=>$row->userId===$userId));
            usort($rows,static fn(MySystemReadRecord $a,MySystemReadRecord $b):int=>($b->createdAt<=>$a->createdAt) ?: strcmp($b->systemId,$a->systemId));
            return array_slice($rows,$page->offset,$page->limit+1);
        }
    };
    return ['gateway'=>$gateway,'service'=>new MySystemsService($gateway)];
};
$myPassport=static function(int $id,string $state='ACTIVE',string $publicId=''):PassportRecord {
    $at=new DateTimeImmutable('2026-10-16 12:00:00',new DateTimeZone('Europe/Moscow')); $transfer=$state==='ACTIVE' ? $at : null;
    return new PassportRecord($id,$publicId==='' ? str_repeat('a',31).dechex($id%16) : $publicId,str_repeat('e',64),$state,$transfer,$transfer,$at->modify('-1 day'),$at);
};
$myComponent=static function(int $id,int $parent,string $slot='SSD_1',string $name='Current disk',?string $serial='PRIVATE_SERIAL',?DateTimeImmutable $installed=null,?DateTimeImmutable $removed=null):SystemComponent {
    $at=$installed ?? new DateTimeImmutable('2026-10-16 12:00:00',new DateTimeZone('Europe/Moscow'));
    return new SystemComponent($id,$parent,explode('_',$slot)[0],$slot,str_replace('_',' ',$slot),$name,$serial,$at,$removed,$at,$removed ?? $at);
};

$test('My Systems membership uses current USER_ID only despite Order and historical associations',static function() use ($assert,$myRecord,$myFixture):void {
    $a=$myRecord(['id'=>1]); $b=$myRecord(['id'=>2]); $c=$myRecord(['id'=>3,'user'=>null]); $d=$myRecord(['id'=>4,'user'=>8]);
    $f=$myFixture([$a,$b,$c,$d]); $f['gateway']->orderUsers=[1=>8,2=>9,3=>7,4=>7]; $f['gateway']->formerOwners=[4=>[7]];
    $page=$f['service']->listForUser(7); $assert(array_column($page->items,'systemId')===[$b->systemId,$a->systemId] && !$page->hasMore);
    $other=$f['service']->listForUser(8); $assert(array_column($other->items,'systemId')===[$d->systemId] && !$other->hasMore);
    $assert($f['gateway']->records===[$a,$b,$c,$d] && $f['gateway']->orderUsers[3]===7 && $f['gateway']->formerOwners[4]===[7]);
});
$test('My Systems valid user without current Systems receives an empty safe page',static function() use ($assert,$myRecord,$myFixture):void {
    $f=$myFixture([$myRecord()]); $assert($f['service']->listForUser(9)->toArray()===['items'=>[],'hasMore'=>false]);
});
foreach ([0,-1,2147483648,4294967295] as $user) {
    $test('My Systems rejects user '.$user.' before gateway access',static function() use ($assert,$myFixture,$myError,$user):void {
        $f=$myFixture(); $myError(static fn()=>$f['service']->listForUser($user),'my_systems_user_invalid'); $assert($f['gateway']->reads===0);
        $calls=0; $gateway=new BitrixMySystemsGateway(static function() use (&$calls):object { ++$calls; return new stdClass(); });
        $myError(static fn()=>$gateway->findForUser($user,new MySystemsPageRequest()),'my_systems_user_invalid'); $assert($calls===0);
    });
}
$test('My Systems accepts the signed INT32 maximum user without checking account activity or Sale',static function() use ($assert,$myRecord,$myFixture):void {
    $f=$myFixture([$myRecord(['user'=>2147483647])]); $assert(count($f['service']->listForUser(2147483647)->items)===1 && $f['gateway']->reads===1);
});
$test('My Systems no and PREPARED Passport expose no dates path or prepared public identity',static function() use ($assert,$myRecord,$myFixture,$myPassport):void {
    $prepared=$myPassport(2,'PREPARED',str_repeat('b',32)); $active=$myPassport(3);
    $f=$myFixture([$myRecord(['id'=>1]),$myRecord(['id'=>2,'passport'=>$prepared]),$myRecord(['id'=>3,'passport'=>$active])]);
    $items=$f['service']->listForUser(7)->toArray()['items'];
    $assert($items[0]['passportPath']==='/passport/'.$active->publicId.'/' && $items[0]['transferredAt']===$active->transferredAt->format(DATE_ATOM) && $items[0]['warrantyStartedAt']===$active->warrantyStartedAt->format(DATE_ATOM));
    foreach ([$items[1],$items[2]] as $item) $assert($item['passportPath']===null && $item['transferredAt']===null && $item['warrantyStartedAt']===null);
    $json=json_encode($items,JSON_THROW_ON_ERROR); $assert(!str_contains($json,$prepared->publicId) && !str_contains($json,$prepared->secretCodeHash) && !str_contains($json,'publicId') && !str_contains($json,'warrantyEnd'));
});
$test('My Systems keeps CANCELLED current associations and projects only current actual hardware in slot order',static function() use ($assert,$myRecord,$myFixture,$myComponent):void {
    $at=new DateTimeImmutable('2026-10-16 12:00:00',new DateTimeZone('Europe/Moscow')); $replace=$at->modify('+1 hour');
    $old=$myComponent(8930111,1,name:'Removed disk A',installed:$at,removed:$replace); $new=$myComponent(8930113,1,name:'Current disk B',serial:'PRIVATE_B',installed:$replace); $second=$myComponent(8930112,1,'SSD_2','Second slot','PRIVATE_SECOND');
    $record=$myRecord(['lifecycle'=>'CANCELLED','components'=>[$second,$new,$old]]); $f=$myFixture([$record]); $before=$f['gateway']->records;
    $item=$f['service']->listForUser(7)->toArray()['items'][0]; $assert($item['lifecycleState']==='CANCELLED' && $item['components']===[['typeCode'=>'SSD','slotLabel'=>'SSD 1','name'=>'Current disk B'],['typeCode'=>'SSD','slotLabel'=>'SSD 2','name'=>'Second slot']]);
    $json=json_encode($item,JSON_THROW_ON_ERROR); foreach (['Removed disk A','8930111','8930112','8930113','PRIVATE','SSD_1','SSD_2','slotKey','componentId','installedAt','removedAt'] as $private) $assert(!str_contains($json,$private)); $assert($before===$f['gateway']->records);
});
foreach ([[0,0],[-1,0],[101,0],[1,-1]] as [$limit,$offset]) {
    $test('My Systems rejects page '.$limit.'/'.$offset.' before reads',static function() use ($assert,$myFixture,$myError,$limit,$offset):void {
        $f=$myFixture(); $myError(static fn()=>$f['service']->listForUser(7,new MySystemsPageRequest($limit,$offset)),'my_systems_page_invalid'); $assert($f['gateway']->reads===0);
    });
}
$test('My Systems default max limit offset ordering and adjacent pages have no duplicate or omitted Systems',static function() use ($assert,$myRecord,$myFixture):void {
    $rows=[]; for ($i=1;$i<=130;++$i) $rows[]=$myRecord(['id'=>$i]); $f=$myFixture(array_reverse($rows));
    $first=$f['service']->listForUser(7); $assert(count($first->items)===50 && $first->hasMore && $f['gateway']->requests[0][1]->limit===50 && $f['gateway']->requests[0][1]->offset===0);
    $all=[]; foreach ([0,50,100] as $offset) { $page=$f['service']->listForUser(7,new MySystemsPageRequest(50,$offset)); array_push($all,...array_column($page->items,'systemId')); $assert($page->hasMore===($offset<100)); }
    $expected=array_reverse(array_column($rows,'systemId')); $assert($all===$expected && count(array_unique($all))===130);
    $maximum=$f['service']->listForUser(7,new MySystemsPageRequest(100)); $assert(count($maximum->items)===100 && $maximum->hasMore);
    $assert($f['service']->listForUser(7,new MySystemsPageRequest(50,130))->toArray()===['items'=>[],'hasMore'=>false]);
    $newer=$myRecord(['id'=>131,'created'=>$rows[0]->createdAt->modify('+1 day')]); $f=$myFixture([$rows[129],$newer]); $assert($f['service']->listForUser(7)->items[0]->systemId===$newer->systemId);
});
foreach (['system_read_failed','system_passport_read_failed','system_component_read_failed','unexpected'] as $source) {
    $test('My Systems '.$source.' fails the whole page without private diagnostics',static function() use ($assert,$myRecord,$myFixture,$myError,$source):void {
        $f=$myFixture([$myRecord()]); $f['gateway']->failure=$source==='unexpected' ? new RuntimeException('SQL PUBLIC_ID PRIVATE_SERIAL PRIVATE_HASH /private/path') : new SystemException($source,['field'=>'USER_ID=7 SQL PRIVATE_SERIAL /private/path']);
        $myError(static fn()=>$f['service']->listForUser(7),'my_systems_read_failed'); $assert($f['gateway']->reads===1);
    });
}
foreach (['system-id','system-type','model','lifecycle','missing-owner-history','wrong-history-owner','passport-state','passport-parent','passport-hash','passport-dates'] as $defect) {
    $test('My Systems corrupt '.$defect.' is a safe state error with no partial page',static function() use ($myRecord,$myFixture,$myPassport,$myError,$defect):void {
        $options=['id'=>1];
        if ($defect==='system-id') $options['systemId']='not-a-system';
        if ($defect==='system-type') $options['type']='UNKNOWN';
        if ($defect==='model') $options['model']="Invalid\0model";
        if ($defect==='lifecycle') $options['lifecycle']='UNKNOWN';
        if ($defect==='missing-owner-history') $options['history']=null;
        if ($defect==='wrong-history-owner') { $at=new DateTimeImmutable('2026-10-15 12:00:00'); $options['history']=new OwnershipHistoryRecord(100,1,1,null,8,'CLAIMED','SECRET_CLAIM',8,$at); }
        if (str_starts_with($defect,'passport-')) {
            $p=$myPassport(1); $options['passport']=new PassportRecord($defect==='passport-parent' ? 2 : 1,$p->publicId,$defect==='passport-hash' ? 'PRIVATE_BAD_HASH' : $p->secretCodeHash,$defect==='passport-state' ? 'BROKEN' : $p->state,$defect==='passport-dates' ? null : $p->transferredAt,$p->warrantyStartedAt,$p->preparedAt,$p->updatedAt);
        }
        $f=$myFixture([$myRecord(['id'=>2]),$myRecord($options)]); $myError(static fn()=>$f['service']->listForUser(7),'my_systems_state_invalid');
    });
}
foreach (['duplicate-active','overlap','type','label','active-not-latest','serial-conflict'] as $defect) {
    $test('My Systems rejects the established component timeline contradiction '.$defect,static function() use ($myRecord,$myFixture,$myError,$componentLegacyRows,$defect):void {
        $components=array_map(static fn(array $row)=>\KK\Korsac\Component\ComponentRowMapper::component($row),$componentLegacyRows($defect));
        $f=$myFixture([$myRecord(['components'=>$components])]); $myError(static fn()=>$f['service']->listForUser(7),'my_systems_state_invalid');
    });
}
$test('My Systems rejects cross-user unowned duplicate out-of-order or over-limit gateway records',static function() use ($myRecord,$myFixture,$myError):void {
    foreach ([[$myRecord(['user'=>8])],[$myRecord(['user'=>null])],[$myRecord(),$myRecord()],[$myRecord(['id'=>1]),$myRecord(['id'=>2])],[$myRecord(['id'=>3]),$myRecord(['id'=>2]),$myRecord(['id'=>1])]] as $rows) {
        $f=$myFixture($rows); $f['gateway']->raw=true; $myError(static fn()=>$f['service']->listForUser(7,new MySystemsPageRequest(1)),'my_systems_state_invalid');
    }
});

// SQL connection seam: no mutation/transaction methods are available to the real read gateway.
$myConnection=static function(array $rows=[]):object {
    return new class($rows) {
        public array $queries=[]; public ?Throwable $failure=null; public ?int $failFetch=null;
        public function __construct(public array $rows) {}
        public function query(string $sql):object {
            $this->queries[]=$sql; if ($this->failure!==null) throw $this->failure;
            return new class($this->rows,$this->failFetch) {
                private int $reads=0;
                public function __construct(private array $rows,private ?int $failFetch) {}
                public function fetch():array|false { if ($this->reads++===$this->failFetch) throw new RuntimeException('SQL PRIVATE_HASH /private/path'); return array_shift($this->rows) ?? false; }
            };
        }
    };
};
$mySqlRows=static function(MySystemReadRecord $r,array $extra=[]):array {
    $source=['ID'=>$r->internalId,'USER_ID'=>$r->userId,'SYSTEM_ID'=>$r->systemId,'SYSTEM_TYPE'=>$r->systemType,'MODEL_NAME'=>$r->modelName,'LIFECYCLE_STATE'=>$r->lifecycleState,'CREATED_AT'=>$r->createdAt->format('Y-m-d H:i:s'),'UPDATED_AT'=>$r->updatedAt->format('Y-m-d H:i:s')];
    $p=$r->passport; $passport=['SYSTEM_INTERNAL_ID'=>$p?->systemInternalId,'PUBLIC_ID'=>$p?->publicId,'SECRET_CODE_HASH'=>$p?->secretCodeHash,'STATE'=>$p?->state,'TRANSFERRED_AT'=>$p?->transferredAt?->format('Y-m-d H:i:s'),'WARRANTY_STARTED_AT'=>$p?->warrantyStartedAt?->format('Y-m-d H:i:s'),'PREPARED_AT'=>$p?->preparedAt->format('Y-m-d H:i:s'),'UPDATED_AT'=>$p?->updatedAt->format('Y-m-d H:i:s')];
    $h=$r->latestOwnership; $history=['ID'=>$h?->id,'SYSTEM_INTERNAL_ID'=>$h?->systemInternalId,'REVISION'=>$h?->revision,'FROM_USER_ID'=>$h?->fromUserId,'TO_USER_ID'=>$h?->toUserId,'EVENT_CODE'=>$h?->eventCode,'SOURCE_CODE'=>$h?->sourceCode,'ACTOR_USER_ID'=>$h?->actorUserId,'CREATED_AT'=>$h?->createdAt->format('Y-m-d H:i:s')];
    $rows=[];
    foreach ($r->components===[] ? [null] : $r->components as $c) {
        $component=['ID'=>$c?->id,'SYSTEM_INTERNAL_ID'=>$c?->systemInternalId,'TYPE_CODE'=>$c?->typeCode,'SLOT_KEY'=>$c?->slotKey,'SLOT_LABEL'=>$c?->slotLabel,'NAME'=>$c?->name,'SERIAL_NUMBER'=>$c?->serialNumber,'INSTALLED_AT'=>$c?->installedAt->format('Y-m-d H:i:s'),'REMOVED_AT'=>$c?->removedAt?->format('Y-m-d H:i:s'),'CREATED_AT'=>$c?->createdAt->format('Y-m-d H:i:s'),'UPDATED_AT'=>$c?->updatedAt->format('Y-m-d H:i:s')];
        $row=$extra; foreach (['S'=>$source,'P'=>$passport,'C'=>$component,'H'=>$history] as $prefix=>$fields) foreach ($fields as $field=>$value) $row[$prefix.'_'.$field]=$value; $rows[]=$row;
    }
    return $rows;
};
$test('My Systems actual SQL is one read-only bounded USER_ID window without Sale or lifecycle membership filters',static function() use ($assert,$myRecord,$myConnection,$mySqlRows):void {
    $record=$myRecord(['lifecycle'=>'CANCELLED']); $connection=$myConnection($mySqlRows($record)); $service=new MySystemsService(new BitrixMySystemsGateway(static fn():object=>$connection));
    $page=$service->listForUser(7,new MySystemsPageRequest(100,20)); $assert(count($page->items)===1 && $page->items[0]->lifecycleState==='CANCELLED' && count($connection->queries)===1);
    $sql=$connection->queries[0]; $assert(str_contains($sql,'WHERE `USER_ID`=7') && str_contains($sql,'LIMIT 101 OFFSET 20') && str_contains($sql,'ORDER BY `CREATED_AT` DESC,`SYSTEM_ID` DESC') && str_contains($sql,'ORDER BY oh_latest.`REVISION` DESC LIMIT 1'));
    $assert(!preg_match('/\b(?:INSERT|UPDATE|DELETE|REPLACE|TRANSACTION|FOR UPDATE)\b/',$sql));
    foreach (['ORDER_ID','ORDER_BASKET_ID','PRODUCT_ID','IBLOCK_ID','CONFIG_SNAPSHOT_KEY','b_sale','LIFECYCLE_STATE`='] as $forbidden) $assert(!str_contains($sql,$forbidden));
});
$test('My Systems private-rich joined data serializes only the account allowlist',static function() use ($assert,$myRecord,$myPassport,$myComponent,$myConnection,$mySqlRows):void {
    $user=7830991; $id=1930331; $p=$myPassport($id,'ACTIVE',str_repeat('c',32));
    $r=$myRecord(['id'=>$id,'user'=>$user,'systemId'=>'KS-2610-0101','passport'=>$p,'components'=>[$myComponent(8930111,$id,'SSD_1','Public disk','PRIVATE_SERIAL')]]);
    $extras=['ORDER_ID'=>2930441,'ORDER_BASKET_ID'=>3930551,'ORDER_UNIT_INDEX'=>112911,'IBLOCK_ID'=>4930661,'PRODUCT_ID'=>5930771,'CONFIG_SNAPSHOT_KEY'=>str_repeat('d',64),'SECRET_CODE'=>'PRIVATE_SECRET','OWNERSHIP_HISTORY'=>'PRIVATE_HISTORY'];
    $connection=$myConnection($mySqlRows($r,$extras)); $page=(new MySystemsService(new BitrixMySystemsGateway(static fn():object=>$connection)))->listForUser($user); $data=$page->toArray();
    $assert(array_keys($data)===['items','hasMore'] && array_keys($data['items'][0])===['systemId','systemType','modelName','lifecycleState','transferredAt','warrantyStartedAt','passportPath','components']);
    $assert(array_keys($data['items'][0]['components'][0])===['typeCode','slotLabel','name'] && $data['items'][0]['passportPath']==='/passport/'.$p->publicId.'/');
    $json=json_encode($page,JSON_THROW_ON_ERROR); foreach ([$user,$id,8930111,...array_values($extras),$p->secretCodeHash,'SSD_1','slotKey','serialNumber','publicId','userId','internalId','createdAt','updatedAt','warrantyEnd'] as $private) $assert(!str_contains($json,(string)$private));
    $assert(substr_count($json,$p->publicId)===1); foreach ([$page,$page->items[0],$page->items[0]->components[0]] as $dto) $assert((new ReflectionClass($dto))->isReadOnly());
});
foreach (['bad-component','bad-passport','bad-system-id','null-child-id','inconsistent-join','missing-field','stream-failure'] as $defect) {
    $test('My Systems SQL hydration '.$defect.' fails safely rather than returning partial data',static function() use ($myRecord,$myPassport,$myComponent,$myConnection,$mySqlRows,$myError,$defect):void {
        $r=$myRecord(['passport'=>$myPassport(1),'components'=>[$myComponent(1,1),$myComponent(2,1,'SSD_2',serial:'OTHER')]]); $rows=$mySqlRows($r);
        if ($defect==='bad-component') $rows[1]['C_SERIAL_NUMBER']="invalid\nserial";
        if ($defect==='bad-passport') $rows[0]['P_PUBLIC_ID']='invalid';
        if ($defect==='bad-system-id') foreach ($rows as &$row) $row['S_ID']='2147483648';
        if ($defect==='null-child-id') $rows[0]['C_ID']=null;
        if ($defect==='inconsistent-join') $rows[1]['P_PUBLIC_ID']=str_repeat('f',32);
        if ($defect==='missing-field') unset($rows[0]['S_MODEL_NAME']);
        $connection=$myConnection($rows); if ($defect==='stream-failure') $connection->failFetch=1;
        $service=new MySystemsService(new BitrixMySystemsGateway(static fn():object=>$connection)); $myError(static fn()=>$service->listForUser(7),$defect==='stream-failure' ? 'my_systems_read_failed' : 'my_systems_state_invalid');
    });
}
$test('My Systems SQL driver failure is sanitized and query count stays one',static function() use ($assert,$myConnection,$myError):void {
    $connection=$myConnection(); $connection->failure=new RuntimeException('SQL PUBLIC_ID PRIVATE_SERIAL PRIVATE_HASH /private/path'); $service=new MySystemsService(new BitrixMySystemsGateway(static fn():object=>$connection));
    $myError(static fn()=>$service->listForUser(7),'my_systems_read_failed'); $assert(count($connection->queries)===1);
});
foreach ([['0','50','0','my_systems_user_invalid'],['-1','50','0','my_systems_user_invalid'],['2147483648','50','0','my_systems_user_invalid'],['4294967295','50','0','my_systems_user_invalid'],['7','0','0','my_systems_page_invalid'],['7','101','0','my_systems_page_invalid'],['7','50','-1','my_systems_page_invalid'],['7','50','99999999999999999999999','my_systems_page_invalid']] as [$user,$limit,$offset,$code]) {
    $test('My Systems CLI primitive validation '.$user.'/'.$limit.'/'.$offset,static function() use ($myError,$user,$limit,$offset,$code):void {
        $myError(static fn()=>MySystemsCliInput::fromOptions(['user'=>$user,'limit'=>$limit,'offset'=>$offset]),$code);
    });
}
$test('My Systems CLI accepts defaults and maximum signed user and page limit without coercion',static function() use ($assert):void {
    $default=MySystemsCliInput::fromOptions(['user'=>'7']); $assert($default->userId===7 && $default->page->limit===50 && $default->page->offset===0);
    $max=MySystemsCliInput::fromOptions(['user'=>'2147483647','limit'=>'100','offset'=>'20']); $assert($max->userId===2147483647 && $max->page->limit===100 && $max->page->offset===20);
});
$test('My Systems operator CLI runs the actual read core and rejects invalid input before data access',static function() use ($assert,$removeHttpFixture,$myRecord,$myPassport,$myComponent,$mySqlRows):void {
    $root=sys_get_temp_dir().'/korsac-my-systems-cli-'.bin2hex(random_bytes(6)); mkdir($root.'/bitrix/modules/main/include',0700,true);
    try {
        $user=7830991; $id=1930331; $passport=$myPassport($id,'ACTIVE',str_repeat('c',32));
        $rows=$mySqlRows($myRecord(['id'=>$id,'user'=>$user,'systemId'=>'KS-2610-0101','passport'=>$passport,'components'=>[$myComponent(8930111,$id,'SSD_1','Public account disk','PRIVATE_ACCOUNT_SERIAL')]]));
        $bootstrap=<<<'PHP'
<?php
namespace Bitrix\Main {
    final class Loader { public static function includeModule(string $name):bool { return $name==='kk.korsac'; } }
    final class Application {
        public static function getConnection():object {
            file_put_contents(MY_SYSTEMS_ACCESS,'read');
            return new class($GLOBALS['mySystemsRows']) {
                public function __construct(private array $rows) {}
                public function query(string $sql):object {
                    if (!preg_match('/WHERE `USER_ID`=([0-9]+)/',$sql,$match)) throw new \RuntimeException('Canonical user predicate missing');
                    $rows=array_values(array_filter($this->rows,static fn(array $row):bool=>$row['S_USER_ID']===(int)$match[1]));
                    return new class($rows) { public function __construct(private array $rows) {} public function fetch():array|false { return array_shift($this->rows) ?? false; } };
                }
            };
        }
    }
}
namespace {
PHP;
        $bootstrap.='define(\'MY_SYSTEMS_ACCESS\','.var_export($root.'/data-access',true).'); $GLOBALS[\'mySystemsRows\']='.var_export($rows,true).'; }';
        file_put_contents($root.'/bitrix/modules/main/include/prolog_before.php',$bootstrap);
        $run=static function(array $arguments,string $tool='tools/system.php') use ($root):array {
            $runner='<?php require '.var_export(dirname(__DIR__).'/bootstrap.php',true).'; $_SERVER[\'DOCUMENT_ROOT\']='.var_export($root,true).'; require '.var_export(dirname(__DIR__,2).'/'.$tool,true).';'; file_put_contents($root.'/run.php',$runner);
            $command=[PHP_BINARY]; if (php_ini_loaded_file()) array_push($command,'-c',php_ini_loaded_file()); array_push($command,$root.'/run.php',...$arguments);
            $pipes=[]; $process=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
            if (!is_resource($process)) throw new RuntimeException('CLI subprocess unavailable'); fclose($pipes[0]);
            $out=stream_get_contents($pipes[1]); $err=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); return [proc_close($process),$out,$err];
        };
        [$status,$out,$err]=$run(['my-systems','--user='.$user]); $assert($status===0 && $err===''); $data=json_decode($out,true,512,JSON_THROW_ON_ERROR);
        $assert($data['ok'] && array_keys($data['result'])===['items','hasMore'] && count($data['result']['items'])===1 && $data['result']['items'][0]['components']===[['typeCode'=>'SSD','slotLabel'=>'SSD 1','name'=>'Public account disk']]);
        foreach ([(string)$user,(string)$id,'8930111','PRIVATE_ACCOUNT_SERIAL','SSD_1',$passport->secretCodeHash,'userId','publicId','internalId','componentId','slotKey','serialNumber','ORDER_ID','CONFIG_SNAPSHOT_KEY'] as $private) $assert(!str_contains($out,$private));
        $assert($data['result']['items'][0]['passportPath']==='/passport/'.$passport->publicId.'/');
        [$status,$out,$err]=$run(['my-systems','--user=9']); $assert($status===0 && $err==='' && json_decode($out,true,512,JSON_THROW_ON_ERROR)['result']===['items'=>[],'hasMore'=>false]);
        foreach ([['--user=0','my_systems_user_invalid'],['--user=-1','my_systems_user_invalid'],['--user=2147483648','my_systems_user_invalid'],['--user=4294967295','my_systems_user_invalid'],['--user=invalid','my_systems_user_invalid'],['--user='.$user,'my_systems_page_invalid','--limit=101']] as $case) {
            @unlink($root.'/data-access'); $arguments=['my-systems',$case[0]]; if (isset($case[2])) $arguments[]=$case[2]; [$status,$out,$err]=$run($arguments);
            $assert($status===1 && $out==='' && json_decode($err,true,512,JSON_THROW_ON_ERROR)===['ok'=>false,'code'=>$case[1]] && !is_file($root.'/data-access'));
        }
        @unlink($root.'/data-access'); [$status,$out,$err]=$run(['--confirm-write'],'tests/Integration/my_systems_smoke.php');
        $assert($status===1 && $out==='' && json_decode($err,true,512,JSON_THROW_ON_ERROR)===['ok'=>false,'code'=>'system_cli_arguments_invalid'] && !is_file($root.'/data-access'));
    } finally { $removeHttpFixture($root); }
});
