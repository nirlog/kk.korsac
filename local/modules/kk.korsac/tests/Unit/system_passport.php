<?php

declare(strict_types=1);

use KK\Korsac\System\PassportRecord;
use KK\Korsac\System\PassportSystemState;
use KK\Korsac\System\PassportService;
use KK\Korsac\System\PassportRepositoryInterface;
use KK\Korsac\System\PassportCredentialGeneratorInterface;
use KK\Korsac\System\PassportCredentialCollision;
use KK\Korsac\System\PassportCredentialShape;
use KK\Korsac\System\SystemException;
use KK\Korsac\Install\PassportSchemaDefinition;
use KK\Korsac\Install\Migration\SystemPassportCore;
use KK\Korsac\Health\PassportSchemaSelfCheck;

$passportHex=static fn(int $number):string=>str_pad(dechex($number),32,'0',STR_PAD_LEFT);
$passportFixture=static function() use ($passportHex):array {
    $clock=new class {public int $calls=0;public DateTimeImmutable $time;public function __construct(){ $this->time=new DateTimeImmutable('2026-10-13 10:00:00',new DateTimeZone('Europe/Moscow')); }};
    $origin=$clock->time->modify('-1 day');
    $repo=new class($origin) implements PassportRepositoryInterface {
        public array $parents=[], $records=[], $events=[];
        public int $reads=0,$creates=0,$activations=0,$forcedCollisions=0;
        public bool $failCreate=false,$failActivate=false,$failCommit=false;
        public ?Throwable $readFailure=null;
        public ?PassportRecord $raceWinner=null;
        public function __construct(DateTimeImmutable $origin){$this->parents[1]=new PassportSystemState(1,'ACTIVE',$origin);}
        public function transaction(Closure $op):mixed {
            $backup=$this->records;$this->events[]='begin';
            try{$result=$op();if($this->failCommit)throw new RuntimeException('private driver details');$this->events[]='commit';return $result;}
            catch(Throwable $e){$this->records=$backup;$this->events[]='rollback';throw $e;}
        }
        public function lockSystem(int $id):PassportSystemState{$this->events[]='lock-system';if(!isset($this->parents[$id]))throw new SystemException('system_not_found');return $this->parents[$id];}
        public function lockBySystem(int $id):?PassportRecord{$this->events[]='lock-passport';if($this->raceWinner!==null){$this->records[$id]=$this->raceWinner;$this->raceWinner=null;}return $this->records[$id] ?? null;}
        public function findBySystem(int $id):?PassportRecord{++$this->reads;return $this->records[$id] ?? null;}
        public function findByPublicId(string $pub):?PassportRecord{++$this->reads;if($this->readFailure!==null)throw $this->readFailure;foreach($this->records as $r)if($r->publicId===$pub)return $r;return null;}
        public function create(PassportRecord $r):PassportRecord{
            ++$this->creates;$this->events[]='create';if($this->failCreate)throw new RuntimeException('SQL secret /private/path');
            if($this->forcedCollisions>0){--$this->forcedCollisions;throw new PassportCredentialCollision();}
            foreach($this->records as $old)if($old->publicId===$r->publicId || $old->secretCodeHash===$r->secretCodeHash)throw new PassportCredentialCollision();
            $this->records[$r->systemInternalId]=$r;return $r;
        }
        public function activate(int $id,DateTimeImmutable $transfer,DateTimeImmutable $updated):PassportRecord{
            ++$this->activations;$this->events[]='activate';$old=$this->records[$id];$new=new PassportRecord($id,$old->publicId,$old->secretCodeHash,'ACTIVE',$transfer,$transfer,$old->preparedAt,$updated);$this->records[$id]=$new;
            if($this->failActivate)throw new RuntimeException('SQL details');return $new;
        }
    };
    $generator=new class($passportHex) implements PassportCredentialGeneratorInterface {
        public int $publicCalls=0,$secretCalls=0;public bool $fail=false,$identical=false;public ?string $badPublic=null,$badSecret=null;
        public function __construct(private Closure $hex){}
        public function generatePublicId():string{++$this->publicCalls;if($this->fail)throw new RuntimeException('private randomness error');return $this->badPublic ?? ($this->hex)($this->publicCalls);}
        public function generateSecretCode():string{++$this->secretCalls;return $this->badSecret ?? ($this->hex)($this->identical?$this->secretCalls:1000+$this->secretCalls);}
    };
    $service=new PassportService($repo,$generator,static function()use($clock):DateTimeImmutable{++$clock->calls;return $clock->time;});
    return compact('repo','generator','service','clock','origin');
};
$passportSchemaGateway=static fn()=>new class implements \KK\Korsac\Install\SystemTableGatewayInterface {
    public array $tables=[];public int $writes=0;
    public function describe(string $t):?array{return $this->tables[$t] ?? null;}
    public function createTable(string $t):void{$d=PassportSchemaDefinition::tables()[$t];foreach($d['columns'] as &$c)if(isset($c['size']))$c['collation']='utf8mb4_unicode_ci';$this->tables[$t]=['engine'=>'InnoDB','columns'=>$d['columns'],'indexes'=>['PRIMARY'=>$d['indexes']['PRIMARY']]];++$this->writes;}
    public function createIndex(string $t,string $name,array $index):void{$this->tables[$t]['indexes'][$name]=$index;++$this->writes;}
};

