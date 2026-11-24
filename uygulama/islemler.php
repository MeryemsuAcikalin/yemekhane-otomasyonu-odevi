<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/redis.php';

function hatalariGoster(string $hataMesaji,int $durumKodu = 400): never{
    http_response_code($durumKodu);
    throw new DomainException($hataMesaji);
}

function sayiKontrolu($girilenDeger): int{

    if (!is_scalar($girilenDeger) || !ctype_digit((string) $girilenDeger) || (int) $girilenDeger < 1){
        hatalariGoster('Geçerli bir sayı seçiniz.');
    }
    return (int) $girilenDeger;
}

function bakiyeDogrula($tutarDegeri,float $minutar,float $maxTutar): string{
    if (!is_scalar($tutarDegeri) || !preg_match('/^\d+(?:\.\d{1,2})?$/D',(string) $tutarDegeri)){
        hatalariGoster('Tutar en fazla iki ondalıklı olmalıdır.');
    }
    $tutar = (float) $tutarDegeri;
    if ($tutar < $minutar || $tutar > $maxTutar){
        hatalariGoster("Tutar $minutar ile $maxTutar arasında olmalıdır.");
    }
    return number_format($tutar,2,'.','');
}

function tarihDogrulamasi($girilenTarih): string{
    if (!is_string($girilenTarih) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D',$girilenTarih)){
        hatalariGoster('Geçerli bir tarih giriniz.');
    }
    [$yil,$ay,$gun] = array_map('intval',explode('-',$girilenTarih));

    if (!checkdate($ay,$gun,$yil)){
        hatalariGoster('Geçerli bir tarih giriniz.');
    }
    return $girilenTarih;
}

function menuBilgileriniDogrula(array $menuVerileri): array{
    $yemekler = $menuVerileri['yemek_ids'] ?? null;
    if (!is_array($yemekler) || count($yemekler) < 1 || count($yemekler) > 100){
        hatalariGoster('En az bir yemek seçmelisiniz.');
    }

    $yemekId = array_map('sayiKontrolu',$yemekler);
    if (count(array_unique($yemekId)) !== count($yemekId)){
        hatalariGoster('Aynı yemek birden fazla seçilemez.');
    }

    return ['yemekhane_id' => sayiKontrolu($menuVerileri['yemekhane_id'] ?? null),'tarih' => tarihDogrulamasi($menuVerileri['tarih'] ?? null),
    'ogun_id' => sayiKontrolu($menuVerileri['ogun_id'] ?? null),'ogrenci_fiyati' => bakiyeDogrula($menuVerileri['ogrenci_fiyati'] ?? null,0,999999.99),
    'personel_fiyati' => bakiyeDogrula($menuVerileri['personel_fiyati'] ?? null,0,999999.99),'yemek_ids' => $yemekId];
}

function guvenlikKontrolu(): void{
    $gelen = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $beklenen = $_SESSION['csrf'] ?? '';
    if (!is_string($gelen) || !is_string($beklenen) || $beklenen === '' || !hash_equals($beklenen,$gelen)){
        hatalariGoster('Güvenlik doğrulaması başarısız. Sayfayı yenileyiniz.',403);
    }
}

