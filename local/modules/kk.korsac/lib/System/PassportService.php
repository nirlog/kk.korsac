<?php

declare(strict_types=1);

namespace KK\Korsac\System;
use Closure;
use DateTimeImmutable;
use Throwable;
final class PassportService
{
    public const MAX_CREDENTIAL_ATTEMPTS=5;
    public function __construct(private readonly PassportRepositoryInterface $passports,private readonly PassportCredentialGeneratorInterface $credentials,private readonly ?Closure $clock=null) {}
    public function preparePassport(int $systemInternalId): PassportPreparationResult
    {
        self::validateId($systemInternalId);
        try {
            return $this->passports->transaction(function() use ($systemInternalId): PassportPreparationResult {
                $this->activeSystem($systemInternalId);
                $existing=$this->passports->lockBySystem($systemInternalId);
                if ($existing!==null) { $existing->assertValid(); return new PassportPreparationResult($existing,false,null); }
                $now=PassportTimestamp::seconds($this->now());
                for ($attempt=0;$attempt<self::MAX_CREDENTIAL_ATTEMPTS;++$attempt) {
                    try {
                        $publicId=$this->credentials->generatePublicId(); $secret=$this->credentials->generateSecretCode();
                        if (!PassportCredentialShape::token($publicId) || !PassportCredentialShape::token($secret)) throw new SystemException('system_passport_token_generation_failed');
                    } catch (Throwable) { throw new SystemException('system_passport_token_generation_failed'); }
                    // Independent randomness may theoretically coincide; never publish the private value as PUBLIC_ID.
                    if (hash_equals($publicId,$secret)) continue;
                    $record=new PassportRecord($systemInternalId,$publicId,hash('sha256',$secret),'PREPARED',null,null,$now,$now);
                    try { $stored=$this->passports->create($record); return new PassportPreparationResult($stored,true,$secret); }
                    catch (PassportCredentialCollision) { /* Parent lock/transaction remain held while both values are regenerated. */ }
                }
                throw new SystemException('system_passport_token_collision');
            });
        } catch (SystemException $error) { throw $error; }
        catch (Throwable) { throw new SystemException('system_passport_persist_failed'); }
    }
    public function activatePassport(int $systemInternalId,?DateTimeImmutable $transferredAt=null): PassportRecord
    {
        self::validateId($systemInternalId);
        try {
            return $this->passports->transaction(function() use ($systemInternalId,$transferredAt): PassportRecord {
                $system=$this->activeSystem($systemInternalId); $passport=$this->passports->lockBySystem($systemInternalId);
                if ($passport===null) throw new SystemException('system_passport_not_found');
                $passport->assertValid();
                if ($passport->state==='ACTIVE') {
                    if ($transferredAt!==null && $transferredAt!=$passport->transferredAt) throw new SystemException('system_passport_activation_conflict');
                    return $passport; // No clock/generator call, no timestamp rewrite.
                }
                $clock=$this->now();
                if ($transferredAt!==null && $transferredAt->format('u')!=='000000') throw new SystemException('system_passport_transfer_time_invalid');
                $transfer=$transferredAt===null ? PassportTimestamp::seconds($clock) : $transferredAt->setTimezone($clock->getTimezone());
                if ($transfer<$system->createdAt || $transfer>$clock) throw new SystemException('system_passport_transfer_time_invalid');
                return $this->passports->activate($systemInternalId,$transfer,PassportTimestamp::seconds($clock));
            });
        } catch (SystemException $error) { throw $error; }
        catch (Throwable) { throw new SystemException('system_passport_persist_failed'); }
    }
    /** Fail closed; this boolean grants no ownership or other authority. No logs or mutation. */
    public function verifySecret(string $publicId,string $secretCode): bool
    {
        if (!PassportCredentialShape::token($publicId) || !PassportCredentialShape::token($secretCode)) return false;
        try {
            $passport=$this->passports->findByPublicId($publicId);
            if ($passport===null || $passport->publicId!==$publicId) return false;
            $passport->assertValid();
            return hash_equals($passport->secretCodeHash,hash('sha256',$secretCode));
        } catch (Throwable) { return false; }
    }
    private function activeSystem(int $id): PassportSystemState
    {
        $system=$this->passports->lockSystem($id);
        if ($system->systemInternalId!==$id) throw new SystemException('system_not_found');
        if ($system->lifecycleState!=='ACTIVE') throw new SystemException('system_passport_system_inactive');
        return $system;
    }
    private function now(): DateTimeImmutable { return $this->clock!==null ? ($this->clock)() : new DateTimeImmutable('now'); }
    private static function validateId(int $id): void { if ($id<1 || $id>2147483647) throw new SystemException('system_source_invalid'); }
}
