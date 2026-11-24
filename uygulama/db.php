<?php
declare(strict_types=1);

function mySQLbaglantisi(bool $yazma = false): PDO{
    static $baglantilar = [];
    $tur = $yazma ? 'primary' : 'replica';

    if (!isset($baglantilar[$tur])) {

        $host = getenv($yazma ? 'DB_YAZMA_HOST' : 'DB_OKUMA_HOST');
        $port = getenv('DB_PORT') ?: '3306';
        $dbAdi = getenv('DB_ADI');
        $kullanici = getenv('DB_KULLANICI');
        $sifre = getenv('DB_SIFRE');
        $baglantiAdres = "mysql:host=$host;port=$port;dbname=$dbAdi;charset=utf8mb4";
        $baglantilar[$tur] = new PDO($baglantiAdres,$kullanici,$sifre,[PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES => false]);
    }

    return $baglantilar[$tur];
}
function listele(string $sql,array $parametreler = [],bool $primary = false): array{
    $sorgu = mySQLbaglantisi($primary)->prepare($sql);
    $sorgu->execute($parametreler);
    return $sorgu->fetchAll();
}
function KayitGoster(string $sql,array $parametreler = [],bool $primary = false): ?array{
    return listele($sql,$parametreler,$primary)[0] ?? null;
}

function proseduruCalistir(string $prosedurAdi,array $parametreler): array{
    $kullanilabilirProsedurler = ['sp_rezervasyon_yap','sp_rezervasyon_iptal','sp_bakiye_yukle'];
    if (!in_array($prosedurAdi,$kullanilabilirProsedurler,true)){
        throw new LogicException('İzin verilmeyen prosedür.');
    }

    $isaretler=implode(', ',array_fill(0, count($parametreler), '?'));
    $sorgu=mySQLbaglantisi(true)->prepare("CALL $prosedurAdi($isaretler)");

    try{
    $sorgu->execute($parametreler);
    return $sorgu->fetch() ?: [];
    }finally{
    $sorgu->closeCursor();
    }
}

function kullaniciyiBul(string $eposta): ?array{
    return KayitGoster('SELECT id, ad, soyad, eposta, sifre_hash, rol, aktif FROM kullanicilar WHERE eposta = ?',[$eposta]);
}

function MenuSecenekleri(): array{
return['yemekhaneler' => listele('SELECT id, ad, konum FROM yemekhaneler ORDER BY ad'),'ogunler' => listele('SELECT id, ad FROM ogunler ORDER BY sira'),'yemekler' => listele(
    'SELECT y.id, y.ad, k.ad AS kategori FROM yemekler y JOIN yemek_kategorileri k ON k.id = y.kategori_id ORDER BY k.sira, y.ad')];
}

