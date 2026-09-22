<?php
declare(strict_types=1);

$test('server QR renders bounded opaque PNG with an intact four-module quiet zone', function()use($same):void {
    foreach ([['0812345678','100.25'],['1234567890123','999999999999.99']] as [$target,$amount]) {
        $payload=\Dormitory\Domain\PromptPayService::payload($target,$amount);
        $png=\Dormitory\Support\QrPng::render($payload);
        $same("\x89PNG\r\n\x1a\n",substr($png,0,8));
        $size=getimagesizefromstring($png);$same(IMAGETYPE_PNG,$size[2]);$same($size[0],$size[1]);
        $same(true,$size[0]<=1024&&strlen($png)<300000);
        $image=imagecreatefromstring($png);
        foreach ([0,39,$size[0]-40,$size[0]-1] as $edge) {
            for($p=0;$p<$size[0];$p++){$same(0xffffff,imagecolorat($image,$p,$edge));$same(0xffffff,imagecolorat($image,$edge,$p));}
        }
        $same(0,imagecolorat($image,40,40));
        $same($png,\Dormitory\Support\QrPng::render($payload));
    }
});
$test('QR renderer rejects arbitrary or oversized input without network fallback',function():void {
    foreach(['',str_repeat('1',201),'<svg>',"abc\n",'https://example.test'] as $payload){
        try{\Dormitory\Support\QrPng::render($payload);}catch(RuntimeException){continue;}
        throw new RuntimeException('Invalid QR input accepted');
    }
});
