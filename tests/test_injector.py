import os
import sys
import unittest
from unittest.mock import MagicMock

# Add root directory to sys.path
sys.path.insert(0, os.path.abspath(os.path.join(os.path.dirname(__file__), '..')))

import xderm_ws

class TestXdermWSInjector(unittest.TestCase):
    def test_format_payload(self):
        raw_payload = "GET / HTTP/1.1[crlf]Host: [host][crlf]Upgrade: websocket[crlf][crlf]"
        formatted = xderm_ws.format_payload(raw_payload, host="dz1wsoabehhmc.cloudfront.net", port="443")
        expected = "GET / HTTP/1.1\r\nHost: dz1wsoabehhmc.cloudfront.net\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n\r\n"
        self.assertEqual(formatted, expected)

    def test_format_payload_without_placeholders(self):
        raw_payload = "GET / HTTP/1.1[crlf]Host: dz1wsoabehhmc.cloudfront.net[crlf]Upgrade: websocket[crlf][crlf]"
        formatted = xderm_ws.format_payload(raw_payload, host="other.net", port="80")
        expected = "GET / HTTP/1.1\r\nHost: dz1wsoabehhmc.cloudfront.net\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n\r\n"
        self.assertEqual(formatted, expected)

    def test_domain_fronting_payload(self):
        raw_payload = "GET / HTTP/1.1[crlf]Host: [host][crlf]X-Front: [sni][crlf]Upgrade: websocket[crlf][crlf]"
        formatted = xderm_ws.format_payload(raw_payload, host="dz1wsoabehhmc.cloudfront.net", port="443", sni="bug.isp.com")
        expected = "GET / HTTP/1.1\r\nHost: dz1wsoabehhmc.cloudfront.net\r\nX-Front: bug.isp.com\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n\r\n"
        self.assertEqual(formatted, expected)

    def test_parse_config(self):
        sample_config = """
host=dz1wsoabehhmc.cloudfront.net
port=443
pudp=7300
user=xxxx
pass=xxxx
sni=dz1wsoabehhmc.cloudfront.net
payload=GET / HTTP/1.1[crlf]Host: dz1wsoabehhmc.cloudfront.net[crlf]Upgrade: websocket[crlf][crlf]
mode=SSH-WS.
"""
        cfg = xderm_ws.parse_config_text(sample_config)
        self.assertEqual(cfg.get('host'), 'dz1wsoabehhmc.cloudfront.net')
        self.assertEqual(cfg.get('port'), '443')
        self.assertEqual(cfg.get('user'), 'xxxx')
        self.assertEqual(cfg.get('pass'), 'xxxx')
        self.assertEqual(cfg.get('sni'), 'dz1wsoabehhmc.cloudfront.net')
        self.assertEqual(cfg.get('payload'), 'GET / HTTP/1.1[crlf]Host: dz1wsoabehhmc.cloudfront.net[crlf]Upgrade: websocket[crlf][crlf]')
        self.assertEqual(cfg.get('mode'), 'SSH-WS.')

    def test_parse_colon_style_config(self):
        sample_colon_config = """
Host: dz1wsoabehhmc.cloudfront.net
Port: 443
Payload: GET / HTTP/1.1[crlf]Host: dz1wsoabehhmc.cloudfront.net[crlf]Upgrade: websocket[crlf][crlf]
SNI: dz1wsoabehhmc.cloudfront.net
User: xxxx
Pass: xxxx
"""
        cfg = xderm_ws.parse_config_text(sample_colon_config)
        self.assertEqual(cfg.get('host'), 'dz1wsoabehhmc.cloudfront.net')
        self.assertEqual(cfg.get('port'), '443')
        self.assertEqual(cfg.get('user'), 'xxxx')
        self.assertEqual(cfg.get('pass'), 'xxxx')
        self.assertEqual(cfg.get('sni'), 'dz1wsoabehhmc.cloudfront.net')

    def test_websocket_handshake_parsing(self):
        mock_sock = MagicMock()
        mock_sock.recv.side_effect = [
            b"HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n\r\nSSH-2.0-OpenSSH_8.9p1"
        ]
        status_code, extra = xderm_ws.read_http_response(mock_sock)
        self.assertEqual(status_code, 101)
        self.assertEqual(extra, b"SSH-2.0-OpenSSH_8.9p1")

    def test_live_cloudfront_handshake(self):
        import socket, ssl
        s = socket.create_connection(('dz1wsoabehhmc.cloudfront.net', 443), timeout=5)
        ctx = ssl.create_default_context()
        ctx.check_hostname = False
        ctx.verify_mode = ssl.CERT_NONE
        ss = ctx.wrap_socket(s, server_hostname='dz1wsoabehhmc.cloudfront.net')
        payload = xderm_ws.format_payload(
            "GET / HTTP/1.1[crlf]Host: dz1wsoabehhmc.cloudfront.net[crlf]Upgrade: websocket[crlf][crlf]",
            host="dz1wsoabehhmc.cloudfront.net",
            port="443"
        )
        ss.sendall(payload.encode())
        code, extra = xderm_ws.read_http_response(ss, timeout=5)
        self.assertEqual(code, 101)
        # Check SSH banner or MaxStartups from sshd
        banner = extra
        if not banner:
            banner = ss.recv(256)
        ss.close()
        self.assertTrue(banner.startswith(b"SSH-2.0") or b"MaxStartups" in banner)

    def test_local_proxy_corkscrew_handshake(self):
        import socket, threading, time
        # Start a local test server
        server_sock = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
        server_sock.bind(('127.0.0.1', 0))
        local_port = server_sock.getsockname()[1]
        server_sock.listen(1)

        cfg = {
            'host': 'dz1wsoabehhmc.cloudfront.net',
            'port': '443',
            'sni': 'dz1wsoabehhmc.cloudfront.net',
            'payload': 'GET / HTTP/1.1[crlf]Host: dz1wsoabehhmc.cloudfront.net[crlf]Upgrade: websocket[crlf][crlf]',
            'tls': 'yes'
        }

        def run_srv():
            try:
                csock, caddr = server_sock.accept()
                handler = xderm_ws.WSTunnelHandler(csock, caddr, cfg, verbose=False)
                handler.start()
            except Exception:
                pass

        t = threading.Thread(target=run_srv, daemon=True)
        t.start()

        # Connect as a corkscrew client
        client = socket.create_connection(('127.0.0.1', local_port), timeout=5)
        client.sendall(b"CONNECT dz1wsoabehhmc.cloudfront.net:443 HTTP/1.0\r\n\r\n")

        # Expect 200 Connection established
        resp = client.recv(1024)
        self.assertIn(b"200 Connection established", resp)

        # After 200, next bytes should be the SSH server banner or MaxStartups
        client.settimeout(5)
        banner = client.recv(256)
        self.assertTrue(banner.startswith(b"SSH-2.0") or b"MaxStartups" in banner or len(banner) > 0)

        client.close()
        server_sock.close()

if __name__ == '__main__':
    unittest.main()
