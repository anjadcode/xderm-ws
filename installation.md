# Panduan Instalasi Xderm Mini GUI di OpenWrt `aarch64_generic`

Panduan lengkap untuk menginstal dan mengonfigurasi **Xderm Mini GUI** dengan dukungan **SSH WebSocket (WS/WSS) CDN**, **Dual-Stack Firewall (iptables + nftables/fw4)**, dan **Internet Tunneling Penuh** pada arsitektur OpenWrt **`aarch64_generic`** (STB Amlogic S905X/S905X3 seperti HG680P, B860H, Raspberry Pi 4, Rockchip, dll.).

---

## 1. Prasyarat Sistem & Paket Dependensi

Pastikan perangkat OpenWrt terhubung ke internet untuk instalasi paket melalui terminal (SSH / LuCI Terminal).

### A. Update Package Feed & Instal Paket Utama
Jalankan perintah berikut di terminal OpenWrt:

```bash
opkg update
opkg install kmod-tun badvpn-tun2socks coreutils-base64 coreutils-timeout httping \
  v2ray-core corkscrew procps-ng-ps git curl sshpass openssh-client \
  openssl-util https-dns-proxy python3 python3-pip
```

### B. Instal Modul Web Server (PHP 7 atau PHP 8)
- **Untuk OpenWrt 18.06 / 19.07 / 21.02 (PHP 7):**
  ```bash
  opkg install php7 php7-cgi php7-mod-session
  ```
- **Untuk OpenWrt 22.03 / 23.05+ (PHP 8):**
  ```bash
  opkg install php8 php8-cgi php8-mod-session
  ```

### C. Instal Pustaka Python Pendukung
```bash
python3 -m pip install requests beautifulsoup4
```

> **Catatan Paket Arsitektur `aarch64_generic`:**
> Repositori ini telah menyertakan paket biner pra-kompilasi ARM64:
> - `corkscrew_2.0-Rureka.com_aarch64_cortex-a53.ipk`
> - `trojan_aarch64_cortex-a53`
>
> Jika `corkscrew` belum tersedia dari repositori resmi opkg Anda, instal langsung dengan:
> ```bash
> opkg install /www/xderm/corkscrew_2.0-Rureka.com_aarch64_cortex-a53.ipk
> ```

---

## 2. Langkah Instalasi Xderm Mini

### Metode: Clone Repositori Langsung ke `/www/xderm`

1. Bersihkan instalasi lama (jika ada) dan clone repositori ini:
   ```bash
   rm -rf /www/xderm
   git clone https://github.com/anjadcode/xderm-ws /www/xderm
   ```

2. Berikan izin eksekusi (*executable permissions*) pada skrip utama:
   ```bash
   chmod +x /www/xderm/xderm-mini
   chmod +x /www/xderm/xderm_ws.py
   chmod +x /www/xderm/installer
   chmod +x /www/xderm/adds/xdrauth
   chmod +x /www/xderm/adds/xdrtool
   ```

3. Daftarkan CLI Tool ke `/bin`:
   ```bash
   ln -sf /www/xderm/adds/xdrauth /bin/xdrauth
   ln -sf /www/xderm/adds/xdrtool /bin/xdrtool
   ```

4. Buat file autentikasi login awal:
   ```bash
   echo -e "user=admin\npasswd=xderm" > /root/auth.txt
   ```

5. Pastikan uhttpd mendukung interpreter PHP:
   ```bash
   uci set uhttpd.main.interpreter='.php=/usr/bin/php-cgi'
   uci commit uhttpd
   /etc/init.d/uhttpd restart
   ```

---

## 3. Konfigurasi SSH WebSocket CDN (CloudFront)

### A. Menggunakan WebUI (Direkomendasikan)
1. Buka browser dan akses halaman Xderm:
   ```text
   http://192.168.1.1/xderm
   ```
2. Klik tombol **Config**.
3. Pada tab **Form Editor (Mudah)**, isi parameter akun Anda:
   - **Host / CDN Domain**: `dz1wsoabehhmc.cloudfront.net`
   - **Port**: `443`
   - **SNI Bug**: `dz1wsoabehhmc.cloudfront.net`
   - **Username**: `xxxx` (username akun SSH Anda)
   - **Password**: `xxxx` (password akun SSH Anda)
   - **UDPgw Port**: `7300`
   - **Payload**: Klik tombol **`+ CloudFront WS Preset`** atau masukkan:
     ```http
     GET / HTTP/1.1[crlf]Host: dz1wsoabehhmc.cloudfront.net[crlf]Upgrade: websocket[crlf][crlf]
     ```
