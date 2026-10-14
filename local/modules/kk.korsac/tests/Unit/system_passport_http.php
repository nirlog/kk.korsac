<?php

declare(strict_types=1);

use KK\Korsac\Passport\PublicPassportHttpPresenter;
use KK\Korsac\Passport\PublicPassportHttpView;
use KK\Korsac\Passport\PublicPassportRoute;
use KK\Korsac\Install\PassportDeliveryDefinition;
use KK\Korsac\Install\PassportDeliveryFiles;
use KK\Korsac\Install\PassportDeliveryService;
use KK\Korsac\System\PublicPassportDto;
use KK\Korsac\System\PublicPassportComponent;

$httpDto=static fn(array $components=[]):PublicPassportDto=>new PublicPassportDto(str_repeat('a',32),'KS-2610-0006','MODEL','KORSAC PLAY 1440','2026-10-14T00:30:00+03:00','2026-10-14T00:30:00+03:00',$components);
$renderPassport=static function(PublicPassportHttpView $view):string {
    if (!defined('B_PROLOG_INCLUDED')) define('B_PROLOG_INCLUDED',true);
    $arResult=['VIEW'=>$view];
    $arParams=['SECRET_CODE'=>'UNUSED_PRIVATE_PARAM','SYSTEM_INTERNAL_ID'=>1930331];
    ob_start();
    try { include dirname(__DIR__,2).'/install/components/kk/korsac.passport/templates/.default/template.php'; return ob_get_clean(); }
    catch (Throwable $error) { ob_end_clean(); throw $error; }
};
$test('Passport HTTP presenter returns only a public DTO for 200 ACTIVE',static function() use ($assert,$httpDto):void {
    $dto=$httpDto(); $ids=[];
    $view=(new PublicPassportHttpPresenter(static function(string $id) use ($dto,&$ids):PublicPassportDto { $ids[]=$id; return $dto; }))->present($dto->publicId);
    $assert($view->status===200 && $view->state==='ACTIVE' && $view->passport===$dto && $ids===[$dto->publicId]);
    $assert(array_keys(get_object_vars($view))===['status','state','passport']);
});
foreach ([['system_passport_not_found',404,'NOT_FOUND'],['system_passport_read_failed',500,'ERROR'],['system_passport_state_invalid',500,'ERROR'],[null,500,'ERROR']] as [$code,$status,$state]) {
    $test('Passport HTTP presenter maps '.($code ?? 'unexpected Throwable').' without private error details',static function() use ($assert,$code,$status,$state):void {
        $private='SQL SELECT customer /private/path PRIVATE_SECRET PRIVATE_HASH PRIVATE_SERIAL';
        $error=$code===null ? new RuntimeException($private) : new \KK\Korsac\System\SystemException($code,['field'=>$private]);
        $view=(new PublicPassportHttpPresenter(static fn(string $id)=>throw $error))->present(str_repeat('a',32));
        $assert(get_object_vars($view)===['status'=>$status,'state'=>$state,'passport'=>null]);
        $encoded=json_encode($view,JSON_THROW_ON_ERROR); $assert(!str_contains($encoded,$private) && !str_contains($encoded,'system_passport_') && !str_contains($encoded,'RuntimeException'));
    });
}
$test('Passport HTTP presenter delegates malformed input to the existing public boundary',static function() use ($assert,$publicPassportFixture):void {
    $f=$publicPassportFixture(); $presenter=new PublicPassportHttpPresenter(static fn(string $id)=>$f['public']->find($id));
    foreach (['bad',str_repeat('f',32),['SECRET_CODE'=>'private']] as $id) $assert($presenter->present($id)->status===404);
});
foreach ([PublicPassportHttpView::active($httpDto()),PublicPassportHttpView::notFound(),PublicPassportHttpView::error()] as $view) {
    $test('Passport HTTP '.$view->status.' has identical noindex referrer and cache policy',static function() use ($assert,$view):void {
        $assert($view::headers()===['X-Robots-Tag'=>'noindex, nofollow, noarchive','Referrer-Policy'=>'no-referrer','Cache-Control'=>'no-store']);
    });
}
$test('Passport template renders identity dates ordered components and escapes dynamic text',static function() use ($assert,$renderPassport):void {
    $dto=new PublicPassportDto(str_repeat('a',32),'KS-2610-0006 <x>','CUSTOM','<script>bad & "quoted"</script>','2026-10-14T00:30:00+03:00','2026-10-14T00:30:00+03:00',[
        new PublicPassportComponent('SSD','SSD <img src=x>','Disk <svg onload="bad">'),new PublicPassportComponent('RAM','RAM 2','Second row'),
    ]);
    $html=$renderPassport(PublicPassportHttpView::active($dto));
    $assert(str_contains($html,'KS-2610-0006 &lt;x&gt;') && str_contains($html,'&lt;script&gt;bad &amp; &quot;quoted&quot;&lt;/script&gt;'));
    $assert(str_contains($html,'SSD &lt;img src=x&gt;') && str_contains($html,'Disk &lt;svg onload=&quot;bad&quot;&gt;'));
    $assert(!str_contains($html,'<script>') && !str_contains($html,'<img') && !str_contains($html,'<svg'));
    $assert(substr_count($html,'<h1 ')===1 && str_contains($html,'Индивидуальная сборка') && str_contains($html,'14.10.2026'));
    $assert(substr_count($html,'<time datetime="2026-10-14T00:30:00+03:00">')===2 && strpos($html,'Disk &lt;')<strpos($html,'Second row'));
});
$test('Passport template accepts an ACTIVE System with no physical component rows',static function() use ($assert,$renderPassport,$httpDto):void {
    $html=$renderPassport(PublicPassportHttpView::active($httpDto()));
    $assert(str_contains($html,'KS-2610-0006') && str_contains($html,'Данные о комплектующих пока не внесены.'));
});
$test('Passport NOT_FOUND and ERROR templates contain no Passport data or machine details',static function() use ($assert,$renderPassport):void {
    foreach ([PublicPassportHttpView::notFound(),PublicPassportHttpView::error()] as $view) {
        $html=$renderPassport($view);
        $assert(str_contains($html,$view->status===404 ? 'Паспорт системы не найден.' : 'Паспорт временно недоступен.'));
        foreach (['KS-','system_passport_','UNUSED_PRIVATE_PARAM','1930331','RuntimeException','<time','Комплектация'] as $private) $assert(!str_contains($html,$private));
    }
});
$test('Passport public HTML excludes every private-rich domain fixture value',static function() use ($assert,$renderPassport,$publicPassportFixture):void {
    $f=$publicPassportFixture(); $id=1930331;
    $source=\KK\Korsac\System\SystemCreationRequest::fromArray(['SITE_ID'=>'s1','ORDER_ID'=>2930441,'ORDER_BASKET_ID'=>3930551,'ORDER_UNIT_INDEX'=>112911,'SYSTEM_TYPE'=>'MODEL','MODEL_NAME'=>'Public model','IBLOCK_ID'=>4930661,'PRODUCT_ID'=>5930771,'CONFIG_SNAPSHOT_KEY'=>str_repeat('e',64)]);
    $f['systems']->record=new \KK\Korsac\System\SystemRecord($id,'KS-2610-0006',$source,7930991,'ACTIVE',$f['origin'],$f['origin']);
    $f['repo']->parents[$id]=new \KK\Korsac\System\PassportSystemState($id,'ACTIVE',$f['origin']);
    $prepared=$f['service']->preparePassport($id); $f['service']->activatePassport($id);
    $components=new class($id,$f['origin']) implements \KK\Korsac\System\SystemComponentRepositoryInterface {
        public function __construct(private int $id,private DateTimeImmutable $at) {}
        public function create(\KK\Korsac\System\SystemComponent $component):\KK\Korsac\System\SystemComponent { throw new RuntimeException('Unexpected write'); }
        public function findForSystem(int $id,bool $activeOnly=false):array {
            if (!$activeOnly) throw new RuntimeException('Expected active-only component read');
            return [new \KK\Korsac\System\SystemComponent(8930111,$this->id,'SSD','PRIVATE_SLOT_1','SSD 1','Public disk','PRIVATE_SERIAL_1',$this->at,null,$this->at,$this->at)];
        }
    };
    $public=new \KK\Korsac\System\PublicPassportService($f['repo'],$f['systems'],$components);
    $view=(new PublicPassportHttpPresenter(static fn(string $key)=>$public->find($key)))->present($prepared->passport->publicId);
    $assert($view->status===200);
    $html=$renderPassport($view); $assert(str_contains($html,'Public model') && str_contains($html,'Public disk'));
    foreach ([$id,2930441,3930551,112911,4930661,5930771,7930991,8930111,str_repeat('e',64),$prepared->secretCode,$prepared->passport->secretCodeHash,'PRIVATE_SLOT_1','PRIVATE_SERIAL_1','SECRET_CODE','SYSTEM_INTERNAL_ID','ORDER_ID'] as $private) $assert(!str_contains($html,(string)$private));
});
$test('Passport rewrite accepts only the anchored lowercase token path with an optional slash and query tail',static function() use ($assert):void {
    $id=str_repeat('a',32);
    $rule=PublicPassportRoute::rewrite();
    $assert($rule['RULE']==='PUBLIC_ID=$1' && $rule['PATH']==='/passport/index.php' && $rule['ID']==='kk:korsac.passport');
    foreach (['/passport/'.$id.'/','/passport/'.$id,'/passport/'.$id.'/?unused=value','/passport/'.$id.'?secret=ignored'] as $uri) {
        $assert(preg_match($rule['CONDITION'],$uri)===1 && PublicPassportRoute::publicId($uri)===$id);
    }
    foreach (['/passport/'.str_repeat('A',32).'/', '/passport/'.str_repeat('a',31).'/', '/passport/'.str_repeat('a',33).'/', '/passport/KS-2610-0006/', '/passport/'.$id.'%2fmore/', '/passport/'.$id.'/extra/', '/passport/'.$id.'/secret/', '/passport/'.$id.'.php', '/passport/%61'.str_repeat('a',31).'/', 'https://untrusted.test/passport/'.$id.'/', '/prefix/passport/'.$id.'/', '/passport/'.$id."/\n"] as $uri) {
        $assert(preg_match($rule['CONDITION'],$uri)===0 && PublicPassportRoute::publicId($uri)==='');
    }
});
$test('Passport canonical redirect is relative and drops every query credential',static function() use ($assert):void {
    $id=str_repeat('a',32);
    $assert(PublicPassportRoute::redirect('/passport/'.$id.'?secret=PRIVATE&public-id=other&host=evil')==='/passport/'.$id.'/');
    $assert(PublicPassportRoute::redirect('/passport/'.$id.'/?secret=PRIVATE')===null);
    $assert(PublicPassportRoute::redirect('//evil.test/passport/'.$id)===null && PublicPassportRoute::redirect('/passport/KS-2610-0006')===null);
});

