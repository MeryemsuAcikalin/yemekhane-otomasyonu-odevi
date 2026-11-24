<?php
declare(strict_types=1);

function redisBaglantisi(): Redis{
    static $redis = null;

    if ($redis === null){
      $redis = new Redis();
        $redis->connect(getenv('REDIS_HOST') ?: 'redis',(int) (getenv('REDIS_PORT') ?: 6379),2.5);
    }
    return $redis;
}

class RedisOturumYonetici implements SessionHandlerInterface, SessionUpdateTimestampHandlerInterface{

    private function anahtar(string $id): string{
        return 'oturum:' . $id;
    }
    public function open(string $path,string $name): bool{
        return true;
    }
    public function close(): bool{
        return true;
    }
    public function read(string $id): string|false{
        $veri = redisBaglantisi()->get($this->anahtar($id));

        return $veri === false ? '' : $veri;
    }
    public function write(string $id,string $data): bool{
        return redisBaglantisi()->setEx($this->anahtar($id),1800,$data);
    }
    public function destroy(string $id): bool{
        redisBaglantisi()->del($this->anahtar($id));

        return true;
    }
    public function gc(int $max_lifetime): int|false{
        return 0;
    }
    public function validateId(string $id): bool{
        return (bool) redisBaglantisi()->exists($this->anahtar($id));
    }
    public function updateTimestamp(string $id,string $data): bool{
        return (bool) redisBaglantisi()->expire($this->anahtar($id),1800);
    }
}
function redisOnbellegindenMenuyuGetir(int $yemekhane,int $ogun): array{
    $bugun = (new DateTimeImmutable('today',new DateTimeZone('Europe/Istanbul')))->format('Y-m-d');
    $anahtar = "menu:$yemekhane:$bugun:$ogun";
    $veri = redisBaglantisi()->get($anahtar);

    if ($veri !== false){
        error_log("Menu bilgisi redisten getirildi. Menu anahtari: $anahtar");
        return json_decode($veri,true,512,JSON_THROW_ON_ERROR);
    }
     error_log("Menu bilgisi Redis'te bulunamadi. Menu anahtari: $anahtar");
    $sonuc = gunlukMenu($yemekhane,$ogun);
    $yarin = new DateTimeImmutable('tomorrow',new DateTimeZone('Europe/Istanbul'));
    $sure = max(1,$yarin->getTimestamp() - time());
    redisBaglantisi()->setEx($anahtar,$sure,json_encode($sonuc,JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    return $sonuc;
}
function menuOnbelleginiSil(int $yemekhane,string $tarih,int $ogun): void{
    $anahtar = "menu:$yemekhane:$tarih:$ogun";
    redisBaglantisi()->del($anahtar);
    error_log("MENU_CACHE DEL $anahtar");
}
function menuyuKaydetVeOnbellegiSil(?int $menuID,array $menuBilgisi): int{
    $eskiMenu = null;
    if ($menuID !== null){
        $eskiMenu = KayitGoster("SELECT yemekhane_id,DATE_FORMAT(tarih,'%Y-%m-%d') AS tarih,ogun_id FROM menuler WHERE id = ?",[$menuID],true);
    }
    $kaydedilenId = menuyuKaydet($menuID,$menuBilgisi);
    if ($eskiMenu !== null){
        menuOnbelleginiSil((int) $eskiMenu['yemekhane_id'],(string) $eskiMenu['tarih'],(int) $eskiMenu['ogun_id']);
    }
    menuOnbelleginiSil((int) $menuBilgisi['yemekhane_id'],(string) $menuBilgisi['tarih'],(int) $menuBilgisi['ogun_id']);
    return $kaydedilenId;
}