<?php

declare(strict_types=1);

use KK\Korsac\Cart\BasketGatewayInterface;
use KK\Korsac\Cart\BasketPropertyProjector;
use KK\Korsac\Cart\BasketPropertyWriter;
use KK\Korsac\Cart\BasketResult;
use KK\Korsac\Cart\CartErrorMapper;
use KK\Korsac\Cart\CartException;
use KK\Korsac\Cart\ConfigurationSnapshot;
use KK\Korsac\Cart\ConfigurationSnapshotBuilder;
use KK\Korsac\Cart\ConfigurationSnapshotReference;
use KK\Korsac\Cart\ConfigurationSnapshotRepositoryInterface;
use KK\Korsac\Cart\ConfiguredProductCartService;
use KK\Korsac\Cart\ConfiguredBasketPriceProjector;
use KK\Korsac\Cart\MinorUnitFormatter;
use KK\Korsac\Cart\ProductViewProviderInterface;
use KK\Korsac\Cart\SiteResolverInterface;
use KK\Korsac\Catalog\ProductConfiguration;
use KK\Korsac\Configurator\ConfiguredProductPricingServiceInterface;
use KK\Korsac\Configurator\ConfiguredProductQuote;
use KK\Korsac\Configurator\OptionView;
use KK\Korsac\Configurator\OptionViewProviderInterface;
use KK\Korsac\Install\Migration\ConfigurationSnapshot as SnapshotMigration;
use KK\Korsac\Install\SnapshotTableGatewayInterface;
use KK\Korsac\Pricing\ConfigurationSelection;

$cartQuote = static function (int $final=5049000, string $hdd='HDD_2TB'): ConfiguredProductQuote {
    $configuration=ProductConfiguration::fromPropertyValues([
        'KK_CPU_DEFAULT'=>['CPU_A'],'KK_HDD_OPTIONS'=>['HDD_2TB','HDD_4TB'],'KK_SOFTWARE_MULTI_OPTIONS'=>['OFFICE','AV'],
    ],static fn(string $group,string $id):array=>['UF_XML_ID'=>$id,'UF_ACTIVE'=>1]);
    $selection=ConfigurationSelection::fromArray($configuration,['HDD'=>$hdd,'SOFTWARE'=>['OFFICE','AV']]);
    $deltas=array_fill_keys(array_keys($configuration->toArray()),0); $deltas['HDD']=$hdd==='HDD_2TB'?1920000:2500000; $deltas['SOFTWARE']=30000;
    return new ConfiguredProductQuote(2,4,$configuration,$selection,2,'RUB',3099000,$final-3099000,$final,$deltas);
};
$views=new class implements OptionViewProviderInterface {
    public array $names=['CPU_A'=>'Ryzen','HDD_2TB'=>'2 TB','HDD_4TB'=>'4 TB','OFFICE'=>'Office','AV'=>'Antivirus'];
    public function get(string $group,string $xmlId):OptionView{return new OptionView($xmlId,$this->names[$xmlId],null);}
};

$test('snapshot v1 is canonical complete immutable and hashed over exact JSON',static function()use($assert,$cartQuote,$views):void{
    $snapshot=(new ConfigurationSnapshotBuilder($views))->build($cartQuote(),'s1');
    $payload=json_decode($snapshot->payload,true,512,JSON_THROW_ON_ERROR);
    $assert($payload['schemaVersion']===1 && count($payload['selection'])===12);
    $assert($payload['selection']['GPU']===null && $payload['selection']['SERVICE']===[]);
    $assert($payload['display']['HDD']['items'][0]===['xmlId'=>'HDD_2TB','name'=>'2 TB']);
    $assert(hash('sha256',$snapshot->payload)===$snapshot->hash);
    $assert(hash('sha256',$snapshot->payload.'x')!==$snapshot->hash);
    $assert(!str_contains($snapshot->payload,'UF_PRICE'));
    $views->names['HDD_2TB']='changed';
    $assert(json_decode($snapshot->payload,true)['display']['HDD']['items'][0]['name']==='2 TB');
});

$test('basket properties are deterministic machine references and individual multiple values',static function()use($assert,$cartQuote,$views):void{
    $views->names['HDD_2TB']='2 TB';
    $snapshot=(new ConfigurationSnapshotBuilder($views))->build($cartQuote(),'s1');
    $properties=(new BasketPropertyProjector())->project($snapshot); $codes=array_column($properties,'CODE');
    foreach(['KORSAC_CONFIGURED','KORSAC_SNAPSHOT_KEY','KORSAC_SNAPSHOT_HASH','KORSAC_SNAPSHOT_VERSION','KORSAC_CPU','KORSAC_HDD','KORSAC_SOFTWARE_001','KORSAC_SOFTWARE_002'] as $code)$assert(in_array($code,$codes,true),$code);
    $assert(!in_array('KORSAC_GPU',$codes,true));
    $assert(MinorUnitFormatter::decimal(5648800)==='56488.00');
});