function apiIsteginiIsle(string $adres,string $istek,array $gelen): array{
    if ($adres === '/api/' && $istek === 'GET'){
        return ['durum' => 'hazir'];
    }
    if ($adres === '/api/oturum' && $istek === 'GET'){
        return ['kullanici' => $_SESSION['kullanici'] ?? null,'csrf' => $_SESSION['csrf'] ?? null];
    }

    if ($adres === '/api/giris' && $istek === 'POST'){
        $eposta = trim((string) ($gelen['eposta'] ?? ''));
        $sifre = (string) ($gelen['sifre'] ?? '');
        $kullanici = kullaniciyiBul($eposta);
        if (!$kullanici || (int) $kullanici['aktif'] !== 1 || !password_verify($sifre,$kullanici['sifre_hash'])){
            hatalariGoster('E-posta veya şifre hatalı.',401);
        }
        session_regenerate_id(true);

        $_SESSION['kullanici'] = ['id' => (int) $kullanici['id'],'ad' => $kullanici['ad'],'soyad' => $kullanici['soyad'],'rol' => $kullanici['rol']];
        $_SESSION['csrf'] = bin2hex(random_bytes(24));

        return ['kullanici' => $_SESSION['kullanici'],'csrf' => $_SESSION['csrf']];
    }

    if ($adres === '/api/cikis' && $istek === 'POST'){
        if (isset($_SESSION['kullanici'])){
        guvenlikKontrolu();
        }
        $_SESSION = [];
        session_destroy();
        return ['mesaj' => 'Oturum kapatıldı.'];
    }

    if (!isset($_SESSION['kullanici'])){
        hatalariGoster('Önce giriş yapınız.',401);
    }
    if (in_array($istek,['POST','PUT','PATCH','DELETE'],true)){
        guvenlikKontrolu();
    }
    $kullaniciId = (int) $_SESSION['kullanici']['id'];
    $rol = $_SESSION['kullanici']['rol'];
    if ($adres === '/api/secenekler' && $istek === 'GET'){
        return MenuSecenekleri();
    }

    if ($adres === '/api/bugun' && $istek === 'GET'){
        return redisOnbellegindenMenuyuGetir(sayiKontrolu($_GET['yemekhane'] ?? null),sayiKontrolu($_GET['ogun'] ?? null));
    }

    if ($adres === '/api/hafta' && $istek === 'GET'){
        $menuler = haftalikMenuler();
        foreach ($menuler as &$menu){
            $menu['fiyat'] = $rol === 'ogrenci' ? $menu['ogrenci_fiyati'] : $menu['personel_fiyati'];
        }
        unset($menu);
        return $menuler;
    }

    if ($adres === '/api/rezervasyonlar' && $istek === 'GET'){
        return rezervasyonBilgileri($kullaniciId);
    }

    if ($adres === '/api/rezervasyon' && $istek === 'POST'){
        $rezervasyonSonucu = proseduruCalistir('sp_rezervasyon_yap',[$kullaniciId,sayiKontrolu($gelen['menu_id'] ?? null)]);
        return ['mesaj' => 'Rezervasyonunuz oluşturuldu.','sonuc' => $rezervasyonSonucu,'bakiye' => guncelBakiye($kullaniciId,true)];
    }

    if (preg_match('~^/api/rezervasyon/(\d+)/iptal$~',$adres,$eslesenler) && $istek === 'POST'){
        $rezervasyonSonucu = proseduruCalistir('sp_rezervasyon_iptal',[$kullaniciId,sayiKontrolu($eslesenler[1])]);
        return ['mesaj' => 'Rezervasyon iptal edildi.','sonuc' => $rezervasyonSonucu,'bakiye' => guncelBakiye($kullaniciId,true)];
    }

    if ($adres === '/api/bakiye' && $istek === 'GET'){
        return ['bakiye' => guncelBakiye($kullaniciId),'hareketler' => bakiyeHareketleri($kullaniciId)];
    }

    if ($adres === '/api/yukleme' && $istek === 'POST'){
        $sonuc = proseduruCalistir('sp_bakiye_yukle',[$kullaniciId,bakiyeDogrula($gelen['tutar'] ?? null,10,1000)]);

        return ['mesaj' => 'Bakiye başarıyla yüklendi.','sonuc' => $sonuc,'bakiye' => guncelBakiye($kullaniciId,true)];
    }

    if ($adres === '/api/uygun-menuler' && $istek === 'GET'){
        return puanVerilebilenMenuler($kullaniciId);
    }

    if ($adres === '/api/puan' && $istek === 'POST'){
        $verilenPuan = sayiKontrolu($gelen['puan'] ?? null);
        $yorum = trim((string) ($gelen['yorum'] ?? ''));
        if ($verilenPuan > 5){
            hatalariGoster('Puan 1 ile 5 arasında olmalıdır.');
        }
        if (mb_strlen($yorum,'UTF-8') > 500){
            hatalariGoster('Yorum en fazla 500 karakter olabilir.');
        }
        $basariliKayit = degerlendirmeKaydet($kullaniciId,sayiKontrolu($gelen['menu_id'] ?? null),$verilenPuan,$yorum);
        if (!$basariliKayit){
            hatalariGoster('Yalnızca aldığınız menüyü değerlendirebilirsiniz.');
        }
        return ['mesaj' => 'Değerlendirmeniz kaydedildi.'];
    }

    if (str_starts_with($adres,'/api/yonetim')){
        if ($rol !== 'yonetici'){
            hatalariGoster('Yönetici yetkisi gerekli.',403);
        }
        if ($adres === '/api/yonetim' && $istek === 'GET'){
            return yoneticiMenuListesi();
        }

        if ($adres === '/api/yonetim' && $istek === 'POST'){
            return ['mesaj' => 'Yeni menü kaydedildi.','menu_id' => menuyuKaydetVeOnbellegiSil(null,menuBilgileriniDogrula($gelen))];
        }

        if (preg_match('~^/api/yonetim/(\d+)/yemekler$~',$adres,$eslesenler) && $istek === 'GET'){
            return MenuYemekleri(sayiKontrolu($eslesenler[1]));
        }

        if (preg_match('~^/api/yonetim/(\d+)$~',$adres,$eslesenler) && $istek === 'PUT'){
            return ['mesaj' => 'Menü güncellendi.','menu_id' => menuyuKaydetVeOnbellegiSil(sayiKontrolu($eslesenler[1]),menuBilgileriniDogrula($gelen))];
        }
    }

    hatalariGoster('İstenen API adresi bulunamadı.',404);
}