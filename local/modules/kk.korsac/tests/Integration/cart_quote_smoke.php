<?php

declare(strict_types=1);

use Bitrix\Main\Loader;
use KK\Korsac\Cart\BasketPropertyProjector;
use KK\Korsac\Cart\ConfigurationSnapshotBuilder;
use KK\Korsac\Catalog\BitrixCatalogPropertyGateway;
use KK\Korsac\Catalog\ProductConfigurationRepository;
use KK\Korsac\Configurator\BitrixCatalogPriceProvider;
use KK\Korsac\Configurator\ConfiguredProductPricingService;
use KK\Korsac\Configurator\HlOptionViewProvider;
use KK\Korsac\Pricing\BitrixPricingPolicyProvider;
use KK\Korsac\Pricing\ConfiguredCatalogPriceTypeResolver;
use KK\Korsac\Pricing\HlOptionPriceProvider;
use KK\Korsac\Repository\OptionRepository;

$args=getopt('',['iblock:','product:','selection-json::']);
$iblockId=filter_var($args['iblock']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
$productId=filter_var($args['product']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
if($iblockId===false||$productId===false){fwrite(STDERR,"Usage: php cart_quote_smoke.php --iblock=<ID> --product=<ID> [--selection-json='<JSON>']\n");exit(2);}
try{$selection=json_decode((string)($args['selection-json']??'{}'),true,512,JSON_THROW_ON_ERROR);if(!is_array($selection)||(array_is_list($selection)&&$selection!==[]))throw new InvalidArgumentException('Selection must be an object');}catch(Throwable $e){fwrite(STDERR,"Invalid selection: {$e->getMessage()}\n");exit(2);}
$documentRoot=($_SERVER['DOCUMENT_ROOT']??'')?:dirname(__DIR__,5);$_SERVER['DOCUMENT_ROOT']=$documentRoot;
require $documentRoot.'/bitrix/modules/main/include/prolog_before.php';
foreach(['iblock','highloadblock','catalog','kk.korsac'] as $module)if(!Loader::includeModule($module)){fwrite(STDERR,"Required module {$module} is unavailable.\n");exit(2);}
try{
 $options=new OptionRepository();$views=new HlOptionViewProvider($options);
 $pricing=new ConfiguredProductPricingService(new ProductConfigurationRepository(new BitrixCatalogPropertyGateway(),$options),new BitrixCatalogPriceProvider(),new HlOptionPriceProvider($options),new ConfiguredCatalogPriceTypeResolver(),new BitrixPricingPolicyProvider());
 $quote=$pricing->quote((int)$iblockId,(int)$productId,$selection);$snapshot=(new ConfigurationSnapshotBuilder($views))->build($quote,defined('SITE_ID')?(string)SITE_ID:'smoke');
 echo json_encode(['selection'=>$quote->selection->toArray(),'price'=>$quote->publicPrice(),'snapshot'=>['schemaVersion'=>1,'hash'=>$snapshot->hash],'basketPropertyCodes'=>array_column((new BasketPropertyProjector())->project($snapshot),'CODE')],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),PHP_EOL;
}catch(Throwable $e){fwrite(STDERR,"Cart quote smoke failed: {$e->getMessage()}\n");exit(1);}
