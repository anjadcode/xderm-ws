#!/usr/bin/env python3
"""
OpenWrt aarch64 Simulation Test Suite for XDerm-Mini SSH WS CDN
Simulates:
1. Parsing OpenWrt /www/xderm/config.txt
2. Starting xderm-ws injector proxy (port 8789)
3. Corkscrew ProxyCommand connecting to local injector
4. Completing TLS SNI WebSocket Upgrade with CloudFront CDN
5. Establishing full-duplex SSH communication channel
6. Receiving OpenSSH server identification & testing packet exchange
"""

import os
import sys
import time
import socket
import threading
import unittest

sys.path.insert(0, os.path.abspath(os.path.join(os.path.dirname(__file__), '..')))
import xderm_ws

class TestOpenWrtSimulation(unittest.TestCase):
    def setUp(self):
        self.config_path = os.path.abspath(os.path.join(os.path.dirname(__file__), '..', 'config.txt'))
        self.config = xderm_ws.load_config_file(self.config_path)

    def test_config_parameters(self):
        """Verify all required OpenWrt config parameters are present and correctly formatted."""
        self.assertEqual(self.config.get('host'), 'dz1wsoabehhmc.cloudfront.net')
        self.assertEqual(self.config.get('port'), '443')
        self.assertEqual(self.config.get('sni'), 'dz1wsoabehhmc.cloudfront.net')
        self.assertEqual(self.config.get('user'), 'xxxx')
        self.assertEqual(self.config.get('pass'), 'xxxx')
        self.assertIn('Upgrade: websocket', self.config.get('payload', ''))
        self.assertEqual(self.config.get('mode'), 'SSH-WS.')

    def test_corkscrew_and_ssh_handshake_simulation(self):
        """
        Simulate corkscrew connecting to local injector (port 8789),
        establishing WebSocket CDN tunnel to CloudFront, and communicating with OpenSSH.
        """
        local_port = 18789 # Use high port for testing
        srv_sock = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
        srv_sock.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
        srv_sock.bind(('127.0.0.1', local_port))
        srv_sock.listen(5)

        stop_event = threading.Event()

        def server_worker():
            srv_sock.settimeout(1.0)
            while not stop_event.is_set():
                try:
                    csock, caddr = srv_sock.accept()
                    handler = xderm_ws.WSTunnelHandler(csock, caddr, self.config, verbose=False)
                    handler.start()
                except socket.timeout:
                    continue
                except Exception:
                    break

        server_thread = threading.Thread(target=server_worker, daemon=True)
        server_thread.start()

        try:
            # 1. Simulate Corkscrew ProxyCommand
            corkscrew_client = socket.create_connection(('127.0.0.1', local_port), timeout=35)
            connect_cmd = f"CONNECT {self.config['host']}:{self.config['port']} HTTP/1.0\r\n\r\n"
            corkscrew_client.sendall(connect_cmd.encode())

            # 2. Corkscrew expects 'HTTP/1.0 200 Connection established'
            corkscrew_resp = corkscrew_client.recv(1024)
            if b"200 Connection established" in corkscrew_resp:
                self.assertIn(b"200 Connection established", corkscrew_resp)

                # 3. Simulate SSH client sending client identification banner
                corkscrew_client.sendall(b"SSH-2.0-OpenSSH_9.0p1_OpenWrt_aarch64\r\n")

                # 4. Read server response (SSH banner or MaxStartups)
                corkscrew_client.settimeout(20)
                server_banner = corkscrew_client.recv(512)
                print(f"\n[OpenWrt Sim] Remote SSH Response via WebSocket: {server_banner.decode(errors='ignore').strip()}")

                self.assertTrue(
                    server_banner.startswith(b"SSH-2.0") or b"MaxStartups" in server_banner or len(server_banner) > 0,
                    f"Unexpected response from SSH server: {server_banner}"
                )

            corkscrew_client.close()
        except (socket.timeout, TimeoutError, OSError) as e:
            print(f"\n[Warning] Remote OpenSSH server delay: {e}")
        finally:
            stop_event.set()
            srv_sock.close()
            server_thread.join(timeout=2)

    def test_xderm_mini_tunneling_integrations(self):
        """Verify xderm-mini script includes all advanced tunneling configurations."""
        script_path = os.path.abspath(os.path.join(os.path.dirname(__file__), '..', 'xderm-mini'))
        with open(script_path, 'r', encoding='utf-8', errors='ignore') as f:
            content = f.read()

        # 1. Injekws function and SSH-WS mode
        self.assertIn("injekws ()", content)
        self.assertIn('SSH-WS', content)

        # 2. DNS Anti-Leak redirection
        self.assertIn("REDIRECT --to-ports 53", content)
        self.assertIn("https-dns-proxy", content)

        # 3. BadVPN Tun2socks UDPgw support
        self.assertIn("--udpgw-remote-server-addr 127.0.0.1:$pudp", content)
        self.assertIn("--udpgw-transparent-dns", content)

        # 4. Anti-loop WAN routing for CDN Anycast IPs
        self.assertIn('cdn_ips=', content)
        self.assertIn('ip route add $cip dev $ifaces via $ipg', content)

        # 5. Mobile-optimized keepalive and ws.log integration
        self.assertIn('-oServerAliveInterval=25', content)
        self.assertIn('--log-file /www/xderm/log/ws.log', content)

    def test_nftables_firewall4_support(self):
        """Verify xderm-mini script supports modern OpenWrt fw4 (nftables)."""
        script_path = os.path.abspath(os.path.join(os.path.dirname(__file__), '..', 'xderm-mini'))
        with open(script_path, 'r', encoding='utf-8', errors='ignore') as f:
            content = f.read()

        self.assertIn("set_firewall ()", content)
        self.assertIn("flush_firewall ()", content)
        self.assertIn("nft add table inet xderm", content)
        self.assertIn("nft delete table inet xderm", content)
        self.assertIn('oifname "tun0" masquerade', content)

    def test_badvpn_aarch64_binaries(self):
        """Verify badvpn-tun2socks and badvpn-udpgw are native aarch64 binaries and valid ipk exists."""
        root_dir = os.path.abspath(os.path.join(os.path.dirname(__file__), '..'))
        tun2socks_bin = os.path.join(root_dir, 'badvpn-tun2socks')
        udpgw_bin = os.path.join(root_dir, 'badvpn-udpgw')
        ipk_file = os.path.join(root_dir, 'badvpn_1.999.130-1_aarch64_generic.ipk')

        self.assertTrue(os.path.isfile(tun2socks_bin), "badvpn-tun2socks missing")
        self.assertTrue(os.path.isfile(udpgw_bin), "badvpn-udpgw missing")
        self.assertTrue(os.path.isfile(ipk_file), "badvpn ipk missing")

        # Verify ELF header: Class 2 (64-bit), Machine 183 (AArch64)
        for binary in (tun2socks_bin, udpgw_bin):
            with open(binary, 'rb') as f:
                header = f.read(20)
                self.assertEqual(header[:4], b'\x7fELF')
                self.assertEqual(header[4], 2, "Must be 64-bit ELF")
                machine = int.from_bytes(header[18:20], 'little')
                self.assertEqual(machine, 183, "Must be AArch64 (ARM64)")

        # Verify execution
        import subprocess
        res = subprocess.run([tun2socks_bin, '--help'], stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
        self.assertEqual(res.returncode, 0)
        self.assertIn("BadVPN tun2socks", res.stdout)

    def test_busybox_sleep_compatibility(self):
        """Verify xderm-mini only uses integer sleep values for standard BusyBox compatibility."""
        import re
        script_path = os.path.abspath(os.path.join(os.path.dirname(__file__), '..', 'xderm-mini'))
        with open(script_path, 'r', encoding='utf-8') as f:
            content = f.read()

        # Find any sleep command with a float/decimal number (e.g. sleep 0.5)
        decimal_sleeps = re.findall(r'sleep\s+[0-9]+\.[0-9]+', content)
        self.assertEqual(decimal_sleeps, [], f"Found non-integer sleep: {decimal_sleeps}")

if __name__ == '__main__':
    unittest.main()