$test('basket property writer supports Bitrix void setProperty signature',static function()use($assert):void{
    $collection=new class { public array $properties=[]; public function setProperty(array $properties):void{$this->properties=$properties;} };
    $properties=[['CODE'=>'KORSAC_CONFIGURED','VALUE'=>'Y']];
    (new BasketPropertyWriter())->write($collection,$properties);
    $assert($collection->properties===$properties);
    $source=(string)file_get_contents(dirname(__DIR__,2).'/lib/Cart/BitrixBasketGateway.php');
    $assert(!str_contains($source,'$propertyResult'));
    $assert(!str_contains($source,'setProperty($properties)->isSuccess()'));
});

$test('configured basket provider projects snapshot final price and rejects invalid authority',static function()use($assert,$cartQuote,$views):void{
    $snapshot=(new ConfigurationSnapshotBuilder($views))->build($cartQuote(5648800),'s1');
    $repository=new class($snapshot) implements ConfigurationSnapshotRepositoryInterface {
        public function __construct(public ?ConfigurationSnapshot $snapshot){}
        public function create(ConfigurationSnapshot $snapshot):ConfigurationSnapshotReference{throw new LogicException('read only');}
        public function findByKey(string $key):?ConfigurationSnapshot{return $this->snapshot !== null && $this->snapshot->key === $key ? $this->snapshot : null;}
        public function deleteUnattached(ConfigurationSnapshotReference $reference):void{throw new LogicException('read only');}
    };
    $projector=new ConfiguredBasketPriceProjector($repository);
    $assert($projector->project($snapshot->key,4,'RUB')===['BASE_PRICE'=>'56488.00','PRICE'=>'56488.00','DISCOUNT_PRICE'=>'0.00','CUSTOM_PRICE'=>'Y','CURRENCY'=>'RUB']);
    $cases=[
        [null,$snapshot->key,4,'RUB','snapshot_not_found'],
        [$snapshot,$snapshot->key,5,'RUB','snapshot_product_mismatch'],
        [$snapshot,$snapshot->key,4,'USD','snapshot_invalid'],
        [new ConfigurationSnapshot($snapshot->key,str_repeat('0',64),$snapshot->siteId,$snapshot->iblockId,$snapshot->productId,$snapshot->priceTypeId,$snapshot->currency,$snapshot->basePriceMinor,$snapshot->configurationDeltaMinor,$snapshot->finalPriceMinor,$snapshot->payload,$snapshot->display),$snapshot->key,4,'RUB','snapshot_invalid'],
    ];
    foreach($cases as [$stored,$key,$product,$currency,$code]){$repository->snapshot=$stored;try{$projector->project($key,$product,$currency);}catch(CartException $error){$assert($error->diagnostic()['code']===$code,$code);continue;}throw new RuntimeException("Projection accepted {$code}");}
});

$test('cart always obtains a fresh server quote and ignores browser pricing metadata',static function()use($assert,$cartQuote,$views):void{
    $pricing=new class($cartQuote) implements ConfiguredProductPricingServiceInterface { public int $calls=0; public function __construct(private $factory){} public function quote(int $i,int $p,array $s):ConfiguredProductQuote{++$this->calls;return ($this->factory)(5710000);} };
    $repository=new class implements ConfigurationSnapshotRepositoryInterface {public array $items=[];public function create(ConfigurationSnapshot $s):ConfigurationSnapshotReference{$this->items[$s->key]=$s;return new ConfigurationSnapshotReference(count($this->items),$s->key);}public function findByKey(string $k):?ConfigurationSnapshot{return $this->items[$k]??null;}public function deleteUnattached(ConfigurationSnapshotReference $r):void{unset($this->items[$r->key]);}};
    $basket=new class implements BasketGatewayInterface {public array $writes=[];public function addConfiguredProduct(ConfiguredProductQuote $q,string $site,string $name,array $properties):BasketResult{$this->writes[]=['price'=>$q->finalPriceMinor,'customPrice'=>'Y','site'=>$site,'properties'=>$properties];return new BasketResult(count($this->writes));}};
    $service=new ConfiguredProductCartService($pricing,new ConfigurationSnapshotBuilder($views),$repository,$basket,new BasketPropertyProjector(),new class implements SiteResolverInterface{public function currentSiteId():string{return's1';}},new class implements ProductViewProviderInterface{public function name(int $i,int $p):string{return'PC';}});
    $result=$service->add(2,4,['HDD'=>'HDD_2TB','finalPriceMinor'=>1]);
    $assert($pricing->calls===1 && $result['price']['finalPriceMinor']===5710000 && $basket->writes[0]['price']===5710000);
    $assert(count($result['selection'])===12 && !isset($result['price']['groupDeltas']));
});