$deliveryGateway=static fn()=>new class implements \KK\Korsac\Install\PassportDeliveryGatewayInterface {
    public array $files=[], $rewrites=[];
    public int $fileWrites=0,$routeWrites=0;
    public function fileState(string $file):string { return $this->files[$file] ?? 'missing'; }
    public function installFile(string $file):void { if ($this->fileState($file)!=='owned') { $this->files[$file]='owned'; ++$this->fileWrites; } }
    public function removeFile(string $file):void { if (in_array($this->fileState($file),['owned','outdated'],true)) { unset($this->files[$file]); ++$this->fileWrites; } }
    public function routes():array { return $this->rewrites; }
    public function replaceOwnedRoute(?array $route):void {
        $this->rewrites=array_values(array_filter($this->rewrites,static fn(array $row):bool=>($row['ID'] ?? null)!==PublicPassportRoute::COMPONENT));
        if ($route!==null) $this->rewrites[]=$route;
        ++$this->routeWrites;
    }
};
$foreignRewrite=['CONDITION'=>'#^/news/#','RULE'=>'','ID'=>'bitrix:news','PATH'=>'/news/index.php'];
$test('Passport delivery installs fresh files and one rewrite idempotently without changing unrelated routes',static function() use ($assert,$deliveryGateway,$foreignRewrite):void {
    $gateway=$deliveryGateway(); $gateway->rewrites=[$foreignRewrite]; $service=new PassportDeliveryService($gateway);
    $assert($service->install()['ok'] && $gateway->fileWrites===5 && $gateway->routeWrites===1);
    $assert($service->install()['ok'] && $gateway->fileWrites===5 && $gateway->routeWrites===1 && $gateway->rewrites===[$foreignRewrite,PublicPassportRoute::rewrite()]);
});
$test('Passport delivery repairs duplicate or malformed owned rules and missing/outdated files',static function() use ($assert,$deliveryGateway,$foreignRewrite):void {
    $gateway=$deliveryGateway(); $service=new PassportDeliveryService($gateway); $service->install();
    $bad=PublicPassportRoute::rewrite(); $bad['RULE']='WRONG=$1';
    $gateway->rewrites=[$foreignRewrite,$bad,PublicPassportRoute::rewrite(),PublicPassportRoute::rewrite()];
    unset($gateway->files['passport/index.php']); $gateway->files['local/components/kk/korsac.passport/component.php']='outdated';
    $assert(!$service->check()['ok']); $assert($service->install()['ok']);
    $assert($gateway->rewrites===[$foreignRewrite,PublicPassportRoute::rewrite()] && $gateway->fileWrites===7);
});
foreach (['endpoint','component','missing-route','wrong-route','duplicate-route'] as $defect) {
    $test('Passport delivery read-only health detects '.$defect,static function() use ($assert,$deliveryGateway,$defect):void {
        $gateway=$deliveryGateway(); $service=new PassportDeliveryService($gateway); $service->install();
        if ($defect==='endpoint') unset($gateway->files['passport/index.php']);
        if ($defect==='component') unset($gateway->files['local/components/kk/korsac.passport/component.php']);
        if ($defect==='missing-route') $gateway->rewrites=[];
        if ($defect==='wrong-route') $gateway->rewrites[0]['PATH']='/other/index.php';
        if ($defect==='duplicate-route') $gateway->rewrites[]=$gateway->rewrites[0];
        $before=[$gateway->fileWrites,$gateway->routeWrites];
        $assert(!$service->check()['ok'] && $before===[$gateway->fileWrites,$gateway->routeWrites]);
    });
}
$test('Passport delivery conflict preflight preserves foreign files and route definitions before any writes',static function() use ($assert,$deliveryGateway,$systemError):void {
    $gateway=$deliveryGateway(); $gateway->files['passport/index.php']='conflict'; $service=new PassportDeliveryService($gateway);
    $systemError(static fn()=>$service->install(),'passport_delivery_file_conflict'); $assert($gateway->fileWrites===0 && $gateway->routeWrites===0);
    $gateway=$deliveryGateway(); $foreign=PublicPassportRoute::rewrite(); $foreign['ID']='application:owned'; $gateway->rewrites=[$foreign]; $service=new PassportDeliveryService($gateway);
    $systemError(static fn()=>$service->install(),'passport_delivery_route_conflict');
    $assert($gateway->rewrites===[$foreign] && $gateway->fileWrites===0 && $gateway->routeWrites===0 && !$service->check()['ok']);
});
$test('Passport delivery uninstall removes only manifest-owned executables and owned routes',static function() use ($assert,$deliveryGateway,$foreignRewrite):void {
    $gateway=$deliveryGateway(); $service=new PassportDeliveryService($gateway); $service->install();
    $gateway->files['passport/custom.php']='foreign'; $gateway->files['local/components/kk/other/component.php']='foreign';
    $gateway->files['passport/index.php']='conflict'; $gateway->rewrites[]=$foreignRewrite;
    $service->remove();
    $assert($gateway->rewrites===[$foreignRewrite] && $gateway->files===['passport/index.php'=>'conflict','passport/custom.php'=>'foreign','local/components/kk/other/component.php'=>'foreign']);
});

