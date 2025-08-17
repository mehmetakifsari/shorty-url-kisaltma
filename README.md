# Shorty URL Kısaltma

🔗 Basit, hızlı ve güvenli bir **PHP tabanlı URL kısaltma sistemi**.  
Kolay kurulum için `install.php` dosyası ile gelir.  
Veritabanı otomatik oluşturulur, QR kod desteği ile birlikte çalışır.

---

## 🚀 Özellikler

- PHP 8+ desteği  
- PDO tabanlı veritabanı bağlantısı  
- Yönetim paneli (`admin.php`)  
- URL başlığı ve durum kontrolü  
- QR kod üretimi (`images/qr` dizininde)  
- Otomatik kurulum sihirbazı (`install.php`)  
- Güvenlik için kurulum sonrası `install.lock`  

---

## 📂 Kurulum

1. **Projeyi indir veya kopyala**
   ```bash
   git clone https://github.com/mehmetakifsari/shorty-url-kisaltma.git
   ```
   veya ZIP olarak indir:  
   [📦 GitHub ZIP](https://github.com/mehmetakifsari/shorty-url-kisaltma/archive/refs/heads/main.zip)

2. **Sunucuya yükle**  
   Dosyaları FTP veya terminal üzerinden web dizinine aktar.

3. **Kurulum sihirbazını aç**  
   Tarayıcında şu adrese git:  
   ```
   https://alanadiniz.com/install.php
   ```

4. **Veritabanı bilgilerini gir**  
   - Host: `localhost`  
   - Port: `3306`  
   - Veritabanı adı  
   - Kullanıcı adı  
   - Şifre  

5. **Kur butonuna bas**  
   - `images/qr` klasörü otomatik oluşturulur  
   - GitHub’dan güncel dosyalar indirilir  
   - `index.php` içine tek seferlik DB kurulum kodu eklenir  
   - Kurulum tamamlanınca `install.lock` dosyası oluşur  

---

## ⚙️ Gereksinimler

- **PHP >= 8.0**  
- **MySQL >= 5.7**  
- PHP uzantıları:  
  - `pdo`  
  - `pdo_mysql`  
  - `mbstring`  
  - `json`  
  - `curl`  
  - `openssl`  
  - `fileinfo`  
  - `zip`  

---

## 🔑 Yönetici Paneli

- `admin.php` üzerinden kısa link yönetimi yapılır.  
- Giriş sistemi `auth.php` dosyası ile sağlanır.  

---

## 📸 QR Kodlar

- Tüm linkler için QR kod üretilebilir.  
- QR görselleri `images/qr/` dizinine kaydedilir.  

---

## 🔒 Güvenlik

- Kurulum tamamlandıktan sonra **install.php** dosyasını silmeniz önerilir.  
- Sistem kurulum sırasında otomatik olarak `install.lock` dosyası üretir.  

---

## 🤝 Katkıda Bulunma

1. Fork yap  
2. Yeni branch oluştur (`feature/yeni-ozellik`)  
3. Commit işlemini yap  
4. Pull Request gönder  

---

## 📄 Lisans

Bu proje **MIT Lisansı** ile lisanslanmıştır.  
Dilediğiniz gibi kullanabilir ve geliştirebilirsiniz.  
