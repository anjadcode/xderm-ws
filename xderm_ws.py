#!/usr/bin/env python3
"""
XDerm-Mini SSH WebSocket (WS/WSS) CDN Injector Engine
Supports:
- HTTP WebSocket (Port 80)
- HTTPS WebSocket Secure (Port 443 with TLS & SNI)
- Custom HTTP Upgrade Payloads ([crlf], [host], [port])
- Corkscrew ProxyCommand & Direct Local Port Forwarding
Compatible with OpenWrt (Python 3.6+) on any architecture (aarch64, x86_64, mips, arm).
"""

import os
import sys
import re
import ssl
import time
import socket
import select
import threading
import argparse

def format_payload(payload_template: str, host: str = "", port: str = "443", sni: str = "") -> str:
    """Format user payload template into standard HTTP WebSocket request."""
    if not payload_template:
        payload_template = f"GET / HTTP/1.1[crlf]Host: {host}[crlf]Upgrade: websocket[crlf]Connection: Upgrade[crlf][crlf]"

    p = payload_template
    # Replace common placeholders
    p = p.replace("[host]", host)
    p = p.replace("[sni]", sni or host)
    p = p.replace("[port]", str(port))
    p = p.replace("[host_port]", f"{host}:{port}")

    # Replace newlines tokens
    p = p.replace("[crlf]", "\r\n")
    p = p.replace("[cr]", "\r")
    p = p.replace("[lf]", "\n")
    p = p.replace("[protocol]", "HTTP/1.1")

    # If payload requests Upgrade: websocket but lacks Connection header,
    # auto-add Connection: Upgrade for standard CDN WebSocket compliance (e.g. AWS CloudFront)
    if "upgrade: websocket" in p.lower() and "connection:" not in p.lower():
        p = re.sub(r'(?i)(upgrade:\s*websocket)', r'\1\r\nConnection: Upgrade', p)

    # Ensure it ends with double CRLF
    if not p.endswith("\r\n\r\n"):
        if p.endswith("\r\n"):
            p += "\r\n"
        elif p.endswith("\n\n"):
            p = p[:-2] + "\r\n\r\n"
        else:
            p += "\r\n\r\n"

    return p

def parse_config_text(content: str) -> dict:
    """Parse xderm config content into dictionary."""
    config = {}
    for line in content.splitlines():
        line = line.strip()
        if not line or line.startswith("#"):
            continue

        if "=" in line:
            key, val = line.split("=", 1)
            config[key.strip().lower()] = val.strip()
        elif ":" in line:
            key, val = line.split(":", 1)
            config[key.strip().lower()] = val.strip()

    return config

def load_config_file(filepath: str) -> dict:
    """Load and parse config file."""
    if not os.path.isfile(filepath):
        return {}
    with open(filepath, "r", encoding="utf-8", errors="ignore") as f:
        return parse_config_text(f.read())

def read_http_response(sock, buffer_size=4096, timeout=30):
    """
    Read HTTP response until header boundary (\r\n\r\n).
    Returns (status_code: int, extra_body_bytes: bytes).
    """
    resp_buffer = b""
    old_timeout = sock.gettimeout()
    sock.settimeout(timeout)
    try:
        while b"\r\n\r\n" not in resp_buffer:
            chunk = sock.recv(buffer_size)
            if not chunk:
                break
            resp_buffer += chunk

        if b"\r\n\r\n" in resp_buffer:
            header_part, _, extra_bytes = resp_buffer.partition(b"\r\n\r\n")
            first_line = header_part.split(b"\r\n")[0].decode("ascii", errors="ignore")
            # e.g. 'HTTP/1.1 101 Switching Protocols'
            parts = first_line.split()
            if len(parts) >= 2 and parts[1].isdigit():
                return int(parts[1]), extra_bytes
            return 200, extra_bytes
        return 0, b""
    finally:
        sock.settimeout(old_timeout)