$test('Passport secure generator uses two independent 16-byte sources and lowercase hex',static function()use($assert):void{
    $calls=[];$g=new \KK\Korsac\System\SecurePassportCredentialGenerator(static function(int $n)use(&$calls):string{$calls[]=$n;return str_repeat(chr(count($calls)),$n);});
    $pub=$g->generatePublicId();$sec=$g->generateSecretCode();$assert($calls===[16,16] && $pub===str_repeat('01',16) && $sec===str_repeat('02',16));
    $real=new \KK\Korsac\System\SecurePassportCredentialGenerator();$assert(PassportCredentialShape::token($real->generatePublicId()) && PassportCredentialShape::token($real->generateSecretCode()));
});
$test('Passport secure generator sanitizes random source failures and wrong byte length',static function()use($systemError):void{
    foreach([static fn(int $n):string=>throw new RuntimeException('private random driver/path'),static fn(int $n):string=>'short'] as $source){$g=new \KK\Korsac\System\SecurePassportCredentialGenerator($source);$systemError(static fn()=>$g->generatePublicId(),'system_passport_token_generation_failed');}
});
$test('Passport prepare stores only SHA-256 and issues independent plaintext once',static function()use($assert,$passportFixture,$passportHex):void{
    $f=$passportFixture();$one=$f['service']->preparePassport(1);$r=$one->passport;
    $assert($one->created && $one->secretCode===$passportHex(1001) && $r->secretCodeHash===hash('sha256',$one->secretCode) && $r->publicId===$passportHex(1));
    $assert($r->state==='PREPARED' && $r->transferredAt===null && $r->warrantyStartedAt===null && $r->preparedAt==$f['clock']->time && $r->updatedAt==$r->preparedAt);
    $assert(array_keys(get_object_vars($f['repo']->records[1]))===['systemInternalId','publicId','secretCodeHash','state','transferredAt','warrantyStartedAt','preparedAt','updatedAt']);
    $assert(!property_exists($r,'secretCode') && $r->publicId!==$one->secretCode);
    $two=$f['service']->preparePassport(1);$assert(!$two->created && $two->secretCode===null && $two->passport===$r && $f['repo']->creates===1 && $f['generator']->publicCalls===1 && $f['generator']->secretCalls===1 && $f['clock']->calls===1);
});
foreach(['public','secret'] as $kind)$test('Passport '.$kind.' UNIQUE collision regenerates both under the same parent lock',static function()use($assert,$passportFixture,$passportHex,$kind):void{
    $f=$passportFixture();$f['repo']->records[2]=new PassportRecord(2,$kind==='public'?$passportHex(1):$passportHex(9999),hash('sha256',$kind==='secret'?$passportHex(1001):$passportHex(9000)),'PREPARED',null,null,$f['origin'],$f['origin']);
    $result=$f['service']->preparePassport(1);$assert($result->passport->publicId===$passportHex(2) && $result->secretCode===$passportHex(1002) && $f['repo']->creates===2 && $f['generator']->publicCalls===2 && $f['generator']->secretCalls===2);
    $assert(count(array_filter($f['repo']->events,static fn(string $event):bool=>$event==='lock-system'))===1 && end($f['repo']->events)==='commit');
});
$test('Passport credential collision retries are bounded and equal public/private values are never issued',static function()use($assert,$passportFixture,$systemError):void{
    foreach([false,true] as $same){$f=$passportFixture();$f['generator']->identical=$same;$f['repo']->forcedCollisions=5;$systemError(static fn()=>$f['service']->preparePassport(1),'system_passport_token_collision');$assert($f['generator']->publicCalls===5 && $f['generator']->secretCalls===5 && $f['repo']->records===[]);}
});
$test('Passport generator malformed tokens and generation errors fail safely before storage',static function()use($assert,$passportFixture,$systemError):void{
    foreach(['badPublic','badSecret','fail'] as $field){$f=$passportFixture();$f['generator']->{$field}=$field==='fail'?true:str_repeat('A',32);$systemError(static fn()=>$f['service']->preparePassport(1),'system_passport_token_generation_failed');$assert($f['repo']->creates===0 && $f['repo']->records===[]);}
});
$test('Passport noncollision insert and commit failures are safe and do not return a secret',static function()use($assert,$passportFixture,$systemError):void{
    foreach(['failCreate','failCommit'] as $field){$f=$passportFixture();$f['repo']->{$field}=true;$systemError(static fn()=>$f['service']->preparePassport(1),'system_passport_persist_failed');$assert($f['repo']->records===[] && $f['repo']->creates===1 && $f['generator']->publicCalls===1);}
});
$test('Passport same-System first-row race observes one winner and generates nothing',static function()use($assert,$passportFixture,$passportHex):void{
    $f=$passportFixture();$winner=new PassportRecord(1,$passportHex(77),hash('sha256',$passportHex(88)),'PREPARED',null,null,$f['origin'],$f['origin']);$f['repo']->raceWinner=$winner;
    $result=$f['service']->preparePassport(1);$assert(!$result->created && $result->secretCode===null && $result->passport===$winner && count($f['repo']->records)===1 && $f['generator']->publicCalls===0);
});
$test('Passport rejects missing inactive and out-of-range Systems without credential generation',static function()use($assert,$passportFixture,$systemError):void{
    $f=$passportFixture();$systemError(static fn()=>$f['service']->preparePassport(2),'system_not_found');
    $f['repo']->parents[1]=new PassportSystemState(1,'CANCELLED',$f['origin']);$systemError(static fn()=>$f['service']->preparePassport(1),'system_passport_system_inactive');
    foreach([0,-1,2147483648,4294967295] as $id){$systemError(static fn()=>$f['service']->preparePassport($id),'system_source_invalid');$systemError(static fn()=>$f['service']->activatePassport($id),'system_source_invalid');}
    $assert($f['generator']->publicCalls===0 && $f['repo']->records===[]);
    $f['repo']->parents[2147483647]=new PassportSystemState(2147483647,'ACTIVE',$f['origin']);$assert($f['service']->preparePassport(2147483647)->passport->systemInternalId===2147483647);
});
$test('Passport secret verification returns only boolean and performs no mutation',static function()use($assert,$passportFixture,$passportHex):void{
    $f=$passportFixture();$p=$f['service']->preparePassport(1);$before=$f['repo']->records;$creates=$f['repo']->creates;
    $assert($f['service']->verifySecret($p->passport->publicId,$p->secretCode));
    foreach([[$p->passport->publicId,$passportHex(9999)],[$passportHex(9999),$p->secretCode],['bad',$p->secretCode],[$p->passport->publicId,'bad'],[$p->passport->publicId,str_repeat('A',32)]] as [$pub,$secret])$assert($f['service']->verifySecret($pub,$secret)===false);
    $assert($before===$f['repo']->records && $creates===$f['repo']->creates);
    $source=(string)file_get_contents(dirname(__DIR__,2).'/lib/System/PassportService.php');$verification=explode('public function verifySecret',$source)[1];$assert(str_contains($verification,'hash_equals($passport->secretCodeHash,hash(\'sha256\',$secretCode))'));
});
$test('Passport activation is explicit handoff and warranty start with immutable identity and dates',static function()use($assert,$passportFixture):void{
    $f=$passportFixture();$prepared=$f['service']->preparePassport(1)->passport;$transfer=$f['clock']->time->modify('-2 hours');$active=$f['service']->activatePassport(1,$transfer);
    $assert($active->state==='ACTIVE' && $active->transferredAt==$transfer && $active->warrantyStartedAt==$transfer && $active->updatedAt==$f['clock']->time && $active->preparedAt==$prepared->preparedAt && $active->publicId===$prepared->publicId && $active->secretCodeHash===$prepared->secretCodeHash);
    $f['clock']->time=$f['clock']->time->modify('+1 day');$again=$f['service']->activatePassport(1);$same=$f['service']->activatePassport(1,$transfer->setTimezone(new DateTimeZone('UTC')));
    $assert($again===$active && $same===$active && $f['repo']->activations===1 && $f['clock']->calls===2 && $f['generator']->publicCalls===1);
    $repeat=$f['service']->preparePassport(1);$assert(!$repeat->created && $repeat->secretCode===null && $repeat->passport===$active);
});
$test('Passport default handoff uses now without a production READY requirement',static function()use($assert,$passportFixture):void{
    $f=$passportFixture();$f['service']->preparePassport(1);$active=$f['service']->activatePassport(1);$assert($active->transferredAt==$f['clock']->time && $active->warrantyStartedAt==$active->transferredAt);
});
$test('Passport activation rejects unprepared missing inactive before/future dates and conflicting history',static function()use($assert,$passportFixture,$systemError):void{
    $f=$passportFixture();$systemError(static fn()=>$f['service']->activatePassport(1),'system_passport_not_found');$systemError(static fn()=>$f['service']->activatePassport(2),'system_not_found');$f['service']->preparePassport(1);
    foreach([$f['origin']->modify('-1 second'),$f['clock']->time->modify('+1 second'),$f['clock']->time->modify('-1 second')->modify('+1 microsecond')] as $bad)$systemError(static fn()=>$f['service']->activatePassport(1,$bad),'system_passport_transfer_time_invalid');
    $assert($f['repo']->activations===0);$f['repo']->parents[1]=new PassportSystemState(1,'CANCELLED',$f['origin']);$systemError(static fn()=>$f['service']->activatePassport(1),'system_passport_system_inactive');
    $f['repo']->parents[1]=new PassportSystemState(1,'ACTIVE',$f['origin']);$active=$f['service']->activatePassport(1);$systemError(static fn()=>$f['service']->activatePassport(1,$active->transferredAt->modify('-1 second')),'system_passport_activation_conflict');
});
$test('Passport failed activation rolls back prepared state and immutable values',static function()use($assert,$passportFixture,$systemError):void{
    $f=$passportFixture();$p=$f['service']->preparePassport(1)->passport;$f['repo']->failActivate=true;$systemError(static fn()=>$f['service']->activatePassport(1),'system_passport_persist_failed');$assert($f['repo']->records[1]===$p && $p->state==='PREPARED');
});
$test('Passport CLI transfer parsing is strict ISO-8601 and clocks use DATETIME seconds',static function()use($assert,$systemError):void{
    $parse=\KK\Korsac\System\PassportTimestamp::fromIso8601('2026-10-13T07:00:00Z');$assert($parse==new DateTimeImmutable('2026-10-13T10:00:00+03:00'));
    foreach(['tomorrow','2026-02-30T07:00:00Z','2026-10-13T07:00:00.1Z','2026-10-13 07:00:00'] as $bad)$systemError(static fn()=>\KK\Korsac\System\PassportTimestamp::fromIso8601($bad),'system_passport_transfer_time_invalid');
    $assert(\KK\Korsac\System\PassportTimestamp::seconds(new DateTimeImmutable('2026-10-13T10:00:00.123456+03:00'))->format('u')==='000000');
});