function gunlukMenu(int $yemekhane,int $ogun): array{
    $menuBilgisi=listele("SELECT menu_id,DATE_FORMAT(tarih, '%Y-%m-%d') AS tarih,yemekhane,ogun,yemek,kategori,kalori,vejetaryen,alerjenler,ogrenci_fiyati,personel_fiyati FROM v_menu_detay
        WHERE tarih=CURDATE() AND yemekhane_id = ? AND ogun_id = ?
        ORDER BY kategori_sira, yemek",[$yemekhane, $ogun]);
    return['yemekler' => $menuBilgisi,'toplamKalori' => array_sum(array_column($menuBilgisi, 'kalori'))];
}

function haftalikMenuler(): array{
    return listele("SELECT m.id AS menu_id,DATE_FORMAT(m.tarih, '%Y-%m-%d') AS tarih,y.ad AS yemekhane,o.ad AS ogun,m.ogrenci_fiyati,m.personel_fiyati,d.kapasite,d.rezerve,d.bos_yer,
    d.doluluk_yuzde,DATE_FORMAT(d.rezervasyon_son,'%Y-%m-%d %H:%i') AS son_saat,IF(NOW() < d.rezervasyon_son AND d.bos_yer > 0,1,0) AS acik FROM menuler m JOIN yemekhaneler y ON y.id = m.yemekhane_id
    JOIN ogunler o ON o.id = m.ogun_id JOIN v_menu_doluluk d ON d.menu_id = m.id WHERE m.tarih BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 6 DAY) ORDER BY m.tarih, o.sira, y.ad");
}

function rezervasyonBilgileri(int $kullaniciId): array{
    return listele("SELECT r.id,r.durum,r.ucret,DATE_FORMAT(m.tarih, '%Y-%m-%d') AS tarih,y.ad AS yemekhane,o.ad AS ogun,DATE_FORMAT(TIMESTAMP(m.tarih, o.son_saat),'%Y-%m-%d %H:%i') AS son_saat,
    IF(r.durum = 'aktif' AND NOW() < TIMESTAMP(m.tarih, o.son_saat),1,0) AS iptal_acik FROM rezervasyonlar r JOIN menuler m ON m.id = r.menu_id JOIN yemekhaneler y ON y.id = m.yemekhane_id JOIN ogunler o
    ON o.id = m.ogun_id WHERE r.kullanici_id = ? ORDER BY m.tarih DESC, r.id DESC",[$kullaniciId]);
}

function guncelBakiye(int $kullaniciId,bool $primary = false): ?string{
    $bakiyeBilgi=KayitGoster('SELECT bakiye FROM kullanicilar WHERE id = ?',[$kullaniciId],$primary);
    return $bakiyeBilgi['bakiye'] ?? null;
}

function bakiyeHareketleri(int $kullaniciId): array{
    return listele("SELECT tur,tutar,aciklama,DATE_FORMAT(olusturma,'%Y-%m-%d %H:%i') AS tarih FROM bakiye_hareketleri WHERE kullanici_id = ? ORDER BY olusturma DESC, id DESC",[$kullaniciId]);
}

function puanVerilebilenMenuler(int $kullaniciId): array{
    return listele("SELECT r.menu_id,DATE_FORMAT(m.tarih, '%Y-%m-%d') AS tarih,y.ad AS yemekhane,o.ad AS ogun
    FROM rezervasyonlar r JOIN menuler m ON m.id = r.menu_id JOIN yemekhaneler y ON y.id = m.yemekhane_id JOIN ogunler o ON o.id = m.ogun_id
    LEFT JOIN degerlendirmeler d ON d.kullanici_id = r.kullanici_id AND d.menu_id = r.menu_id WHERE r.kullanici_id = ? AND r.durum = 'kullanildi'
    AND d.id IS NULL ORDER BY m.tarih DESC",[$kullaniciId]);
}

function degerlendirmeKaydet(int $kullaniciId,int $menuID,int $puan,string $yorum): bool{
    $sorgu = mySQLbaglantisi(true)->prepare("INSERT INTO degerlendirmeler (kullanici_id, menu_id, puan, yorum) SELECT ?, ?, ?, ? FROM DUAL
    WHERE EXISTS (SELECT 1 FROM rezervasyonlar WHERE kullanici_id = ? AND menu_id = ? AND durum = 'kullanildi')");
    $sorgu->execute([$kullaniciId,$menuID,$puan,$yorum === '' ? null : $yorum,$kullaniciId,$menuID]);
    return $sorgu->rowCount() === 1;
}

function yoneticiMenuListesi(): array{
    return listele("SELECT m.id,DATE_FORMAT(m.tarih, '%Y-%m-%d') AS tarih,m.yemekhane_id,m.ogun_id,m.ogrenci_fiyati,m.personel_fiyati,
    y.ad AS yemekhane,o.ad AS ogun,d.kapasite,d.rezerve,d.bos_yer,d.doluluk_yuzde,p.oy_sayisi,p.ortalama_puan FROM menuler m
    JOIN yemekhaneler y ON y.id = m.yemekhane_id JOIN ogunler o ON o.id = m.ogun_id JOIN v_menu_doluluk d ON d.menu_id = m.id
    JOIN v_menu_puan p ON p.menu_id = m.id ORDER BY m.tarih DESC, o.sira");
}
function MenuYemekleri(int $menuID): array{
    $satirlar = listele('SELECT yemek_id FROM menu_yemekleri WHERE menu_id = ?',[$menuID]);
    return array_map('intval',array_column($satirlar, 'yemek_id'));
}
function menuyuKaydet(?int $menuID, array $menuBilgisi): int{
    $db = mySQLbaglantisi(true);
    $db->beginTransaction();

    try {
        $bilgiler = [$menuBilgisi['yemekhane_id'],$menuBilgisi['tarih'],$menuBilgisi['ogun_id'],$menuBilgisi['ogrenci_fiyati'],$menuBilgisi['personel_fiyati']];

        if ($menuID === null) {
        $s = $db->prepare('INSERT INTO menuler(yemekhane_id, tarih, ogun_id,ogrenci_fiyati, personel_fiyati) VALUES (?, ?, ?, ?, ?)');
        $s->execute($bilgiler);
        $menuID = (int) $db->lastInsertId();
        } else {
        $s = $db->prepare('SELECT id FROM menuler WHERE id = ? FOR UPDATE');
        $s->execute([$menuID]);
        if (!$s->fetch()) {
        throw new DomainException('Menü bulunamadı.');
        }

        $s = $db->prepare('UPDATE menuler SET yemekhane_id = ?,tarih = ?,ogun_id = ?,ogrenci_fiyati = ?,personel_fiyati = ? WHERE id = ?');
        $s->execute([...$bilgiler, $menuID]);
        }

        $s = $db->prepare('DELETE FROM menu_yemekleri WHERE menu_id = ?');
        $s->execute([$menuID]);

        $s = $db->prepare('INSERT INTO menu_yemekleri (menu_id, yemek_id) VALUES (?, ?)');

        foreach ($menuBilgisi['yemek_ids'] as $yemekId) {
            $s->execute([$menuID, $yemekId]);
        }
        $db->commit();
        return $menuID;

    } catch (Throwable $hata) {

        if ($db->inTransaction()) {
        $db->rollBack();
        }
        throw $hata;
    }
}