$test('snapshot failure writes no basket and basket failure compensates only new snapshot',static function()use($assert,$cartQuote,$views):void{
    $pricing=new class($cartQuote) implements ConfiguredProductPricingServiceInterface{public function __construct(private $f){}public function quote(int $i,int $p,array $s):ConfiguredProductQuote{return($this->f)();}};
    $basket=new class implements BasketGatewayInterface{public int $writes=0;public bool $fail=false;public function addConfiguredProduct(ConfiguredProductQuote $q,string $s,string $n,array $p):BasketResult{++$this->writes;if($this->fail)throw new RuntimeException('db secret');return new BasketResult(1);}};
    $repo=new class implements ConfigurationSnapshotRepositoryInterface{public bool $fail=true;public int $deletes=0;public function create(ConfigurationSnapshot $s):ConfigurationSnapshotReference{if($this->fail)throw new RuntimeException('db secret');return new ConfigurationSnapshotReference(1,$s->key);}public function findByKey(string $k):?ConfigurationSnapshot{return null;}public function deleteUnattached(ConfigurationSnapshotReference $r):void{++$this->deletes;}};
    $service=new ConfiguredProductCartService($pricing,new ConfigurationSnapshotBuilder($views),$repo,$basket,new BasketPropertyProjector(),new class implements SiteResolverInterface{public function currentSiteId():string{return's1';}},new class implements ProductViewProviderInterface{public function name(int $i,int $p):string{return'PC';}});
    try{$service->add(2,4,[]);}catch(CartException $e){$assert($e->diagnostic()['code']==='snapshot_persist_failed'&&$basket->writes===0);}
    $repo->fail=false;$basket->fail=true;try{$service->add(2,4,[]);}catch(CartException $e){$assert($e->diagnostic()['code']==='basket_save_failed'&&$repo->deletes===1);return;}throw new RuntimeException('Failure not mapped');
});

$test('snapshot SQL migration is idempotent and non destructive',static function()use($assert):void{
    $gateway=new class implements SnapshotTableGatewayInterface{public bool $table=false;public array $indexes=[];public int $creates=0;public function tableExists(string $t):bool{return$this->table;}public function createTable(string $t):void{$this->table=true;++$this->creates;}public function indexExists(string $t,string $i):bool{return isset($this->indexes[$i]);}public function createIndex(string $t,string $i,array $c,bool $u):void{$this->indexes[$i]=[$c,$u];++$this->creates;}};
    $migration=new SnapshotMigration($gateway);$migration->up();$assert($gateway->creates===4);$migration->up();$assert($gateway->creates===4&&count($gateway->indexes)===3);
});

$test('cart public mapper hides internals and controller keeps POST CSRF defaults with anonymous access',static function()use($assert):void{
    $mapper=new CartErrorMapper();$assert($mapper->map(['code'=>'sql_secret','message'=>'leak'])===['code'=>'internal_error','message'=>'Internal error','customData'=>[]]);
    $assert($mapper->map(['code'=>'unsupported_catalog_currency','currency'=>'USD'])===['code'=>'unsupported_currency','message'=>'Currency is not supported','customData'=>['currency'=>'USD']]);
    foreach(['invalid_pricing_policy','price_option_not_found','invalid_option_price','negative_option_price','price_overflow','negative_final_price'] as $code)$assert($mapper->map(['code'=>$code])['code']===$code,$code);
    $source=(string)file_get_contents(dirname(__DIR__,2).'/lib/Controller/Cart.php');
    $assert(str_contains($source,'HttpMethod::METHOD_POST')&&str_contains($source,'Authentication::class'));
    $assert(!str_contains($source,'Csrf::class')&&!str_contains($source,"'-prefilters' => [Csrf"));
    $gateway=(string)file_get_contents(dirname(__DIR__,2).'/lib/Cart/BitrixBasketGateway.php');
    $assert(str_contains($gateway,"'CUSTOM_PRICE'=>'Y'")&&str_contains($gateway,'Fuser::getId()'));
    foreach(["'BASE_PRICE'=>MinorUnitFormatter::decimal", "'DISCOUNT_PRICE'=>'0.00'", "'PRODUCT_PROVIDER_CLASS'=>KorsacCatalogProvider::class"] as $needle)$assert(str_contains($gateway,$needle),$needle);
    $provider=(string)file_get_contents(dirname(__DIR__,2).'/lib/Cart/KorsacCatalogProvider.php');
    $parentCall=strpos($provider,'parent::getProductData($products)');$configuredCheck=strpos($provider,"KORSAC_CONFIGURED");
    $assert($parentCall!==false&&$configuredCheck!==false&&$parentCall<$configuredCheck,'KORSAC provider must delegate to parent before configured-only projection');
    $smoke=(string)file_get_contents(dirname(__DIR__).'/Integration/cart_add_smoke.php');
    foreach(['Basket::loadItemsForFUser','getPropertyCollection()','CUSTOM_PRICE','snapshotFinalPrice','hash(\'sha256\', $stored->payload)'] as $needle)$assert(str_contains($smoke,$needle),$needle);
});
