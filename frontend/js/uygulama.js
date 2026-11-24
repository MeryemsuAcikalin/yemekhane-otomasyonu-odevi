let kullanici = null;
let guvenlikAnahtari = '';
let secenekler = null;
let yoneticiMenuBilgisi = [];

function eleman(id){
    return document.getElementById(id);
}

function guvenlik(metin){
    return String(metin ?? '').replace(/[&<>"']/g,karakter => ({'&': '&amp;','<': '&lt;','>': '&gt;','"': '&quot;',"'": '&#39;'}[karakter]));
}

function tarih(){
    const bugun = new Date();
    const gun_ay_yil = new Intl.DateTimeFormat('en-GB',{timeZone: 'Europe/Istanbul',year: 'numeric',month: '2-digit',day: '2-digit'}).formatToParts(bugun);

    const yil = gun_ay_yil.find(parca => parca.type === 'year').value;
    const ay = gun_ay_yil.find(parca => parca.type === 'month').value;
    const gun = gun_ay_yil.find(parca => parca.type === 'day').value;
    return `${yil}-${ay}-${gun}`;
}

function bildirimMesaji(mesaj,hataMi = false){
    eleman('mesaj').textContent = mesaj;
    eleman('mesaj').classList.toggle('hata',hataMi);
}

async function istek(adres,metot = 'GET',veriler = null){
    const ayarlar = {method: metot,credentials: 'same-origin',headers: {}};
    if (veriler !== null){
        ayarlar.headers['Content-Type'] = 'application/json';
        ayarlar.body = JSON.stringify(veriler);
    }

    if (metot !== 'GET' && guvenlikAnahtari){
        ayarlar.headers['X-CSRF-Token'] = guvenlikAnahtari;
    }

    const sunucuCevabi = await fetch(adres,ayarlar);
    const cevap = await sunucuCevabi.json();
    if (!sunucuCevabi.ok){
        throw new Error(cevap.hata || 'İşlem gerçekleştirilemedi.');
    }

    return cevap;
}

function secenekleriYaz(id,secenekler){
    eleman(id).innerHTML = secenekler.map(veri =>`<option value="${Number(veri.id)}">${guvenlik(veri.ad)}</option>`).join('');
}

function girisEkraniniGoster(){
    kullanici = null;
    guvenlikAnahtari = '';
    secenekler = null;
    eleman('girisEkrani').hidden = false;
    eleman('uygulama').hidden = true;
    eleman('hesap').hidden = true;
    eleman('girisForm').reset();
}

async function anaSayfayiGoster(bilgiler){
    kullanici = bilgiler.kullanici;
    guvenlikAnahtari = bilgiler.csrf;
    eleman('girisEkrani').hidden = true;
    eleman('uygulama').hidden = false;
    eleman('hesap').hidden = false;
    eleman('kullaniciAdi').textContent = `${kullanici.ad} ${kullanici.soyad} (${kullanici.rol})`;
    eleman('yonetimSekme').hidden = kullanici.rol !== 'yonetici';

    secenekler = await istek('/api/secenekler');
    secenekleriYaz('yemekhane',secenekler.yemekhaneler);
    secenekleriYaz('ogun',secenekler.ogunler);
    secenekleriYaz('menuYemekhane',secenekler.yemekhaneler);
    secenekleriYaz('menuOgun',secenekler.ogunler);
    eleman('menuTarih').value = tarih();
    eleman('yemekKutulari').innerHTML = `<div class="yemekSecenekleri">${secenekler.yemekler.map(yemek =>
        `<label><input type="checkbox" name="yemek" value="${Number(yemek.id)}"><span>${guvenlik(yemek.ad)} (${guvenlik(yemek.kategori)})</span></label>`).join('')}</div>`;
    bildirimMesaji('');
    await sayfaAc('bugun');
}

async function sayfaAc(ad){
    document.querySelectorAll('.sayfa').forEach(sayfa => {sayfa.hidden = sayfa.id !== ad;});
    document.querySelectorAll('.gezinme button').forEach(buton => {buton.classList.toggle('aktif',buton.dataset.sayfa === ad);});

    bildirimMesaji('');
    try{
        if (ad === 'bugun'){
            await gununMenusunuGetir();
        }else if (ad === 'hafta'){
            await haftalikMenuleriGetir();
        }else if (ad === 'rez'){
            await rezervasyonBilgileriniGetir();
        }else if (ad === 'bakiye'){
            await bakiyeyiGetir();
        }else if (ad === 'puan'){
            await puanlanabilirMenuleriGetir();
        }else if (ad === 'yonetim'){
            await yoneticiMenuleriniGetir();
        }else if (ad === 'profil'){
           await profilBilgileriniGetir();
        }
    }catch(hata){
        bildirimMesaji(hata.message,true);
    }
}
async function profilBilgileriniGetir(){
    const bilgiler = await istek('/api/profil');
    const secili = bilgiler.secili_alerjenler.map(Number);
    eleman('alerjenSecimleri').innerHTML = bilgiler.alerjenler.map(alerjen => `<label style="display:block; margin:10px 0;"> <input type="checkbox" name="alerjen"
    value="${Number(alerjen.id)}" ${secili.includes(Number(alerjen.id)) ? 'checked' : ''}> ${guvenlik(alerjen.ad)}</label>`).join('');
}
async function gununMenusunuGetir(){
    const yemekhane = eleman('yemekhane').value;
    const ogun = eleman('ogun').value;

    if (!yemekhane || !ogun){
        eleman('gunlukMenuSonucu').textContent = 'Yemekhane ve öğün seçiniz.';
        return;
    }

    const sonuc = await istek(`/api/bugun?yemekhane=${encodeURIComponent(yemekhane)}&ogun=${encodeURIComponent(ogun)}`);
    if (!sonuc.yemekler.length){
        eleman('gunlukMenuSonucu').textContent = 'Bugün için bu öğünde menü bulunamadı.';
        return;
    }

    const yemekler = sonuc.yemekler.map(yemek =>
        `<div class="kart">
            <h3>${guvenlik(yemek.yemek)}</h3>
            <p>Kategori: ${guvenlik(yemek.kategori)}</p>
            <p>Kalori: ${guvenlik(yemek.kalori)}</p>
            <p>${Number(yemek.vejetaryen) === 1 ? 'Vejetaryen' : 'Vejetaryen değil'}</p>
            <p>Alerjenler: ${guvenlik(yemek.alerjenler || 'Belirtilmemiş')}</p>
       ${yemek.alerjen_uyarisi ? '<p style="color:#b42318;font-weight:bold;">Dikkat bu yemekte seçtiğiniz bir alerjen bulunmakta.</p>': ''}
        </div>`
    ).join('');
    eleman('gunlukMenuSonucu').innerHTML =
        `<h3>${guvenlik(sonuc.yemekler[0].yemekhane)} - ${guvenlik(sonuc.yemekler[0].ogun)}</h3>
        <p>Toplam kalori: ${guvenlik(sonuc.toplamKalori)}</p>
        <div class="kartlar">${yemekler}</div>`;
}

async function haftalikMenuleriGetir(){
    const [menuler,rezervasyonlar] = await Promise.all([
        istek('/api/hafta'),
        istek('/api/rezervasyonlar')
    ]);

    if (!menuler.length){
        eleman('haftaSonuc').textContent = 'Bu hafta için menü bulunamadı.';
        return;
    }

    eleman('haftaSonuc').innerHTML = `<div class="kartlar">${menuler.map(menu => {
        const mevcutRezervasyon = rezervasyonlar.find(rezervasyon =>
            Number(rezervasyon.menu_id) === Number(menu.menu_id) ||
            (
                rezervasyon.tarih === menu.tarih &&
                rezervasyon.yemekhane === menu.yemekhane &&
                rezervasyon.ogun === menu.ogun
            )
        );

        const durum = mevcutRezervasyon ? mevcutRezervasyon.durum : 'yok';
        const acik = Number(menu.acik) === 1;
        const fiyat = kullanici.rol === 'ogrenci' ? menu.ogrenci_fiyati : menu.personel_fiyati;
        const yarimSecilebilir = menu.ogun === 'Öğle' || menu.ogun === 'Akşam';
        const yarimFiyat = (Math.round(Number(fiyat) * 60) / 100).toFixed(2);
        const porsiyonSecimi = acik && durum !== 'aktif' && durum !== 'kullanildi' ? 
        `<label>Porsiyon
        <select data-porsiyon="${Number(menu.menu_id)}">
            <option value="tam">Tam porsiyon - ${guvenlik(fiyat)} TL</option>
            ${yarimSecilebilir ? `<option value="yarim">Yarım porsiyon - ${yarimFiyat} TL</option>`: ''}
        </select>
       </label>`: '';
        let buton = '';

        if (durum === 'aktif' || durum === 'kullanildi'){
            buton = `<button type="button" disabled>Zaten Rezervasyonunuz Var</button>`;
        }else if (!acik){
            buton = `<button type="button" disabled>Rezervasyon Kapalı</button>`;
        }else if (durum === 'iptal'){
            buton = `<button type="button" data-rezervasyon="${Number(menu.menu_id)}">Yeniden Rezervasyon Yap</button>`;
        }else{
            buton = `<button type="button" data-rezervasyon="${Number(menu.menu_id)}">Rezervasyon Yap</button>`;
        }

        return `<div class="kart">
            <h3>${guvenlik(menu.tarih)} - ${guvenlik(menu.ogun)}</h3>
            <p>Yemekhane: ${guvenlik(menu.yemekhane)}</p>
            <p>Menü No: ${Number(menu.menu_id)}</p>
            <p>Fiyat: ${guvenlik(fiyat)} TL</p>
            <p>Boş yer: ${guvenlik(menu.bos_yer)} / ${guvenlik(menu.kapasite)}</p>
            <p>Doluluk: %${guvenlik(menu.doluluk_yuzde)}</p>
            <p>Son rezervasyon: ${guvenlik(menu.son_saat)}</p>
            <p>Rezervasyon durumunuz: ${guvenlik(durum)}</p>
            ${porsiyonSecimi}
            ${buton}
        </div>`;
    }).join('')}</div>`;
}

async function rezervasyonBilgileriniGetir(){
    const rezervasyonlar = await istek('/api/rezervasyonlar');

    if (!rezervasyonlar.length){
        eleman('rezSonuc').textContent = 'Henüz rezervasyonunuz bulunmuyor.';
        return;
    }

    eleman('rezSonuc').innerHTML = `<div class="kartlar">${rezervasyonlar.map(rezervasyon =>
        `<div class="kart">
            <h3>${guvenlik(rezervasyon.tarih)} - ${guvenlik(rezervasyon.ogun)}</h3>
            <p>Yemekhane: ${guvenlik(rezervasyon.yemekhane)}</p>
            <p>Durum: ${guvenlik(rezervasyon.durum)}</p>
            <p>Porsiyon: ${rezervasyon.porsiyon === 'yarim' ? 'Yarım' : 'Tam'}</p>           
            <p>Ücret: ${guvenlik(rezervasyon.ucret)} TL</p>
            <p>İptal için son saat: ${guvenlik(rezervasyon.son_saat)}</p>
            ${Number(rezervasyon.iptal_acik) === 1 ? `<button type="button" data-iptal="${Number(rezervasyon.id)}">Rezervasyonu İptal Et</button>` : ''}
        </div>`
    ).join('')}</div>`;
}

function bakiyeHareketBilgileri(hareketler){
    if (!hareketler.length){
        return '<p>Henüz bakiye hareketi bulunmuyor.</p>';
    }

    return `<div class="tabloAlani"><table>
        <thead><tr><th>Tarih</th><th>İşlem</th><th>Tutar</th><th>Açıklama</th></tr></thead>
        <tbody>${hareketler.map(hareket =>
            `<tr><td>${guvenlik(hareket.tarih)}</td><td>${guvenlik(hareket.tur)}</td><td>${guvenlik(hareket.tutar)} TL</td><td>${guvenlik(hareket.aciklama)}</td></tr>`
        ).join('')}</tbody>
    </table></div>`;
}

async function bakiyeyiGetir(){
    const BakiyeBilgisi = await istek('/api/bakiye');
    eleman('bakiyeDeger').textContent = `${BakiyeBilgisi.bakiye ?? '0.00'} TL`;
    eleman('hareketSonuc').innerHTML = bakiyeHareketBilgileri(BakiyeBilgisi.hareketler || []);
}

async function puanlanabilirMenuleriGetir(){
    const menuler = await istek('/api/uygun-menuler');
    eleman('puanMenu').innerHTML = menuler.map(menu =>
        `<option value="${Number(menu.menu_id)}">${guvenlik(menu.tarih)} - ${guvenlik(menu.yemekhane)} - ${guvenlik(menu.ogun)}</option>`
    ).join('');
    eleman('puanForm').querySelector('button[type="submit"]').disabled = menuler.length === 0;
    if (!menuler.length){
        bildirimMesaji('Değerlendirebileceğiniz kullanılmış bir menü bulunmuyor.');
    }
}

async function yoneticiMenuleriniGetir(){
    const menuler = await istek('/api/yonetim');
    yoneticiMenuBilgisi = menuler;
    if (!menuler.length){
        eleman('yonetimListe').textContent = 'Henüz menü bulunmuyor.';
        return;
    }

    eleman('yonetimListe').innerHTML = `<div class="tabloAlani"><table>
        <thead><tr><th>Tarih</th><th>Yemekhane</th><th>Öğün</th><th>Doluluk</th><th>Oy</th><th>Ortalama</th><th>İşlem</th></tr></thead>
        <tbody>${menuler.map(menu =>
            `<tr>
                <td>${guvenlik(menu.tarih)}</td>
                <td>${guvenlik(menu.yemekhane)}</td>
                <td>${guvenlik(menu.ogun)}</td>
                <td>%${guvenlik(menu.doluluk_yuzde)}</td>
                <td>${guvenlik(menu.oy_sayisi)}</td>
                <td>${guvenlik(menu.ortalama_puan ?? 'Henüz yok')}</td>
                <td><button type="button" data-duzenle="${Number(menu.id)}">Düzenle</button></td>
            </tr>`
        ).join('')}</tbody>
    </table></div>`;
}

async function menuyuDuzenle(id){
    const menu = yoneticiMenuBilgisi.find(kayit => Number(kayit.id) === id);
    if (!menu){
        throw new Error('Menü bulunamadı.');
    }

    const yemekler = await istek(`/api/yonetim/${id}/yemekler`);
    eleman('menuId').value = menu.id;
    eleman('menuTarih').value = menu.tarih;
    eleman('menuYemekhane').value = menu.yemekhane_id;
    eleman('menuOgun').value = menu.ogun_id;
    eleman('ogrFiyat').value = menu.ogrenci_fiyati;
    eleman('perFiyat').value = menu.personel_fiyati;
    document.querySelectorAll('#yemekKutulari input[name="yemek"]').forEach(kutu => {kutu.checked = yemekler.includes(Number(kutu.value));});
    eleman('formBaslik').textContent = 'Menüyü Düzenle';
    eleman('menuForm').scrollIntoView({behavior: 'smooth',block: 'start'});
}

function yeniMenu(){
    eleman('menuForm').reset();
    eleman('menuId').value = '';
    eleman('menuTarih').value = tarih();
    eleman('formBaslik').textContent = 'Menü ekle';

}

async function hatalariBul(islem){
    try{
        await islem();
    }catch(hata){
        bildirimMesaji(hata.message,true);
        alert(hata.message);
    }
}

function formlariVeButonlariHazirla(){
    eleman('girisForm').addEventListener('submit',olay => {olay.preventDefault();hatalariBul(async () => {const bilgiler = await istek('/api/giris','POST',{
    eposta: eleman('eposta').value.trim(),sifre: eleman('sifre').value});
    await anaSayfayiGoster(bilgiler);
        });
    });

    eleman('cikis').addEventListener('click',() => {hatalariBul(async () => {
            await istek('/api/cikis','POST',{});
            girisEkraniniGoster();
            bildirimMesaji('Oturum kapatıldı.');
        });
    });

    eleman('gunlukMenuFormu').addEventListener('submit',olay => {
        olay.preventDefault();
        hatalariBul(gununMenusunuGetir);
    });

    eleman('yukleForm').addEventListener('submit',olay => {
        olay.preventDefault();
        hatalariBul(async () => {const sonuc = await istek('/api/yukleme','POST',{tutar: eleman('tutar').value});
            await bakiyeyiGetir();
            eleman('yukleForm').reset();
            bildirimMesaji(sonuc.mesaj);
        });
    });

    eleman('puanForm').addEventListener('submit',olay => {
        olay.preventDefault();
        hatalariBul(async () => {const sonuc = await istek('/api/puan','POST',{
                menu_id: Number(eleman('puanMenu').value),
                puan: Number(eleman('puanDeger').value),
                yorum: eleman('yorum').value
            });
            eleman('yorum').value = '';
            await puanlanabilirMenuleriGetir();
            bildirimMesaji(sonuc.mesaj);
        });
    });

    eleman('menuForm').addEventListener('submit',olay => {
        olay.preventDefault();
        hatalariBul(async () => {
            const id = eleman('menuId').value;
            const bilgiler = {
                tarih: eleman('menuTarih').value,
                yemekhane_id: Number(eleman('menuYemekhane').value),
                ogun_id: Number(eleman('menuOgun').value),
                ogrenci_fiyati: eleman('ogrFiyat').value,
                personel_fiyati: eleman('perFiyat').value,
                yemek_ids: Array.from(document.querySelectorAll('#yemekKutulari input[name="yemek"]:checked')).map(kutu => Number(kutu.value))
            };

            const adres = id ? `/api/yonetim/${id}` : '/api/yonetim';
            const metot = id ? 'PUT' : 'POST';
            const sonuc = await istek(adres,metot,bilgiler);
            yeniMenu();
            await yoneticiMenuleriniGetir();
            bildirimMesaji(sonuc.mesaj);
        });
    });

    eleman('menuSifirla').addEventListener('click',yeniMenu);
    document.addEventListener('click',olay => {
        const sekme = olay.target.closest('[data-sayfa]');
        if (sekme){
            hatalariBul(() => sayfaAc(sekme.dataset.sayfa));
            return;
        }

        const rezervasyon = olay.target.closest('[data-rezervasyon]');
        if (rezervasyon){
            hatalariBul(async () => {
                const menuId = Number(rezervasyon.dataset.rezervasyon);
                const porsiyonKutusu = rezervasyon.closest('.kart').querySelector('[data-porsiyon]');
                const porsiyon = porsiyonKutusu ? porsiyonKutusu.value : 'tam';
                rezervasyon.disabled = true;

                try{
                const sonuc = await istek('/api/rezervasyon','POST',{menu_id: menuId,porsiyon: porsiyon});
                    await haftalikMenuleriGetir();
                    bildirimMesaji(`${sonuc.mesaj} Güncel bakiye: ${sonuc.bakiye} TL`);
                }catch(hata){
                    rezervasyon.disabled = false;
                    bildirimMesaji(`Menü ${menuId}: ${hata.message}`,true);
                    alert(`Menü ${menuId}: ${hata.message}`);
                }
            });
            return;
        }

        const iptalButonu = olay.target.closest('[data-iptal]');
        if (iptalButonu){
            hatalariBul(async () => {
                iptalButonu.disabled = true;

                try{
                    const sonuc = await istek(`/api/rezervasyon/${iptalButonu.dataset.iptal}/iptal`,'POST',{});
                    await rezervasyonBilgileriniGetir();
                    bildirimMesaji(`${sonuc.mesaj} Güncel bakiye: ${sonuc.bakiye} TL`);
                }catch(hata){
                    iptalButonu.disabled = false;
                    throw hata;
                }
            });
            return;
        }

        const duzenleButonu = olay.target.closest('[data-duzenle]');
        if (duzenleButonu){
            hatalariBul(() => menuyuDuzenle(Number(duzenleButonu.dataset.duzenle)));
        }
    });
    eleman('alerjenForm').addEventListener('submit',olay => {
    olay.preventDefault();
    hatalariBul(async () => {
        const alerjenler = Array.from(
            document.querySelectorAll(
                '#alerjenSecimleri input[name="alerjen"]:checked'
            )
        ).map(kutu => Number(kutu.value));
        const sonuc = await istek('/api/profil','PUT',{
            alerjen_ids: alerjenler
        });
        bildirimMesaji(sonuc.mesaj);
    });
});

}

async function uygulamayiBaslat(){
    formlariVeButonlariHazirla();
    try{
        const oturumbilgileri = await istek('/api/oturum');
        if (oturumbilgileri.kullanici && oturumbilgileri.csrf){
            await anaSayfayiGoster(oturumbilgileri);
        }else{
            girisEkraniniGoster();
        }
    }catch(hata){
        girisEkraniniGoster();
        bildirimMesaji('Sunucu bağlantısı kurulamadı.',true);
    }
}

uygulamayiBaslat();