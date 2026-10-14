<?php

declare(strict_types=1);

use KK\Korsac\Ownership\OwnershipSystemState;
use KK\Korsac\Ownership\OwnershipHistoryRecord;
use KK\Korsac\Ownership\OwnershipService;
use KK\Korsac\Ownership\OwnershipReadService;
use KK\Korsac\Ownership\OwnershipRevision;
use KK\Korsac\Ownership\OwnershipIdentity;
use KK\Korsac\Install\OwnershipSchemaDefinition;
use KK\Korsac\Install\Migration\SystemOwnershipCore;
use KK\Korsac\Health\OwnershipSchemaSelfCheck;

$ownershipError=static function(callable $action,string $code) use ($assert):void {
    try { $action(); }
    catch (\KK\Korsac\System\SystemException $error) {
        $assert($error->getMessage()===$code && $error->diagnostic()===['code'=>$code] && $error->getPrevious()===null); return;
    }
    throw new RuntimeException('Expected '.$code);
};
$ownershipFixture=static function():array {
    $pub=str_repeat('a',32); $secret=str_repeat('b',32);
    $clock=new class { public int $calls=0; public DateTimeImmutable $time; public function __construct() { $this->time=new DateTimeImmutable('2026-10-14T12:00:00.123456+03:00'); } };
    $origin=$clock->time->modify('-1 day')->setTime(12,0,0);
    $gateway=new class($pub,$secret,$origin) implements \KK\Korsac\Ownership\OwnershipGatewayInterface {
        public array $systems=[],$records=[],$events=[];
        public ?\KK\Korsac\System\PassportRecord $passport;
        public int $identifiedId=1,$writes=0,$appends=0;
        public bool $failUpdate=false,$failHistory=false,$failCommit=false;
        public ?string $failRead=null;
        public ?int $raceWinner=null;
        private array $backupSystems=[],$backupRecords=[];
        public function __construct(private string $pub,string $secret,DateTimeImmutable $origin) {
            $this->systems[1]=new OwnershipSystemState(1,'KS-2610-0006',null,'ACTIVE',$origin,$origin);
            $handoff=$origin->modify('+1 hour');
            $this->passport=new \KK\Korsac\System\PassportRecord(1,$pub,hash('sha256',$secret),'ACTIVE',$handoff,$handoff,$origin,$handoff);
        }
        public function transaction(Closure $operation):mixed {
            $this->backupSystems=$this->systems; $this->backupRecords=$this->records; $this->events[]='begin';
            try { $result=$operation(); if ($this->failCommit) throw new RuntimeException('PRIVATE_COMMIT SQL /private/path'); $this->events[]='commit'; return $result; }
            catch (Throwable $error) { $this->systems=$this->backupSystems; $this->records=$this->backupRecords; $this->events[]='rollback'; throw $error; }
        }
        private function read(string $operation):void {
            $this->events[]=$operation;
            if ($this->failRead===$operation) throw new \KK\Korsac\System\SystemException('system_ownership_read_failed',['field'=>'PRIVATE_SECRET_HASH_OWNER /private/path']);
        }
        public function identifySystem(string $pub):?int { $this->read('identify'); return $pub===$this->pub ? $this->identifiedId : null; }
        public function lockSystem(int $id):?OwnershipSystemState {
            $this->read('lock-system');
            if ($this->raceWinner!==null) {
                // Simulate another transaction committing between identification and this parent lock.
                $old=$this->systems[$id]; $user=$this->raceWinner;
                $this->systems[$id]=new OwnershipSystemState($id,$old->systemId,$user,$old->lifecycleState,$old->createdAt,$old->updatedAt);
                $this->records=[new OwnershipHistoryRecord(1,$id,1,null,$user,'CLAIMED','SECRET_CLAIM',$user,$old->updatedAt)];
                $this->backupSystems=$this->systems; $this->backupRecords=$this->records; $this->raceWinner=null;
            }
            return $this->systems[$id] ?? null;
        }
        public function lockPassport(int $id):?\KK\Korsac\System\PassportRecord { $this->read('lock-passport'); return $this->passport; }
        public function latestHistory(int $id):?OwnershipHistoryRecord { $this->read('history'); return $this->records===[] ? null : $this->records[count($this->records)-1]; }
        public function claimUnownedSystem(int $id,int $user,DateTimeImmutable $at):void {
            ++$this->writes; $this->events[]='assign'; $old=$this->systems[$id];
            if ($old->userId!==null) throw new RuntimeException('Conditional assignment failed');
            $this->systems[$id]=new OwnershipSystemState($id,$old->systemId,$user,$old->lifecycleState,$old->createdAt,$at);
            if ($this->failUpdate) throw new RuntimeException('PRIVATE_OWNER SQL /private/path');
        }
        public function appendClaim(OwnershipHistoryRecord $record):void {
            ++$this->appends; $this->events[]='append';
            if ($this->failHistory) throw new RuntimeException('Duplicate entry PRIVATE_HASH for revision /private/path');
            $this->records[]=new OwnershipHistoryRecord(count($this->records)+1,$record->systemInternalId,$record->revision,$record->fromUserId,$record->toUserId,$record->eventCode,$record->sourceCode,$record->actorUserId,$record->createdAt);
        }
        public function historyForSystem(int $id):array { $this->read('history-list'); return $this->records; }
    };
    $users=new class implements \KK\Korsac\Ownership\OwnershipUserGatewayInterface {
        public array $active=[7=>true,8=>true,6=>false,2147483647=>true]; public int $reads=0; public bool $fail=false;
        public function isActiveUser(int $userId):bool { ++$this->reads; if ($this->fail) throw new \KK\Korsac\System\SystemException('system_ownership_read_failed',['table'=>'PRIVATE_CUSTOMER']); return $this->active[$userId] ?? false; }
    };
    $service=new OwnershipService($gateway,$users,static function() use ($clock):DateTimeImmutable { ++$clock->calls; return $clock->time; });
    return compact('gateway','users','service','clock','origin','pub','secret');
};
$test('Ownership first claim assigns the sole current owner and appends one exact audit atomically',static function() use ($assert,$ownershipFixture):void {
    $f=$ownershipFixture(); $passport=$f['gateway']->passport; $result=$f['service']->claim($f['pub'],$f['secret'],7); $record=$f['gateway']->records[0];
    $assert($result->claimed && $result->userId===7 && $result->revision===1 && $result->systemId==='KS-2610-0006');
    $assert($f['gateway']->systems[1]->userId===7 && $record->id===1 && $record->revision===1 && $record->fromUserId===null && $record->toUserId===7 && $record->actorUserId===7 && $record->eventCode==='CLAIMED' && $record->sourceCode==='SECRET_CLAIM');
    $assert($record->createdAt==$f['gateway']->systems[1]->updatedAt && $record->createdAt==$result->claimedAt && $record->createdAt->format('u')==='000000');
    $assert($f['gateway']->events===['begin','identify','lock-system','lock-passport','history','assign','append','commit'] && $passport===$f['gateway']->passport);
    foreach ([$result,$record,$f['gateway']->systems[1]] as $object) { $data=json_encode($object,JSON_THROW_ON_ERROR); $assert(!str_contains($data,$f['secret']) && !str_contains($data,$passport->secretCodeHash) && !property_exists($object,'secretCode') && !property_exists($object,'secretCodeHash')); }
});
$test('Ownership same-user repeat requires the credential and changes no timestamp audit or credential',static function() use ($assert,$ownershipFixture):void {
    $f=$ownershipFixture(); $first=$f['service']->claim($f['pub'],$f['secret'],7); $state=$f['gateway']->systems[1]; $history=$f['gateway']->records; $passport=$f['gateway']->passport;
    $f['clock']->time=$f['clock']->time->modify('+1 day'); $again=$f['service']->claim($f['pub'],$f['secret'],7);
    $assert(!$again->claimed && $again->revision===$first->revision && $again->claimedAt==$first->claimedAt && $state===$f['gateway']->systems[1] && $history===$f['gateway']->records && $passport===$f['gateway']->passport);
    $assert($f['clock']->calls===1 && $f['users']->reads===1 && $f['gateway']->writes===1 && $f['gateway']->appends===1);
});
foreach (['inactive','deleted'] as $account) {
    $test('Ownership same-owner retry remains idempotent after account becomes '.$account,static function() use ($assert,$ownershipFixture,$account):void {
        $f=$ownershipFixture(); $first=$f['service']->claim($f['pub'],$f['secret'],7); $assert($first->claimed);
        $state=$f['gateway']->systems[1]; $history=$f['gateway']->records; $passport=$f['gateway']->passport;
        if ($account==='inactive') $f['users']->active[7]=false; else unset($f['users']->active[7]);
        $f['users']->fail=true; // Replay must not depend on the User gateway, even if it is unavailable.
        $f['gateway']->events=[]; $f['clock']->time=$f['clock']->time->modify('+1 day');
        $again=$f['service']->claim($f['pub'],$f['secret'],7);
        $assert(!$again->claimed && $again->userId===7 && $again->revision===$first->revision && $again->claimedAt===$first->claimedAt);
        $assert($state===$f['gateway']->systems[1] && $state->userId===7 && $state->updatedAt===$first->claimedAt && $history===$f['gateway']->records && $passport===$f['gateway']->passport);
        $assert($f['users']->reads===1 && $f['clock']->calls===1 && $f['gateway']->writes===1 && $f['gateway']->appends===1);
        $assert($f['gateway']->events===['begin','identify','lock-system','lock-passport','history','commit']);
    });
}
$test('Ownership same-owner retry remains idempotent after System lifecycle becomes CANCELLED',static function() use ($assert,$ownershipFixture):void {
    $f=$ownershipFixture(); $first=$f['service']->claim($f['pub'],$f['secret'],7); $s=$f['gateway']->systems[1];
    $f['gateway']->systems[1]=new OwnershipSystemState(1,$s->systemId,7,'CANCELLED',$s->createdAt,$s->updatedAt);
    $state=$f['gateway']->systems[1]; $history=$f['gateway']->records; $passport=$f['gateway']->passport; $f['users']->fail=true;
    $again=$f['service']->claim($f['pub'],$f['secret'],7);
    $assert(!$again->claimed && $again->revision===$first->revision && $again->claimedAt===$first->claimedAt && $again->userId===7);
    $assert($state===$f['gateway']->systems[1] && $state->updatedAt===$first->claimedAt && $history===$f['gateway']->records && $passport===$f['gateway']->passport);
    $assert($f['users']->reads===1 && $f['clock']->calls===1 && $f['gateway']->writes===1 && $f['gateway']->appends===1);
});
foreach ([6,9] as $targetUser) {
    $test('Ownership existing-owner conflict precedes first-claim eligibility for target '.$targetUser,static function() use ($assert,$ownershipFixture,$ownershipError,$targetUser):void {
        $f=$ownershipFixture(); $f['service']->claim($f['pub'],$f['secret'],7); $s=$f['gateway']->systems[1];
        $f['gateway']->systems[1]=new OwnershipSystemState(1,$s->systemId,7,'CANCELLED',$s->createdAt,$s->updatedAt);
        $f['users']->active[7]=false; $f['users']->fail=true;
        $before=[$f['gateway']->systems,$f['gateway']->records,$f['gateway']->passport,$f['gateway']->writes,$f['gateway']->appends];
        $ownershipError(static fn()=>$f['service']->claim($f['pub'],$f['secret'],$targetUser),'system_ownership_already_claimed');
        $assert($before===[$f['gateway']->systems,$f['gateway']->records,$f['gateway']->passport,$f['gateway']->writes,$f['gateway']->appends]);
        $assert($f['users']->reads===1 && $f['clock']->calls===1);
    });
}
foreach (['inactive','deleted','cancelled'] as $state) {
    $test('Ownership wrong secret discloses no ownership state after '.$state,static function() use ($assert,$ownershipFixture,$ownershipError,$state):void {
        $f=$ownershipFixture(); $f['service']->claim($f['pub'],$f['secret'],7); $s=$f['gateway']->systems[1];
        if ($state==='inactive') $f['users']->active[7]=false;
        if ($state==='deleted') unset($f['users']->active[7]);
        if ($state==='cancelled') $f['gateway']->systems[1]=new OwnershipSystemState(1,$s->systemId,7,'CANCELLED',$s->createdAt,$s->updatedAt);
        $f['users']->fail=true; $f['gateway']->events=[];
        $before=[$f['gateway']->systems,$f['gateway']->records,$f['gateway']->passport,$f['gateway']->writes,$f['gateway']->appends];
        $ownershipError(static fn()=>$f['service']->claim($f['pub'],str_repeat('c',32),7),'system_ownership_credentials_invalid');
        $assert($before===[$f['gateway']->systems,$f['gateway']->records,$f['gateway']->passport,$f['gateway']->writes,$f['gateway']->appends]);
        $assert($f['users']->reads===1 && $f['clock']->calls===1 && $f['gateway']->events===['begin','identify','lock-system','lock-passport','rollback']);
    });
}
foreach (['prepared','wrong-parent','wrong-public-id','corrupt-hash','missing-handoff','unknown-state'] as $defect) {
    $test('Ownership established owner does not bypass '.$defect.' Passport validation',static function() use ($assert,$ownershipFixture,$ownershipError,$defect):void {
        $f=$ownershipFixture(); $f['service']->claim($f['pub'],$f['secret'],7); $p=$f['gateway']->passport;
        $f['gateway']->passport=new \KK\Korsac\System\PassportRecord(
            $defect==='wrong-parent' ? 2 : 1,$defect==='wrong-public-id' ? str_repeat('d',32) : $p->publicId,
            $defect==='corrupt-hash' ? 'invalid' : $p->secretCodeHash,$defect==='prepared' ? 'PREPARED' : ($defect==='unknown-state' ? 'EXPIRED' : 'ACTIVE'),
            in_array($defect,['prepared','missing-handoff'],true) ? null : $p->transferredAt,$defect==='prepared' ? null : $p->warrantyStartedAt,$p->preparedAt,$p->updatedAt,
        );
        $f['users']->fail=true;
        $before=[$f['gateway']->systems,$f['gateway']->records,$f['gateway']->passport,$f['gateway']->writes,$f['gateway']->appends];
        foreach ([7,8] as $user) $ownershipError(static fn()=>$f['service']->claim($f['pub'],$f['secret'],$user),'system_ownership_state_invalid');
        $assert($before===[$f['gateway']->systems,$f['gateway']->records,$f['gateway']->passport,$f['gateway']->writes,$f['gateway']->appends]);
        $assert($f['users']->reads===1 && $f['clock']->calls===1);
    });
}
$test('Ownership claim clock cannot precede System creation or physical handoff',static function() use ($assert,$ownershipFixture,$ownershipError):void {
    foreach (['-1 second','+30 minutes'] as $offset) {
        $f=$ownershipFixture(); $f['clock']->time=$f['origin']->modify($offset);
        $ownershipError(static fn()=>$f['service']->claim($f['pub'],$f['secret'],7),'system_ownership_state_invalid');
        $assert($f['gateway']->writes===0 && $f['gateway']->records===[] && $f['gateway']->systems[1]->userId===null);
    }
});
foreach (['unowned','same-owner','other-owner'] as $state) {
    $test('Ownership wrong secret remains generic for '.$state,static function() use ($assert,$ownershipFixture,$ownershipError,$state):void {
        $f=$ownershipFixture(); if ($state!=='unowned') $f['service']->claim($f['pub'],$f['secret'],$state==='same-owner' ? 7 : 8);
        $before=[$f['gateway']->systems,$f['gateway']->records,$f['gateway']->writes,$f['gateway']->appends];
        $ownershipError(static fn()=>$f['service']->claim($f['pub'],str_repeat('c',32),7),'system_ownership_credentials_invalid');
        $assert($before===[$f['gateway']->systems,$f['gateway']->records,$f['gateway']->writes,$f['gateway']->appends]);
    });
}
$test('Ownership malformed and unknown credentials share one safe error without mutation',static function() use ($assert,$ownershipFixture,$ownershipError):void {
    $f=$ownershipFixture();
    foreach ([['bad',$f['secret']],[$f['pub'],'bad'],[str_repeat('A',32),$f['secret']],[$f['pub'],str_repeat('B',32)],[str_repeat('f',32),$f['secret']]] as [$pub,$secret]) $ownershipError(static fn()=>$f['service']->claim($pub,$secret,7),'system_ownership_credentials_invalid');
    $assert($f['gateway']->writes===0 && $f['gateway']->records===[] && $f['users']->reads===0);
});
foreach ([0,-1,2147483648,4294967295,9,6] as $user) {
    $test('Ownership rejects invalid missing or inactive user '.$user,static function() use ($assert,$ownershipFixture,$ownershipError,$user):void {
        $f=$ownershipFixture(); $ownershipError(static fn()=>$f['service']->claim($f['pub'],$f['secret'],$user),'system_ownership_user_invalid');
        $assert($f['gateway']->writes===0 && $f['gateway']->records===[]);
    });
}
$test('Ownership accepts signed INT32 max for both target User and parent identity',static function() use ($assert,$ownershipFixture):void {
    $f=$ownershipFixture(); $f['gateway']->identifiedId=2147483647; $old=$f['gateway']->systems[1];
    $f['gateway']->systems[2147483647]=new OwnershipSystemState(2147483647,$old->systemId,null,'ACTIVE',$old->createdAt,$old->updatedAt);
    $p=$f['gateway']->passport; $f['gateway']->passport=new \KK\Korsac\System\PassportRecord(2147483647,$p->publicId,$p->secretCodeHash,$p->state,$p->transferredAt,$p->warrantyStartedAt,$p->preparedAt,$p->updatedAt);
    $result=$f['service']->claim($f['pub'],$f['secret'],2147483647); $assert($result->userId===2147483647 && $result->systemInternalId===2147483647);
});
$test('Ownership correct credential cannot claim PREPARED Passport or inactive physical System',static function() use ($assert,$ownershipFixture,$ownershipError):void {
    foreach (['passport','system'] as $kind) {
        $f=$ownershipFixture(); $p=$f['gateway']->passport; $s=$f['gateway']->systems[1];
        if ($kind==='passport') $f['gateway']->passport=new \KK\Korsac\System\PassportRecord(1,$p->publicId,$p->secretCodeHash,'PREPARED',null,null,$p->preparedAt,$p->preparedAt);
        else $f['gateway']->systems[1]=new OwnershipSystemState(1,$s->systemId,null,'CANCELLED',$s->createdAt,$s->updatedAt);
        $ownershipError(static fn()=>$f['service']->claim($f['pub'],$f['secret'],7),'system_ownership_not_claimable');
        $ownershipError(static fn()=>$f['service']->claim($f['pub'],str_repeat('c',32),7),'system_ownership_credentials_invalid');
        $assert($f['gateway']->writes===0 && $f['gateway']->records===[]);
    }
});
foreach (['missing-system','missing-passport','wrong-parent','wrong-public-id','corrupt-hash','unknown-passport-state'] as $defect) {
    $test('Ownership locked '.$defect.' state fails safely without mutation',static function() use ($assert,$ownershipFixture,$ownershipError,$defect):void {
        $f=$ownershipFixture(); $p=$f['gateway']->passport;
        if ($defect==='missing-system') $f['gateway']->systems=[];
        if ($defect==='missing-passport') $f['gateway']->passport=null;
        if (!str_starts_with($defect,'missing-')) $f['gateway']->passport=new \KK\Korsac\System\PassportRecord($defect==='wrong-parent' ? 2 : 1,$defect==='wrong-public-id' ? str_repeat('d',32) : $p->publicId,$defect==='corrupt-hash' ? 'invalid' : $p->secretCodeHash,$defect==='unknown-passport-state' ? 'EXPIRED' : $p->state,$p->transferredAt,$p->warrantyStartedAt,$p->preparedAt,$p->updatedAt);
        $ownershipError(static fn()=>$f['service']->claim($f['pub'],$f['secret'],7),'system_ownership_state_invalid'); $assert($f['gateway']->writes===0 && $f['gateway']->records===[]);
    });
}
foreach (['owner-without-history','history-without-owner','wrong-latest-owner','wrong-event','wrong-source','wrong-actor','wrong-parent'] as $defect) {
    $test('Ownership current/history '.$defect.' is rejected instead of repaired',static function() use ($assert,$ownershipFixture,$ownershipError,$defect):void {
        $f=$ownershipFixture(); $s=$f['gateway']->systems[1];
        $f['gateway']->systems[1]=new OwnershipSystemState(1,$s->systemId,$defect==='history-without-owner' ? null : 7,'CANCELLED',$s->createdAt,$s->updatedAt);
        $f['users']->active[7]=false;
        if ($defect!=='owner-without-history') $f['gateway']->records=[new OwnershipHistoryRecord(1,$defect==='wrong-parent' ? 2 : 1,1,null,$defect==='wrong-latest-owner' ? 8 : 7,$defect==='wrong-event' ? 'TRANSFERRED' : 'CLAIMED',$defect==='wrong-source' ? 'MANUAL' : 'SECRET_CLAIM',$defect==='wrong-actor' || $defect==='wrong-latest-owner' ? 8 : 7,$f['origin'])];
        $before=[$f['gateway']->systems,$f['gateway']->records];
        $ownershipError(static fn()=>$f['service']->claim($f['pub'],$f['secret'],7),'system_ownership_state_invalid');
        $ownershipError(static fn()=>(new OwnershipReadService($f['gateway']))->current(1),'system_ownership_state_invalid');
        $assert($before===[$f['gateway']->systems,$f['gateway']->records] && $f['gateway']->writes===0);
    });
}
$test('Ownership competing active users have exactly one winner in either serialized order',static function() use ($assert,$ownershipFixture,$ownershipError):void {
    foreach ([[7,8],[8,7]] as [$winner,$loser]) {
        $f=$ownershipFixture(); $assert($f['service']->claim($f['pub'],$f['secret'],$winner)->claimed);
        $ownershipError(static fn()=>$f['service']->claim($f['pub'],$f['secret'],$loser),'system_ownership_already_claimed');
        $assert($f['gateway']->systems[1]->userId===$winner && count($f['gateway']->records)===1 && $f['gateway']->writes===1 && $f['gateway']->records[0]->revision===1);
    }
});
$test('Ownership reads a race winner under the parent lock and preserves its committed state',static function() use ($assert,$ownershipFixture,$ownershipError):void {
    foreach ([7,8] as $requestUser) {
        $f=$ownershipFixture(); $f['gateway']->raceWinner=7;
        if ($requestUser===7) $assert(!$f['service']->claim($f['pub'],$f['secret'],7)->claimed);
        else $ownershipError(static fn()=>$f['service']->claim($f['pub'],$f['secret'],8),'system_ownership_already_claimed');
        $assert($f['gateway']->systems[1]->userId===7 && count($f['gateway']->records)===1 && $f['gateway']->writes===0 && $f['gateway']->appends===0 && $f['clock']->calls===0);
    }
});
foreach (['failUpdate','failHistory','failCommit'] as $failure) {
    $test('Ownership '.$failure.' rolls back owner and audit together without private diagnostics',static function() use ($assert,$ownershipFixture,$ownershipError,$failure):void {
        $f=$ownershipFixture(); $state=$f['gateway']->systems[1]; $f['gateway']->{$failure}=true;
        $ownershipError(static fn()=>$f['service']->claim($f['pub'],$f['secret'],7),'system_ownership_persist_failed');
        $assert($f['gateway']->systems[1]===$state && $f['gateway']->records===[] && end($f['gateway']->events)==='rollback');
    });
}
foreach (['identify','lock-system','lock-passport','history','user'] as $failure) {
    $test('Ownership '.$failure.' infrastructure failure is a sanitized read error',static function() use ($assert,$ownershipFixture,$ownershipError,$failure):void {
        $f=$ownershipFixture(); if ($failure==='user') $f['users']->fail=true; else $f['gateway']->failRead=$failure;
        $ownershipError(static fn()=>$f['service']->claim($f['pub'],$f['secret'],7),'system_ownership_read_failed'); $assert($f['gateway']->writes===0 && $f['gateway']->records===[]);
    });
}
$test('Ownership reads and same-owner retry preserve later-inactive accounts without writing anything',static function() use ($assert,$ownershipFixture):void {
    $f=$ownershipFixture(); $reads=new OwnershipReadService($f['gateway']); $assert($reads->current(1)->userId===null && $reads->history(1)===[]);
    $f['service']->claim($f['pub'],$f['secret'],7); $before=[$f['gateway']->systems,$f['gateway']->records,$f['gateway']->writes,$f['gateway']->appends]; $f['users']->active[7]=false;
    $assert($reads->current(1)->userId===7 && $reads->current(1)->revision===1 && count($reads->history(1))===1);
    $again=$f['service']->claim($f['pub'],$f['secret'],7); $assert(!$again->claimed && $again->revision===1 && $again->userId===7);
    $assert($before===[$f['gateway']->systems,$f['gateway']->records,$f['gateway']->writes,$f['gateway']->appends]);
});
$test('Ownership revision allocation is signed bounded and never wraps or resets',static function() use ($assert,$ownershipFixture,$ownershipError):void {
    $f=$ownershipFixture(); $assert(OwnershipRevision::next(null)===1);
    $r=new OwnershipHistoryRecord(1,1,2147483646,null,7,'CLAIMED','SECRET_CLAIM',7,$f['origin']); $assert(OwnershipRevision::next($r)===2147483647);
    $r=new OwnershipHistoryRecord(1,1,2147483647,null,7,'CLAIMED','SECRET_CLAIM',7,$f['origin']); $ownershipError(static fn()=>OwnershipRevision::next($r),'system_ownership_revision_exhausted');
    foreach ([0,2147483648,4294967295] as $revision) { $r=new OwnershipHistoryRecord(1,1,$revision,null,7,'CLAIMED','SECRET_CLAIM',7,$f['origin']); $ownershipError(static fn()=>OwnershipRevision::next($r),'system_ownership_state_invalid'); }
});
$test('Ownership ordinary USER_ID updates and history updates/deletes are blocked',static function() use ($assert,$ownershipError):void {
    require_once __DIR__.'/fixtures/system_table_parent.php'; \Bitrix\Main\Entity\DataManager::$updates=[];
    foreach ([null,7,2147483647,'replacement'] as $value) {
        $ownershipError(static fn()=>\KK\Korsac\System\SystemTable::update(1,['USER_ID'=>$value]),'system_source_immutable');
        $ownershipError(static fn()=>\KK\Korsac\System\SystemTable::update(1,['LIFECYCLE_STATE'=>'ACTIVE','USER_ID'=>$value]),'system_source_immutable');
    }
    $ownershipError(static fn()=>\KK\Korsac\Ownership\OwnershipHistoryTable::update(1,['TO_USER_ID'=>8]),'system_ownership_history_immutable');
    $ownershipError(static fn()=>\KK\Korsac\Ownership\OwnershipHistoryTable::delete(1),'system_ownership_history_immutable');
    $assert(\Bitrix\Main\Entity\DataManager::$updates===[]);
});
$test('Ownership real gateway rejects owner/history writes outside a locked transaction',static function() use ($ownershipError,$ownershipFixture):void {
    $f=$ownershipFixture(); $gateway=new \KK\Korsac\Ownership\BitrixOwnershipGateway();
    $ownershipError(static fn()=>$gateway->claimUnownedSystem(1,7,$f['origin']),'system_ownership_persist_failed');
    $ownershipError(static fn()=>$gateway->appendClaim(new OwnershipHistoryRecord(0,1,1,null,7,'CLAIMED','SECRET_CLAIM',7,$f['origin'])),'system_ownership_persist_failed');
});
$test('Ownership ordinary System ORM cannot create a pre-owned physical record',static function() use ($ownershipError):void {
    foreach ([7,2147483647,0,'7'] as $user) $ownershipError(static fn()=>\KK\Korsac\System\SystemTable::add(['USER_ID'=>$user]),'system_source_immutable');
});
$test('Ownership real transaction refuses to commit an owner assignment without its audit',static function() use ($assert,$ownershipError):void {
    $connection=new class {
        public ?int $owner=null; public int $commits=0,$rollbacks=0; public array $sql=[];
        public function startTransaction():void {}
        public function commitTransaction():void { ++$this->commits; }
        public function rollbackTransaction():void { $this->owner=null; ++$this->rollbacks; }
        public function query(string $sql):object {
            $this->sql[]=$sql;
            $row=str_contains($sql,'ownership_history') ? false : ['ID'=>1,'SYSTEM_ID'=>'KS-2610-0006','USER_ID'=>null,'LIFECYCLE_STATE'=>'ACTIVE','CREATED_AT'=>'2026-10-13 12:00:00','UPDATED_AT'=>'2026-10-13 12:00:00'];
            return new class($row) { public function __construct(private array|false $row) {} public function fetch():array|false { return $this->row; } };
        }
        public function getSqlHelper():object { return new class { public function forSql(string $value):string { return $value; } }; }
        public function queryExecute(string $sql):void { $this->sql[]=$sql; $this->owner=7; }
        public function getAffectedRowsCount():int { return 1; }
    };
    $gateway=new \KK\Korsac\Ownership\BitrixOwnershipGateway(static fn()=>$connection);
    $ownershipError(static fn()=>$gateway->transaction(static function() use ($gateway):void {
        $gateway->lockSystem(1); $gateway->latestHistory(1); $gateway->claimUnownedSystem(1,7,new DateTimeImmutable('2026-10-14 12:00:00'));
    }),'system_ownership_persist_failed');
    $assert($connection->owner===null && $connection->commits===0 && $connection->rollbacks===1);
    $assert(str_contains($connection->sql[0],'FOR UPDATE') && str_contains($connection->sql[1],'ORDER BY `REVISION` DESC') && str_contains($connection->sql[1],'FOR UPDATE'));
    $assert(str_contains($connection->sql[2],'`USER_ID` IS NULL') && str_contains($connection->sql[2],"`LIFECYCLE_STATE`='ACTIVE'"));
});
$test('Ownership verification uses locked hash_equals and redacts the native secret argument',static function() use ($assert):void {
    $source=file_get_contents(dirname(__DIR__,2).'/lib/Ownership/OwnershipService.php');
    $assert(str_contains($source,"hash_equals(\$passport->secretCodeHash,hash('sha256',\$secretCode))") && !str_contains($source,'verifySecret('));
    $parameter=(new ReflectionMethod(OwnershipService::class,'claim'))->getParameters()[1]; $assert(count($parameter->getAttributes(SensitiveParameter::class))===1);
});