$removeHttpFixture=static function(string $path) use (&$removeHttpFixture):void {
    if (is_link($path) || !is_dir($path)) { if (file_exists($path) || is_link($path)) unlink($path); return; }
    foreach (scandir($path) as $entry) if ($entry!=='.' && $entry!=='..') $removeHttpFixture($path.'/'.$entry);
    rmdir($path);
};
$test('Passport delivery real filesystem copies idempotently and preserves custom files on uninstall',static function() use ($assert,$removeHttpFixture,$systemError):void {
    $root=sys_get_temp_dir().'/korsac-http-unit-'.bin2hex(random_bytes(6)); mkdir($root);
    try {
        $files=new PassportDeliveryFiles($root);
        foreach (PassportDeliveryDefinition::files() as $file=>$source) {
            $assert($files->state($file)==='missing'); $files->install($file); $assert($files->state($file)==='owned');
            touch($root.'/'.$file,946684800); clearstatcache(); $files->install($file); clearstatcache(); $assert(filemtime($root.'/'.$file)===946684800);
        }
        file_put_contents($root.'/passport/custom.php','APPLICATION_CUSTOM');
        file_put_contents($root.'/local/components/kk/korsac.passport/custom.php','APPLICATION_COMPONENT_CUSTOM');
        foreach (PassportDeliveryDefinition::files() as $file=>$source) $files->remove($file);
        $assert(file_get_contents($root.'/passport/custom.php')==='APPLICATION_CUSTOM' && file_get_contents($root.'/local/components/kk/korsac.passport/custom.php')==='APPLICATION_COMPONENT_CUSTOM');
        file_put_contents($root.'/passport/index.php','APPLICATION_ENDPOINT'); $assert($files->state('passport/index.php')==='conflict');
        $systemError(static fn()=>$files->install('passport/index.php'),'passport_delivery_file_conflict'); $files->remove('passport/index.php');
        $assert(file_get_contents($root.'/passport/index.php')==='APPLICATION_ENDPOINT');
    } finally { $removeHttpFixture($root); }
});
$test('Passport delivery real filesystem refuses symlink files and parents without following them',static function() use ($assert,$removeHttpFixture,$systemError):void {
    $root=sys_get_temp_dir().'/korsac-http-unit-'.bin2hex(random_bytes(6)); mkdir($root); mkdir($root.'/external'); file_put_contents($root.'/external/index.php','EXTERNAL_UNCHANGED');
    try {
        $files=new PassportDeliveryFiles($root); symlink($root.'/external',$root.'/passport');
        $assert($files->state('passport/index.php')==='conflict'); $systemError(static fn()=>$files->install('passport/index.php'),'passport_delivery_file_conflict'); $files->remove('passport/index.php');
        unlink($root.'/passport'); mkdir($root.'/passport'); symlink($root.'/external/index.php',$root.'/passport/index.php');
        $assert($files->state('passport/index.php')==='conflict'); $systemError(static fn()=>$files->install('passport/index.php'),'passport_delivery_file_conflict'); $files->remove('passport/index.php');
        $assert(file_get_contents($root.'/external/index.php')==='EXTERNAL_UNCHANGED' && is_link($root.'/passport/index.php'));
    } finally { $removeHttpFixture($root); }
});

