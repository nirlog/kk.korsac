<?php

declare(strict_types=1);

namespace KK\Korsac\Component;

use KK\Korsac\System\SystemException;
use KK\Korsac\System\SystemComponent;

/** NAME and SLOT_LABEL are public. Serial is private, case-sensitive physical data. */
final readonly class ComponentSpec
{
    public function __construct(public string $typeCode,public string $slotKey,public string $slotLabel,public string $name,#[\SensitiveParameter] public ?string $serialNumber=null)
    {
        self::assertCode($typeCode,32); self::assertCode($slotKey,64);
        foreach ([$slotLabel,$name,$serialNumber] as $text) {
            if ($text!==null && (!mb_check_encoding($text,'UTF-8') || mb_strlen($text,'UTF-8')<1 || mb_strlen($text,'UTF-8')>255 || preg_match('/[\p{Cc}\p{Cf}]|\A[\s\p{Z}]|[\s\p{Z}]\z/u',$text))) throw new SystemException('system_component_invalid');
        }
    }
    public static function assertCode(string $code,int $limit): void
    {
        if (strlen($code)>$limit || !preg_match('/\A[A-Z][A-Z0-9_]*\z/D',$code)) throw new SystemException('system_component_invalid');
    }
    public function matches(SystemComponent $record): bool
    {
        return $this->typeCode===$record->typeCode && $this->slotKey===$record->slotKey && $this->slotLabel===$record->slotLabel && $this->name===$record->name && $this->serialNumber===$record->serialNumber;
    }
}
