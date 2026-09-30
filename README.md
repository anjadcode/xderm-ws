<p align="center">
  <img src="https://raw.githubusercontent.com/anjadcode/xderm-ws/main/xderm-logo/xderm-icon-256px.png" height="130" alt="Xderm WS Logo"/>
  <br>
  <h1 align="center">XDERM-MINI WS</h1>
  <p align="center">
    <b>OpenWrt Full Internet Tunneling Injector with SSH WebSocket (WS/WSS) CDN & Dual-Stack Firewall (nftables + iptables)</b>
  </p>
  <p align="center">
    <img src="https://img.shields.io/badge/OpenWrt-18.06%20~%2024.x-blue?style=flat-square&logo=openwrt" alt="OpenWrt version"/>
    <img src="https://img.shields.io/badge/Architecture-aarch64__generic-emerald?style=flat-square" alt="Architecture aarch64"/>
    <img src="https://img.shields.io/badge/Firewall-nftables%20%2B%20iptables-orange?style=flat-square" alt="Firewall"/>
    <img src="https://img.shields.io/badge/Engine-Python%203%20Native-yellow?style=flat-square&logo=python" alt="Python Engine"/>
    <img src="https://img.shields.io/badge/Tests-19%20Passed-brightgreen?style=flat-square" alt="Tests Passed"/>
  </p>
</p>

---

## 🚀 Sekilas tentang Xderm-Mini WS

**Xderm-Mini WS** adalah evolusi dari Xderm-Mini GUI yang telah disempurnakan untuk mendukung **SSH WebSocket (WS/WSS) CDN Tunneling** (AWS CloudFront, Cloudflare, dll.), **Dual-Stack Firewall (OpenWrt fw4 `nftables` & fw3 `iptables`)**, serta antarmuka WebUI yang lebih bersih, ringan, dan mudah digunakan langsung dari ponsel maupun PC.

Dibangun khusus untuk router dan STB OpenWrt arsitektur **`aarch64_generic`** (Amlogic S905X/S905X3 seperti Fiberhome HG680P, ZTE B860H, Raspberry Pi 4, RK3328/RK3568, dll.) maupun arsitektur x86_64 dan MIPS.

---

## ✨ Fitur Utama

- 🌐 **Dukungan SSH WebSocket CDN (WS & WSS)**:
  - **Port 80 (WS)** unencrypted WebSocket tunnel.
  - **Port 443 (WSS)** WebSocket Secure dengan enkripsi TLS dan SNI.
  - Otomatis menangani respon `HTTP/1.1 101 Switching Protocols` dan header `Connection: Upgrade`.
  - Mesin Python 3 native tanpa ketergantungan binary eksternal yang rentan crash.
- 🛡️ **Dual-Stack Firewall (`nftables` / fw4 + `iptables` / fw3)**:
  - Kompatibel penuh dengan OpenWrt versi baru (22.03, 23.05, 24.x) menggunakan tabel `inet xderm` di `nftables`.
  - Tetap mendukung OpenWrt versi lama (18.06, 19.07, 21.02) dengan aturan `iptables`.
- 🔄 **Anti-Loop WAN Routing**:
  - Domain CDN dan bug SNI otomatis di-resolve dan diberikan rute statis ke gateway fisik WAN agar koneksi tidak mengalami routing loop saat interface `tun0` aktif.
- 🔒 **DNS Anti-Leak & Redirection**:
  - Seluruh query DNS (port 53 UDP/TCP) dari klien lokal (`br-lan`) otomatis dibelokkan ke resolver lokal / DoH (`https-dns-proxy`).
- 🎮 **UDP Gateway untuk Gaming & Voice**:
  - Terintegrasi dengan `badvpn-tun2socks` (`--udpgw-remote-server-addr 127.0.0.1:7300` & `--udpgw-transparent-dns`) untuk game online (Mobile Legends, PUBG) dan panggilan suara.
- 📱 **WebUI Minimalis & Mudah Digunakan**:
  - **Quick Profile Switcher**: Ganti akun/server (config1 ~ config5) langsung dari dashboard utama dengan 1 klik tanpa harus masuk menu config.
  - **Indikator Ping Real-Time**: Latensi koneksi internet (`⚡ 45 ms`) ditampilkan langsung di status bar.
  - **Form Editor Mode**: Input khusus Host, Port, SNI, Username, Password (dengan toggle 👁️), dan Payload.
  - **Tombol Preset 1-Klik**: Menyiapkan template payload CloudFront instan.
  - **Status Badge Real-Time**: Indikator koneksi langsung (`● Connected`, `● Connecting...`, `● Disconnected`).
  - **Log Tools**: Tombol Clear Log dan Copy Log instan.
  - **Dual-Sync**: Sinkronisasi otomatis dua arah antara Mode Form dan Raw Config Textarea.