$test('Passport public malformed unknown and prepared lookups have identical generic not-found',static function()use($assert,$passportFixture,$systemRepo,$systemError,$passportHex):void{
    $f=$passportFixture();$prepared=$f['service']->preparePassport(1);
    $components=new class implements \KK\Korsac\System\SystemComponentRepositoryInterface {
        public function findForSystem(int $id,bool $activeOnly=false):array{throw new RuntimeException('No component read before activation');}
    };
    $public=new \KK\Korsac\System\PublicPassportService($f['repo'],$systemRepo(),$components);
    foreach(['bad',$passportHex(9999),$prepared->passport->publicId] as $id)$systemError(static fn()=>$public->find($id),'system_passport_not_found');
});

$publicPassportFixture=static function() use ($passportFixture):array {
    $f=$passportFixture();
    $f['preparation']=$f['service']->preparePassport(1);
    $f['active']=$f['service']->activatePassport(1);
    $source=\KK\Korsac\System\SystemCreationRequest::fromArray(['SITE_ID'=>'s1','ORDER_ID'=>10,'ORDER_BASKET_ID'=>20,'ORDER_UNIT_INDEX'=>1,'SYSTEM_TYPE'=>'MODEL','MODEL_NAME'=>'KORSAC PLAY 1440']);
    $system=new \KK\Korsac\System\SystemRecord(1,'KS-2610-0007',$source,null,'ACTIVE',$f['origin'],$f['origin']);
    $f['systems']=new class($system) implements \KK\Korsac\System\SystemRepositoryInterface {
        public ?Throwable $readFailure=null;
        public function __construct(public ?\KK\Korsac\System\SystemRecord $record) {}
        public function findById(int $id):?\KK\Korsac\System\SystemRecord { if ($this->readFailure!==null) throw $this->readFailure; return $this->record; }
        public function create(\KK\Korsac\System\SystemRecord $system):\KK\Korsac\System\SystemRecord { throw new RuntimeException('Unexpected write'); }
        public function findBySystemId(string $systemId):?\KK\Korsac\System\SystemRecord { throw new RuntimeException('Unexpected lookup'); }
        public function findByOrderUnit(int $orderId,int $basketId,int $unitIndex,bool $currentRead=false):?\KK\Korsac\System\SystemRecord { throw new RuntimeException('Unexpected lookup'); }
        public function findByOrder(int $orderId):array { throw new RuntimeException('Unexpected lookup'); }
        public function findByOrderBasket(int $orderId,int $basketId):array { throw new RuntimeException('Unexpected lookup'); }
    };
    $f['components']=new class implements \KK\Korsac\System\SystemComponentRepositoryInterface {
        public ?Throwable $readFailure=null;
        public bool $activeRequested=false;
        public function findForSystem(int $id,bool $activeOnly=false):array { $this->activeRequested=$activeOnly; if ($this->readFailure!==null) throw $this->readFailure; return []; }
    };
    $f['public']=new \KK\Korsac\System\PublicPassportService($f['repo'],$f['systems'],$f['components']);
    return $f;
};
$publicReadError=static function(callable $action) use ($assert):void {
    try { $action(); }
    catch (SystemException $error) {
        $assert($error->machineCode==='system_passport_read_failed');
        $assert($error->getMessage()==='system_passport_read_failed');
        $assert($error->diagnostic()===['code'=>'system_passport_read_failed']);
        $assert($error->getPrevious()===null);
        return;
    }
    throw new RuntimeException('Expected sanitized public Passport read failure');
};
foreach (['repo'=>'Passport','systems'=>'System','components'=>'active component'] as $layer=>$label) {
    $test('Passport public normalizes '.$label.' repository failures without private diagnostics',static function() use ($assert,$publicPassportFixture,$publicReadError,$layer):void {
        $f=$publicPassportFixture();
        $private='SELECT * FROM customer WHERE ORDER_ID=442241 /private/credentials PRIVATE_SERIAL '.$f['preparation']->secretCode.' '.$f['active']->secretCodeHash;
        $context=['table'=>'private_customer','field'=>$private,'index'=>'private_read'];
        foreach ([new RuntimeException($private),new SystemException('underlying_read_failed',$context),new SystemException('system_passport_not_found',$context)] as $failure) {
            $f[$layer]->readFailure=$failure;
            $publicReadError(static fn()=>$f['public']->find($f['active']->publicId));
        }
        if ($layer==='components') $assert($f['components']->activeRequested);
    });
}
$test('Passport public corrupt persisted state is a read failure rather than not-found',static function() use ($publicPassportFixture,$publicReadError):void {
    $f=$publicPassportFixture(); $valid=$f['active'];
    foreach ([
        new PassportRecord(1,$valid->publicId,$valid->secretCodeHash,'EXPIRED',null,null,$valid->preparedAt,$valid->updatedAt),
        new PassportRecord(1,$valid->publicId,$valid->secretCodeHash,'ACTIVE',null,null,$valid->preparedAt,$valid->updatedAt),
        new PassportRecord(1,$valid->publicId,$valid->secretCodeHash,'ACTIVE',$valid->transferredAt,$valid->transferredAt->modify('+1 second'),$valid->preparedAt,$valid->updatedAt),
        new PassportRecord(1,$valid->publicId,'invalid hash','ACTIVE',$valid->transferredAt,$valid->warrantyStartedAt,$valid->preparedAt,$valid->updatedAt),
        new PassportRecord(0,$valid->publicId,$valid->secretCodeHash,'ACTIVE',$valid->transferredAt,$valid->warrantyStartedAt,$valid->preparedAt,$valid->updatedAt),
        new PassportRecord(1,$valid->publicId,$valid->secretCodeHash,'PREPARED',$valid->transferredAt,$valid->warrantyStartedAt,$valid->preparedAt,$valid->updatedAt),
    ] as $corrupt) {
        $f['repo']->records[1]=$corrupt;
        $publicReadError(static fn()=>$f['public']->find($valid->publicId));
    }
});
$test('Passport public missing parent System is a data read failure rather than not-found',static function() use ($publicPassportFixture,$publicReadError):void {
    $f=$publicPassportFixture(); $f['systems']->record=null;
    $publicReadError(static fn()=>$f['public']->find($f['active']->publicId));
});

