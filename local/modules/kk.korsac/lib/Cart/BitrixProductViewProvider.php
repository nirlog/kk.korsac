<?php
declare(strict_types=1);
namespace KK\Korsac\Cart;
use Bitrix\Iblock\ElementTable;
final class BitrixProductViewProvider implements ProductViewProviderInterface
{
    public function name(int $iblockId, int $productId): string
    {
        $row=ElementTable::getList(['select'=>['NAME'],'filter'=>['=ID'=>$productId,'=IBLOCK_ID'=>$iblockId],'limit'=>1])->fetch();
        $name=trim((string)($row['NAME']??''));
        if ($name==='') throw new CartException(['code'=>'product_not_found']);
        return $name;
    }
}