- 📡 **Protokol Lain Tetap Didukung**:
  - `SSH` (Stunnel / Python SSL)
  - `Vmess` (V2Ray Core)
  - `Trojan` (Trojan VPN ARM64)
  - `Multi` (Auto-Switching Injector)

---

## 📦 Prasyarat Paket OpenWrt

Jalankan perintah berikut di terminal OpenWrt:

```bash
opkg update
opkg install kmod-tun badvpn-tun2socks coreutils-base64 coreutils-timeout httping \
  v2ray-core corkscrew procps-ng-ps git curl sshpass openssh-client \
  openssl-util https-dns-proxy python3 python3-pip
```

Pilih modul PHP sesuai versi OpenWrt:
- **OpenWrt 18.06 – 21.02**: `opkg install php7 php7-cgi php7-mod-session`
- **OpenWrt 22.03 – 24.x**: `opkg install php8 php8-cgi php8-mod-session`

Pustaka Python:
```bash
python3 -m pip install requests beautifulsoup4
```

---

## 📥 Instalasi Cepat

### Menggunakan Git (Sangat Direkomendasikan)

```bash
rm -rf /www/xderm
git clone https://github.com/anjadcode/xderm-ws /www/xderm
chmod +x /www/xderm/xderm-mini /www/xderm/xderm_ws.py /www/xderm/installer
chmod +x /www/xderm/adds/xdrauth /www/xderm/adds/xdrtool
ln -sf /www/xderm/adds/xdrauth /bin/xdrauth
ln -sf /www/xderm/adds/xdrtool /bin/xdrtool
echo -e "user=admin\npasswd=xderm" > /root/auth.txt
/etc/init.d/uhttpd restart
```

> 📖 **Panduan Lengkap:** Baca file **[installation.md](installation.md)** untuk petunjuk instalasi step-by-step di OpenWrt `aarch64_generic`.

---

## ⚙️ Konfigurasi Default (SSH WS CDN)

File konfigurasi berada di `/www/xderm/config.txt` atau dapat diedit langsung lewat WebUI (`http://192.168.1.1/xderm`):

```ini
host=dz1wsoabehhmc.cloudfront.net
port=443
pudp=7300
user=xxxx
pass=xxxx
sni=dz1wsoabehhmc.cloudfront.net
payload=GET / HTTP/1.1[crlf]Host: [host][crlf]Upgrade: websocket[crlf][crlf]
mode=SSH-WS.
```

---

## 🖥️ Penggunaan

### 1. Melalui WebUI Browser
- Buka: `http://192.168.1.1/xderm`
- Autentikasi default:
  - **Username**: `admin`
  - **Password**: `xderm` (ubah dengan perintah `xdrauth`)
- Klik **Config** untuk mengedit akun.
- Klik **Start** untuk memulai terowongan, pantau koneksi lewat tab **Log**.

### 2. Melalui Terminal
- **Memulai Tunnel**: `/www/xderm/xderm-mini start`
- **Menghentikan Tunnel**: `/www/xderm/xderm-mini stop`
- **Melihat Log**: `tail -f /www/xderm/screenlog.0`
- **Alat Konfigurasi CLI**: `xdrtool`

---

## 🧪 Pengujian Otomatis

Repositori ini dilengkapi dengan 16 unit test dan uji simulasi lingkungan OpenWrt:

```bash
python3 -m unittest discover tests -v
```

Hasil uji:
- ✅ Format template payload WebSocket & auto-insert `Connection: Upgrade`
- ✅ Handshake WSS nyata ke server CloudFront `dz1wsoabehhmc.cloudfront.net:443`
- ✅ Negosiasi proxy Corkscrew $\leftrightarrow$ OpenSSH banner
- ✅ Aturan firewall `nftables` (fw4) & `iptables` (fw3)
- ✅ Anti-loop WAN routing & DNS Anti-leak redirection
- ✅ Validasi sintaks shell POSIX (`bash -n`)

---

## 👥 Credits & Acknowledgments
- [Ryan Fauzi](https://github.com/ryanfauzi1) ~ Pengembang utama Xderm-Mini GUI original
- [Helmi Amirudin](https://github.com/helmiau) ~ Desain logo ikon XDRM & penyusun xdrtool
- [Agus Sriawan](https://www.facebook.com/agussriawan.id) ~ Desain tema Blue Light
- [Vito Harhari](https://github.com/vitoharhari) ~ Auto-installer STB
- [anjadcode](https://github.com/anjadcode) ~ Penambahan SSH WebSocket CDN Engine, Dual-Stack nftables/iptables, WebUI Form Editor, dan Test Suite

<br>
<h5 align="center">XDERM-MINI WS • OpenWrt aarch64_generic • 2026</h5>
