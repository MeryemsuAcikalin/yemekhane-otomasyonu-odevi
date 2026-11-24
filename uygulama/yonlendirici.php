<?php
declare(strict_types=1);
date_default_timezone_set('Europe/Istanbul');
ini_set('display_errors','0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
session_set_cookie_params(['httponly' => true,'samesite' => 'Lax','path' => '/']);
session_start();
require_once __DIR__ . '/islemler.php';

function jsonYaniti(array $veriler,int $durumKodu = 200): void{
    http_response_code($durumKodu);
    echo json_encode($veriler,JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
}

$adres = parse_url($_SERVER['REQUEST_URI'] ?? '/',PHP_URL_PATH) ?: '/';
$istek = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try{
    $gelen = [];

    if (in_array($istek,['POST','PUT','PATCH','DELETE'],true)){
        $Veri = file_get_contents('php://input');
        $gelen = json_decode($Veri ?: '{}',true,512,JSON_THROW_ON_ERROR);
        if (!is_array($gelen) || array_is_list($gelen) && $gelen !== []){
            jsonYaniti(['hata' => 'Geçerli bir JSON nesnesi gönderiniz.'],400);
            exit;
        }
    }

    $sonuc = apiIsteginiIsle($adres,$istek,$gelen);
    jsonYaniti($sonuc);
}catch(JsonException $hata){
    jsonYaniti(['hata' => 'Gönderilen JSON formatı geçersiz.'],400);

}catch(PDOException $hata){
    $sqlDurumu = $hata->errorInfo[0] ?? (string) $hata->getCode();

    if ($sqlDurumu === '45000'){
        $mesaj = $hata->errorInfo[2] ?? 'İşlem gerçekleştirilemedi.';
        jsonYaniti(['hata' => $mesaj],400);
    }elseif ($sqlDurumu === '23000'){
        jsonYaniti(['hata' => 'Bu kayıt zaten mevcut veya ilişkili bir kayıt bulunamadı.'],409);
    }else{
        error_log($hata->getMessage());
        jsonYaniti(['hata' => 'Veritabanı işlemi başarısız.'],500);
    }

}catch(DomainException $hata){
    $durumKodu = http_response_code();
    if ($durumKodu < 400){
        $durumKodu = 400;
    }
    jsonYaniti(['hata' => $hata->getMessage()],$durumKodu);

}catch(Throwable $hata){
    error_log($hata->getMessage());
    jsonYaniti(['hata' => 'Beklenmeyen bir hata oluştu.'],500);
}