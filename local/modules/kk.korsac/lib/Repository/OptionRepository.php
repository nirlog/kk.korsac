<?php

declare(strict_types=1);

namespace KK\Korsac\Repository;

use Bitrix\Highloadblock\HighloadBlockTable;
use RuntimeException;

final class OptionRepository
{
    public function findByTypeAndXmlId(string $type, string $xmlId): ?array
    {
        $blockName = OptionTypeRegistry::blockName($type);
        $block = HighloadBlockTable::getList(['filter' => ['=NAME' => $blockName], 'limit' => 1])->fetch();
        if (!$block) {
            throw new RuntimeException("HL block {$blockName} is not installed");
        }
        $dataClass = HighloadBlockTable::compileEntity($block)->getDataClass();
        $row = $dataClass::getList(['filter' => ['=UF_XML_ID' => $xmlId], 'limit' => 1])->fetch();
        return $row ?: null;
    }
}