$ownershipSchemaGateway=static fn()=>new class implements \KK\Korsac\Install\OwnershipTableGatewayInterface {
    public array $tables=[],$events=[]; public int $writes=0,$legacy=0;
    public function legacyOwnerCount():int { $this->events[]='legacy-preflight'; return $this->legacy; }
    public function describe(string $table):?array { $this->events[]='describe'; return $this->tables[$table] ?? null; }
    public function createTable(string $table):void { $definition=OwnershipSchemaDefinition::tables()[$table]; foreach ($definition['columns'] as &$column) if (isset($column['size'])) $column['collation']='utf8mb4_unicode_ci'; $this->tables[$table]=['engine'=>'InnoDB','columns'=>$definition['columns'],'indexes'=>['PRIMARY'=>$definition['indexes']['PRIMARY']]]; ++$this->writes; }
    public function createIndex(string $table,string $name,array $index):void { $this->tables[$table]['indexes'][$name]=$index; ++$this->writes; }
};
$test('Ownership migration 009 is fresh/upgrade idempotent and allows legitimate owners after its marker',static function() use ($assert,$ownershipSchemaGateway,$fakeStore):void {
    foreach ([[],['2026_10_13_008_system_passport_core']] as $history) {
        $gateway=$ownershipSchemaGateway(); $store=$fakeStore($history); $migration=new \KK\Korsac\Install\OwnershipMigrationService($store,$gateway);
        $assert($migration->migrate()===['2026_10_14_009_system_ownership_core'] && (new OwnershipSchemaSelfCheck($gateway))->run()['ok']);
        $gateway->legacy=1; $before=$gateway->writes; $assert($migration->migrate()===[] && $gateway->writes===$before);
        foreach ($history as $id) $assert($store->has($id));
    }
});
$test('Ownership migration rejects pre-009 owner assignments before all ownership DDL or history activation',static function() use ($assert,$ownershipSchemaGateway,$fakeStore):void {
    $gateway=$ownershipSchemaGateway(); $gateway->legacy=3; $store=$fakeStore(['2026_10_13_008_system_passport_core']);
    try { (new \KK\Korsac\Install\OwnershipMigrationService($store,$gateway))->migrate(); }
    catch (\KK\Korsac\Install\OwnershipLegacyAssignmentsException $error) {
        $assert($error->diagnostic()===['code'=>'ownership_legacy_user_assignments_present','count'=>3] && $gateway->events===['legacy-preflight'] && $gateway->writes===0 && $gateway->tables===[] && !$store->has(SystemOwnershipCore::ID)); return;
    }
    throw new RuntimeException('Expected legacy ownership preflight failure');
});
$test('Ownership schema is signed INT with exact full unique revision and safe nullable audit identities',static function() use ($assert):void {
    $d=OwnershipSchemaDefinition::tables()[OwnershipSchemaDefinition::HISTORY];
    foreach (['ID','SYSTEM_INTERNAL_ID','REVISION','FROM_USER_ID','TO_USER_ID','ACTOR_USER_ID'] as $field) $assert($d['columns'][$field]['type']==='int');
    foreach (['FROM_USER_ID','TO_USER_ID','ACTOR_USER_ID'] as $field) $assert($d['columns'][$field]['nullable']);
    $assert($d['indexes']['ux_kk_korsac_ownership_history_revision']===['columns'=>['SYSTEM_INTERNAL_ID','REVISION'],'unique'=>true]);
    $sql=OwnershipSchemaDefinition::createSql(OwnershipSchemaDefinition::HISTORY); $assert(str_contains($sql,'`ID` INT NOT NULL AUTO_INCREMENT') && !str_contains($sql,'UNSIGNED') && !str_contains($sql,'FOREIGN KEY') && !str_contains($sql,'SECRET'));
});
$test('Ownership health rejects missing schema and compatible missing secondary index is repaired',static function() use ($assert,$ownershipSchemaGateway):void {
    $gateway=$ownershipSchemaGateway(); $assert(!(new OwnershipSchemaSelfCheck($gateway))->run()['ok']); $migration=new SystemOwnershipCore($gateway); $migration->up(); $before=$gateway->writes;
    unset($gateway->tables[OwnershipSchemaDefinition::HISTORY]['indexes']['ix_kk_korsac_ownership_history_actor']); $assert(!(new OwnershipSchemaSelfCheck($gateway))->run()['ok']);
    $migration->up(); $assert($gateway->writes===$before+1 && (new OwnershipSchemaSelfCheck($gateway))->run()['ok']);
});
foreach (['unsigned','nullable','unique','prefix','engine','column-missing','primary'] as $defect) {
    $test('Ownership incompatible '.$defect.' schema fails before any DDL',static function() use ($assert,$ownershipSchemaGateway,$systemError,$defect):void {
        $gateway=$ownershipSchemaGateway(); $migration=new SystemOwnershipCore($gateway); $migration->up(); $table=&$gateway->tables[OwnershipSchemaDefinition::HISTORY];
        if ($defect==='unsigned') $table['columns']['SYSTEM_INTERNAL_ID']['type']='int unsigned';
        if ($defect==='nullable') $table['columns']['TO_USER_ID']['nullable']=false;
        if ($defect==='unique') $table['indexes']['ux_kk_korsac_ownership_history_revision']['unique']=false;
        if ($defect==='prefix') $table['indexes']['ux_kk_korsac_ownership_history_revision']['partial']=true;
        if ($defect==='engine') $table['engine']='MyISAM';
        if ($defect==='column-missing') unset($table['columns']['EVENT_CODE']);
        if ($defect==='primary') unset($table['indexes']['PRIMARY']);
        $before=$gateway->writes; $assert(!(new OwnershipSchemaSelfCheck($gateway))->run()['ok']);
        $systemError(static fn()=>$migration->up(),'ownership_schema_incompatible'); $assert($gateway->writes===$before);
    });
}
$test('Ownership uninstall retains current association and audit storage without business deletion',static function() use ($assert):void {
    $source=file_get_contents(dirname(__DIR__,2).'/install/index.php'); $body=explode('public function DoUninstall(): void',$source)[1]; $body=explode('public function InstallFiles()',$body)[0];
    $assert(str_contains($body,'System.USER_ID/ownership history') && !str_contains($body,'DROP') && !str_contains($body,'OwnershipHistoryTable'));
});