$test('Passport active DTO allowlists values and excludes private System and component history data',static function()use($assert,$passportFixture,$systemRepo):void{
    $f=$passportFixture();$systems=$systemRepo();$id=330031;$request=\KK\Korsac\System\SystemCreationRequest::fromArray(['SITE_ID'=>'s1','ORDER_ID'=>442241,'ORDER_BASKET_ID'=>552251,'ORDER_UNIT_INDEX'=>9,'SYSTEM_TYPE'=>'MODEL','MODEL_NAME'=>'KORSAC PLAY 1440','IBLOCK_ID'=>662261,'PRODUCT_ID'=>772271,'CONFIG_SNAPSHOT_KEY'=>str_repeat('e',64)]);
    $systems->items[]=new \KK\Korsac\System\SystemRecord($id,'KS-2610-0007',$request,882281,'ACTIVE',$f['origin'],$f['origin']);$f['repo']->parents[$id]=new PassportSystemState($id,'ACTIVE',$f['origin']);$p=$f['service']->preparePassport($id);$f['service']->activatePassport($id);
    $components=new class($f['origin'],$id) implements \KK\Korsac\System\SystemComponentRepositoryInterface {
        public bool $activeRequested=false;public array $records;
        public function __construct(DateTimeImmutable $at,int $id){$this->records=[new \KK\Korsac\System\SystemComponent(991191,$id,'SSD','PRIVATE_SLOT_1','SSD 1','Actual disk','PRIVATE_SN_ACTIVE',$at,null,$at,$at),new \KK\Korsac\System\SystemComponent(992192,$id,'SSD','PRIVATE_SLOT_1','SSD 1','Removed disk','PRIVATE_SN_REMOVED',$at,$at,$at,$at),new \KK\Korsac\System\SystemComponent(993193,1,'GPU','PRIVATE_SLOT_2','GPU','Wrong parent component','OTHER_PRIVATE_SN',$at,null,$at,$at)];}
        public function findForSystem(int $id,bool $activeOnly=false):array{$this->activeRequested=$activeOnly;return $this->records;}
    };
    $dto=(new \KK\Korsac\System\PublicPassportService($f['repo'],$systems,$components))->find($p->passport->publicId);$view=$dto->toArray();
    $assert(array_keys($view)===['publicId','systemId','systemType','modelName','transferredAt','warrantyStartedAt','components']);$assert($components->activeRequested && $view['components']===[['typeCode'=>'SSD','slotLabel'=>'SSD 1','name'=>'Actual disk']]);
    $assert($view['systemId']==='KS-2610-0007' && $view['systemType']==='MODEL' && $view['modelName']==='KORSAC PLAY 1440' && $view['transferredAt']===$view['warrantyStartedAt']);
    $privateKeys=['systemInternalId','orderId','orderBasketId','orderUnitIndex','userId','iblockId','productId','snapshotKey','secretCode','secretCodeHash','serialNumber','slotKey','id','productionHistory'];
    $privateValues=[$id,442241,552251,9,882281,662261,772271,str_repeat('e',64),$p->secretCode,$p->passport->secretCodeHash,991191,992192,993193,'PRIVATE_SLOT_1','PRIVATE_SLOT_2','PRIVATE_SN_ACTIVE','PRIVATE_SN_REMOVED','OTHER_PRIVATE_SN','Removed disk','Wrong parent component'];
    $check=static function(array $data)use(&$check,$assert,$privateKeys,$privateValues):void{foreach($data as $key=>$value){$assert(!in_array($key,$privateKeys,true));if(is_array($value))$check($value);else $assert(!in_array($value,$privateValues,true));}};$check($view);
    $assert(json_decode(json_encode($dto,JSON_THROW_ON_ERROR),true)===$view); // DTO fields themselves also contain only the allowlist.
    $safe=\KK\Korsac\System\PassportInternalView::of('KS-2610-0007',$f['repo']->records[$id]);$check($safe);$assert(!array_key_exists('secretCodeHash',$safe));
});
$test('Passport canonical path uses only opaque public ID and rejects all malformed IDs',static function()use($assert,$passportFixture,$systemError):void{
    $f=$passportFixture();$p=$f['service']->preparePassport(1);$path=\KK\Korsac\System\PassportPath::build($p->passport->publicId);$assert($path==='/passport/'.$p->passport->publicId.'/' && !str_contains($path,$p->secretCode) && !str_contains($path,$p->passport->secretCodeHash) && !str_contains($path,'KS-'));
    foreach(['KS-2610-0007','1',str_repeat('A',32),str_repeat('a',31),str_repeat('a',33),str_repeat('a',32).'?secret=x',str_repeat('a',32).'#x'] as $bad)$systemError(static fn()=>\KK\Korsac\System\PassportPath::build($bad),'system_passport_not_found');
});
$test('Passport ORM rejects every ordinary update including transfer/state and deletion',static function()use($assert,$systemError):void{
    require_once __DIR__.'/fixtures/system_table_parent.php';\Bitrix\Main\Entity\DataManager::$updates=[];
    foreach(['SYSTEM_INTERNAL_ID','PUBLIC_ID','SECRET_CODE_HASH','STATE','TRANSFERRED_AT','WARRANTY_STARTED_AT','PREPARED_AT','UPDATED_AT'] as $field)$systemError(static fn()=>\KK\Korsac\System\PassportTable::update(1,[$field=>null]),'system_passport_immutable');
    $systemError(static fn()=>\KK\Korsac\System\PassportTable::delete(1),'system_passport_immutable');$assert(\Bitrix\Main\Entity\DataManager::$updates===[]);
});
$test('Passport collision classifier accepts only credential UNIQUE driver failures',static function()use($assert):void{
    require_once __DIR__.'/fixtures/passport_query_exception.php';
    foreach(['ux_kk_korsac_passport_public_id','b_kk_korsac_system_passport.ux_kk_korsac_passport_secret_hash'] as $key)$assert(\KK\Korsac\System\PassportCollisionClassifier::isCredentialDuplicate(new \Bitrix\Main\DB\SqlQueryException("Duplicate entry 'private-value' for key '{$key}'")));
    foreach([new \Bitrix\Main\DB\SqlQueryException("Duplicate entry '1' for key 'PRIMARY'"),new \Bitrix\Main\DB\SqlQueryException("Denied for key 'ux_kk_korsac_passport_public_id'"),new RuntimeException("Duplicate entry 'x' for key 'ux_kk_korsac_passport_public_id'")] as $error)$assert(!\KK\Korsac\System\PassportCollisionClassifier::isCredentialDuplicate($error));
});
$test('Passport migration 008 is fresh/upgrade idempotent and preserves previous history',static function()use($assert,$passportSchemaGateway,$fakeStore):void{
    $g=$passportSchemaGateway();$store=$fakeStore(['2026_10_12_007_system_sale_automation']);$service=new \KK\Korsac\Install\PassportMigrationService($store,$g);
    $assert($service->migrate()===['2026_10_13_008_system_passport_core']);$writes=$g->writes;$assert($service->migrate()===[] && $g->writes===$writes && count($g->tables)===1 && $store->has('2026_10_12_007_system_sale_automation') && (new PassportSchemaSelfCheck($g))->run()['ok']);
    $d=PassportSchemaDefinition::tables()[PassportSchemaDefinition::TABLE];$assert($d['columns']['SYSTEM_INTERNAL_ID']['type']==='int' && $d['columns']['PUBLIC_ID']['type']==='char(32)' && $d['columns']['SECRET_CODE_HASH']['type']==='char(64)' && !isset($d['columns']['SECRET_CODE']));
    foreach(['ux_kk_korsac_passport_public_id'=>'PUBLIC_ID','ux_kk_korsac_passport_secret_hash'=>'SECRET_CODE_HASH'] as $index=>$column)$assert($d['indexes'][$index]===['columns'=>[$column],'unique'=>true]);
});
$test('Passport health rejects missing table and indexes and migration repairs only compatible missing indexes',static function()use($assert,$passportSchemaGateway):void{
    $g=$passportSchemaGateway();$assert(!(new PassportSchemaSelfCheck($g))->run()['ok']);$m=new SystemPassportCore($g);$m->up();$before=$g->writes;
    unset($g->tables[PassportSchemaDefinition::TABLE]['indexes']['ix_kk_korsac_passport_state']);$assert(!(new PassportSchemaSelfCheck($g))->run()['ok']);$m->up();$assert($g->writes===$before+1 && (new PassportSchemaSelfCheck($g))->run()['ok']);
});
foreach(['unsigned','column-size','nullable','public-prefix','hash-prefix','uniqueness','engine'] as $defect)$test('Passport schema '.$defect.' fails before any corrective DDL',static function()use($assert,$passportSchemaGateway,$systemError,$defect):void{
    $g=$passportSchemaGateway();$m=new SystemPassportCore($g);$m->up();$table=&$g->tables[PassportSchemaDefinition::TABLE];
    if($defect==='unsigned')$table['columns']['SYSTEM_INTERNAL_ID']['type']='int unsigned';
    if($defect==='column-size')$table['columns']['PUBLIC_ID']['type']='varchar(32)';
    if($defect==='nullable')$table['columns']['SECRET_CODE_HASH']['nullable']=true;
    if($defect==='public-prefix')$table['indexes']['ux_kk_korsac_passport_public_id']['partial']=true;
    if($defect==='hash-prefix')$table['indexes']['ux_kk_korsac_passport_secret_hash']['partial']=true;
    if($defect==='uniqueness')$table['indexes']['ux_kk_korsac_passport_secret_hash']['unique']=false;
    if($defect==='engine')$table['engine']='MyISAM';
    $before=$g->writes;$assert(!(new PassportSchemaSelfCheck($g))->run()['ok']);$systemError(static fn()=>$m->up(),'passport_schema_incompatible');$assert($g->writes===$before);
});
$test('Passport uninstall wiring retains Passport business data and has no destructive operation',static function()use($assert):void{
    $installer=(string)file_get_contents(dirname(__DIR__,2).'/install/index.php');$body=explode('public function DoUninstall(): void',$installer)[1];$body=explode('public function InstallFiles()',$body)[0];$assert(str_contains($body,'Passports/credential hashes/handoff/warranty starts') && !str_contains($body,'DROP') && !str_contains($body,'delete(') && !str_contains($body,'PassportTable'));
});

$test('Passport preparation result cannot carry a reissued or malformed secret',static function()use($passportFixture,$systemError):void{
    $f=$passportFixture();$r=$f['service']->preparePassport(1)->passport;
    $systemError(static fn()=>new \KK\Korsac\System\PassportPreparationResult($r,false,str_repeat('a',32)),'system_passport_state_invalid');
    $systemError(static fn()=>new \KK\Korsac\System\PassportPreparationResult($r,true,null),'system_passport_state_invalid');
    $systemError(static fn()=>new \KK\Korsac\System\PassportPreparationResult($r,true,'bad'),'system_passport_state_invalid');
});
$test('Passport invalid persisted states fail preparation and are not made public',static function()use($assert,$passportFixture,$systemError,$passportHex):void{
    $f=$passportFixture();$f['repo']->records[1]=new PassportRecord(1,$passportHex(1),hash('sha256',$passportHex(1001)),'EXPIRED',null,null,$f['origin'],$f['origin']);
    $systemError(static fn()=>$f['service']->preparePassport(1),'system_passport_state_invalid');$systemError(static fn()=>$f['service']->activatePassport(1),'system_passport_state_invalid');$assert($f['generator']->publicCalls===0 && $f['repo']->activations===0);
});
