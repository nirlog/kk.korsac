<?php

declare(strict_types=1);

namespace KK\Korsac\Ownership;

use Closure;
use DateTimeImmutable;
use KK\Korsac\System\PassportCredentialShape;
use KK\Korsac\System\PassportTimestamp;
use KK\Korsac\System\SystemException;
use Throwable;

final class OwnershipService
{
    public function __construct(private readonly OwnershipGatewayInterface $gateway,private readonly OwnershipUserGatewayInterface $users,private readonly ?Closure $clock=null) {}

    /** Future HTTP callers must obtain userId from the authenticated server-side session. */
    public function claim(string $publicId,#[\SensitiveParameter] string $secretCode,int $userId): OwnershipClaimResult
    {
        if (!PassportCredentialShape::token($publicId) || !PassportCredentialShape::token($secretCode)) throw new SystemException('system_ownership_credentials_invalid');
        if (!OwnershipIdentity::valid($userId)) throw new SystemException('system_ownership_user_invalid');
        try {
            return $this->gateway->transaction(function() use ($publicId,$secretCode,$userId): OwnershipClaimResult {
                $id=$this->gateway->identifySystem($publicId);
                if ($id===null) throw new SystemException('system_ownership_credentials_invalid');
                if (!OwnershipIdentity::valid($id)) throw new SystemException('system_ownership_state_invalid');
                $system=$this->gateway->lockSystem($id);
                if ($system===null || $system->systemInternalId!==$id) throw new SystemException('system_ownership_state_invalid');
                $passport=$this->gateway->lockPassport($id);
                if ($passport===null || $passport->systemInternalId!==$id || $passport->publicId!==$publicId) throw new SystemException('system_ownership_state_invalid');
                try { $passport->assertValid(); }
                catch (Throwable) { throw new SystemException('system_ownership_state_invalid'); }
                // Authoritative locked credential, before any owner-conflict or audit-state disclosure.
                if (!hash_equals($passport->secretCodeHash,hash('sha256',$secretCode))) throw new SystemException('system_ownership_credentials_invalid');
                $system->assertValid();
                if ($passport->state!=='ACTIVE' || $system->lifecycleState!=='ACTIVE') throw new SystemException('system_ownership_not_claimable');
                if (!$this->users->isActiveUser($userId)) throw new SystemException('system_ownership_user_invalid');
                $latest=$this->gateway->latestHistory($id);
                OwnershipConsistency::assertCurrent($system,$latest);
                if ($system->userId!==null) {
                    if ($system->userId!==$userId) throw new SystemException('system_ownership_already_claimed');
                    return new OwnershipClaimResult($id,$system->systemId,$userId,false,$latest->revision,$latest->createdAt);
                }
                $revision=OwnershipRevision::next($latest);
                $at=PassportTimestamp::seconds($this->clock!==null ? ($this->clock)() : new DateTimeImmutable('now'));
                if ($at<$system->createdAt || $at<$passport->transferredAt) throw new SystemException('system_ownership_state_invalid');
                $this->gateway->claimUnownedSystem($id,$userId,$at);
                $this->gateway->appendClaim(new OwnershipHistoryRecord(0,$id,$revision,null,$userId,'CLAIMED','SECRET_CLAIM',$userId,$at));
                return new OwnershipClaimResult($id,$system->systemId,$userId,true,$revision,$at);
            });
        } catch (SystemException $error) {
            $allowed=['system_ownership_credentials_invalid','system_ownership_not_claimable','system_ownership_already_claimed','system_ownership_user_invalid','system_ownership_state_invalid','system_ownership_revision_exhausted','system_ownership_read_failed'];
            // Recreate even known errors: no private context, previous exception or driver message escapes.
            throw new SystemException(in_array($error->machineCode,$allowed,true) ? $error->machineCode : 'system_ownership_persist_failed');
        } catch (Throwable) { throw new SystemException('system_ownership_persist_failed'); }
    }
}