4. Pada pilihan **Mode**, pilih: **`SSH-WS.`**
5. Opsi tambahan:
   - Centang **`stunnel`** : Jangan dicentang (karena menggunakan native Python WSS).
   - Centang **`Restart Firewall`** : Disarankan dicentang.
   - Centang **`Auto ON-Boot`** : Centang jika ingin Xderm otomatis aktif saat router menyala.
6. Klik **Simpan Konfigurasi**.

---

### B. Menggunakan Terminal Manual (`config.txt`)
Anda juga dapat langsung menyalin konfigurasi berikut ke `/www/xderm/config.txt` dan `/www/xderm/config/config1`:

```ini
host=dz1wsoabehhmc.cloudfront.net
port=443
pudp=7300
user=xxxx
pass=xxxx
sni=dz1wsoabehhmc.cloudfront.net
payload=GET / HTTP/1.1[crlf]Host: dz1wsoabehhmc.cloudfront.net[crlf]Upgrade: websocket[crlf][crlf]
mode=SSH-WS.
```

Aktifkan default profile:
```bash
echo "config1" > /www/xderm/config/default
echo "SSH-WS." > /www/xderm/config/mode.default
```

---

## 4. Menjalankan & Menguji Terowongan (Tunneling)

### A. Melalui WebUI
1. Buka `http://192.168.1.1/xderm`.
2. Klik tombol **Start** (tombol akan berubah menjadi merah bertuliskan **Stop**).
3. Status badge di atas akan beralih dari:
   - 🔴 `● Disconnected` $\to$ 🟡 `● Connecting...` $\to$ 🟢 **`● Connected`**
4. Klik tab **Log** untuk memantau proses tunneling secara langsung.

### B. Melalui Terminal Command Line
- **Memulai Tunnel:**
  ```bash
  /www/xderm/xderm-mini start
  ```
- **Menghentikan Tunnel:**
  ```bash
  /www/xderm/xderm-mini stop
  ```
- **Melihat Log Real-Time:**
  ```bash
  tail -f /www/xderm/screenlog.0
  ```

---

## 5. Fitur Jaringan & Firewall di OpenWrt aarch64

XDERM Mini versi ini telah dilengkapi dengan arsitektur jaringan canggih:

1. **Dual-Stack Firewall (`nftables` & `iptables`)**:
   - Berjalan otomatis di **OpenWrt 22.03, 23.05, 24.x (`firewall4` / nftables)** menggunakan isolated table `inet xderm`.
   - Tetap mendukung **OpenWrt 18.06, 19.07, 21.02 (`firewall3` / iptables)** sebagai fallback.
2. **WAN Anti-Loop Routing**:
   - Alamat CDN `$host` dan `$sni` otomatis di-resolve dan diberikan rute statis melalui WAN fisik modem/ethernet agar koneksi injektor tidak tersedot (*looping*) ke dalam interface `tun0`.
3. **DNS Anti-Leak & Redirection**:
   - Seluruh query DNS (port 53 UDP/TCP) dari klien LAN/Wi-Fi otomatis dibelokkan ke resolver lokal/DoH (`https-dns-proxy`) untuk mencegah kebocoran DNS.
4. **UDP Gaming & Voice (`badvpn-tun2socks`)**:
   - Mengalirkan paket UDP game online dan voice call via UDP Gateway port `7300`.

---

## 6. Uji Konektivitas Klien (Verifikasi Tunnel)

Setelah terhubung (status `200 OK` di log):
1. Sambungkan HP atau Laptop ke Wi-Fi / LAN OpenWrt.
2. Buka terminal atau browser di perangkat klien dan periksa alamat IP publik:
   ```text
   https://ifconfig.me
   atau
   https://ipinfo.io
   ```
3. IP yang terdeteksi adalah IP server SSH / CDN CloudFront, bukan IP asli kartu SIM/ISP.
4. Uji kebocoran DNS di `https://dnsleaktest.com` untuk memastikan tidak ada DNS leak.

---

## 7. Perintah Terminal Tambahan

| Perintah | Deskripsi |
|---|---|
| `xdrtool` | Membuka menu manajemen interaktif Xderm di terminal |
| `xdrauth` | Mengubah username & password login WebUI |
| `/www/xderm/xderm-mini start` | Menjalankan injector di background |
| `/www/xderm/xderm-mini stop` | Menghentikan injector & membersihkan interface tun0 |
| `python3 -m unittest discover /www/xderm/tests` | Menjalankan seluruh pengujian otomatis (16 unit tests) |