foreach (['exception','warning'] as $failure) {
    $test('Passport endpoint contains a bootstrap '.$failure.' even with display_errors enabled',static function() use ($assert,$removeHttpFixture,$failure):void {
        $root=sys_get_temp_dir().'/korsac-http-unit-'.bin2hex(random_bytes(6)); mkdir($root);
        try {
            mkdir($root.'/bitrix/modules/main/include',0755,true);
            $private='PRIVATE_SQL_SECRET_HASH_SERIAL /private/driver.php';
            $operation=$failure==='exception' ? 'throw new RuntimeException('.var_export($private,true).');' : 'trigger_error('.var_export($private,true).', E_USER_WARNING);';
            file_put_contents($root.'/bitrix/modules/main/include/prolog_before.php',"<?php ini_set('display_errors','1'); ".$operation);
            $endpoint=dirname(__DIR__,2).'/install/public/passport/index.php';
            $script='<?php $_SERVER[\'DOCUMENT_ROOT\']='.var_export($root,true).'; include '.var_export($endpoint,true).'; echo "|status=".http_response_code();';
            file_put_contents($root.'/run.php',$script);
            $pipes=[]; $process=proc_open([PHP_BINARY,'-d','display_errors=1',$root.'/run.php'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
            $assert(is_resource($process)); fclose($pipes[0]);
            $html=stream_get_contents($pipes[1]); $stderr=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
            $assert(proc_close($process)===0 && $stderr==='');
            $assert(str_contains($html,'Паспорт временно недоступен.') && str_contains($html,'|status=500') && str_contains($html,'noindex, nofollow, noarchive'));
            foreach ([$private,$root,'RuntimeException','ErrorException','Stack trace','Warning:'] as $detail) $assert(!str_contains($html,$detail));
        } finally { $removeHttpFixture($root); }
    });
}