class WSTunnelHandler(threading.Thread):
    def __init__(self, client_sock, client_addr, config, verbose=True):
        super(WSTunnelHandler, self).__init__()
        self.client_sock = client_sock
        self.client_addr = client_addr
        self.config = config
        self.verbose = verbose
        self.daemon = True
        self.buffer_size = 65535

    def log(self, msg):
        if self.verbose:
            ts = time.strftime("%H:%M:%S")
            print(f"[{ts}] [WS] {msg}", flush=True)

    def run(self):
        remote_sock = None
        try:
            # Check if client sent an HTTP CONNECT request (e.g. from corkscrew)
            initial_req = self.client_sock.recv(4096)
            is_connect = False
            dest_host = self.config.get("host", "127.0.0.1")
            dest_port = int(self.config.get("port", 443))

            if initial_req.startswith(b"CONNECT "):
                is_connect = True
                line = initial_req.split(b"\r\n")[0].decode("ascii", errors="ignore")
                match = re.search(r"CONNECT\s+([^:]+):(\d+)", line)
                if match:
                    # Corkscrew destination (informational, we route to config host/port)
                    pass

            target_host = self.config.get("host", dest_host)
            target_port = int(self.config.get("port", dest_port))
            sni = self.config.get("sni", target_host)
            raw_payload = self.config.get("payload", "")

            # 1. Connect TCP to remote target
            self.log(f"Menghubungkan ke {target_host}:{target_port}...")
            remote_sock = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
            remote_sock.settimeout(30)
            remote_sock.connect((target_host, target_port))

            # 2. TLS wrap if port 443 or TLS enabled
            use_tls = target_port == 443 or self.config.get("tls", "yes").lower() in ("yes", "true", "1")
            if use_tls:
                self.log(f"TLS Handshake SNI: {sni}...")
                ssl_context = ssl.create_default_context()
                ssl_context.check_hostname = False
                ssl_context.verify_mode = ssl.CERT_NONE
                remote_sock = ssl_context.wrap_socket(remote_sock, server_hostname=sni)

            # 3. Send WebSocket Upgrade Request
            formatted_payload = format_payload(raw_payload, host=target_host, port=str(target_port), sni=sni)
            self.log("Mengirim HTTP WebSocket Upgrade Payload...")
            remote_sock.sendall(formatted_payload.encode("utf-8"))

            # 4. Read HTTP Response from Server (allow up to 30s for Origin cold-start)
            status_code, extra_bytes = read_http_response(remote_sock, timeout=30)
            self.log(f"Respons CDN: HTTP {status_code}")

            if status_code not in (101, 200):
                self.log(f"Handshake WebSocket gagal (HTTP {status_code})")
                return

            self.log("WebSocket Handshake Sukses! Terhubung ke SSH backend.")

            # 5. Respond to Corkscrew / Client
            if is_connect:
                self.client_sock.sendall(b"HTTP/1.0 200 Connection established\r\n\r\n")

            # 6. If server sent initial SSH banner along with HTTP 101, forward immediately
            if extra_bytes:
                self.client_sock.sendall(extra_bytes)

            # 7. Bi-directional data transfer loop
            remote_sock.settimeout(None)
            self.client_sock.settimeout(None)
            sockets = [self.client_sock, remote_sock]

            while True:
                r, _, x = select.select(sockets, [], sockets, 60)
                if x:
                    break
                if not r:
                    continue

                for s in r:
                    data = s.recv(self.buffer_size)
                    if not data:
                        return
                    if s is self.client_sock:
                        remote_sock.sendall(data)
                    else:
                        self.client_sock.sendall(data)

        except Exception as e:
            self.log(f"Koneksi terputus/error: {e}")
        finally:
            if remote_sock:
                try:
                    remote_sock.close()
                except Exception:
                    pass
            try:
                self.client_sock.close()
            except Exception:
                pass

def start_ws_server(listen_ip="127.0.0.1", listen_port=8789, config=None, verbose=True):
    """Start local WebSocket injector proxy server."""
    if config is None:
        config = {}

    server_sock = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
    server_sock.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
    server_sock.bind((listen_ip, listen_port))
    server_sock.listen(10)
    print(f"[*] XDerm WS Injector berjalan pada {listen_ip}:{listen_port}", flush=True)

    try:
        while True:
            client_sock, client_addr = server_sock.accept()
            handler = WSTunnelHandler(client_sock, client_addr, config, verbose=verbose)
            handler.start()
    except KeyboardInterrupt:
        print("[*] Menghentikan injektor...", flush=True)
    finally:
        server_sock.close()

def main():
    parser = argparse.ArgumentParser(description="XDerm-Mini SSH WebSocket CDN Injector")
    parser.add_argument("--config", "-c", help="Path to config.txt", default="")
    parser.add_argument("--listen", "-l", help="Local listen IP", default="127.0.0.1")
    parser.add_argument("--port", "-p", help="Local listen port", type=int, default=8789)
    parser.add_argument("--host", help="Remote host/CDN domain", default="")
    parser.add_argument("--rport", help="Remote port (80 or 443)", type=int, default=0)
    parser.add_argument("--sni", help="Server Name Indication (SNI)", default="")
    parser.add_argument("--payload", help="Custom payload template", default="")
    parser.add_argument("--quiet", "-q", help="Quiet mode", action="store_true")

    args = parser.parse_args()

    cfg = {}
    if args.config:
        cfg = load_config_file(args.config)

    # CLI args override file config
    if args.host:
        cfg["host"] = args.host
    if args.rport:
        cfg["port"] = str(args.rport)
    if args.sni:
        cfg["sni"] = args.sni
    if args.payload:
        cfg["payload"] = args.payload

    if "port" not in cfg:
        cfg["port"] = "443"

    start_ws_server(listen_ip=args.listen, listen_port=args.port, config=cfg, verbose=not args.quiet)

if __name__ == "__main__":
    main()
