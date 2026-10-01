#!/usr/bin/env python3
"""
Test Suite for WebUI (index.php) enhancements:
1. Field to Config Text serialization
2. Dual-mode config synchronization
3. Payload preservation and regex compatibility
4. Responsive HTML structure and controls verification
"""

import os
import re
import unittest

class TestWebUI(unittest.TestCase):
    def setUp(self):
        self.webui_path = os.path.abspath(os.path.join(os.path.dirname(__file__), '..', 'index.php'))
        with open(self.webui_path, 'r', encoding='utf-8', errors='ignore') as f:
            self.content = f.read()

    def test_webui_form_inputs_present(self):
        """Verify individual form fields exist for easy editing."""
        self.assertIn('id="f_host"', self.content)
        self.assertIn('id="f_port"', self.content)
        self.assertIn('id="f_sni"', self.content)
        self.assertIn('id="f_user"', self.content)
        self.assertIn('id="f_pass"', self.content)
        self.assertIn('id="f_payload"', self.content)

    def test_webui_preset_buttons(self):
        """Verify quick preset buttons for CloudFront WS CDN exist."""
        self.assertIn('presetCloudFront', self.content)

    def test_webui_status_badge(self):
        """Verify dynamic status badge and log controls exist."""
        self.assertIn('id="status_badge"', self.content)
        self.assertTrue('btn_clear_log' in self.content)
        self.assertIn('id="chk_autoscroll"', self.content)
        self.assertIn('id="tab_log_sys"', self.content)
        self.assertIn('id="tab_log_ws"', self.content)
        self.assertIn('id="tab_log_tun"', self.content)
        self.assertIn("['action'] === 'clear_ws_log'", self.content)
        self.assertIn("['action'] === 'clear_tun_log'", self.content)

    def test_webui_ping_latency_indicator(self):
        """Verify real-time ping latency badge and AJAX endpoint exist."""
        self.assertIn('id="ping_badge"', self.content)
        self.assertIn("['action'] === 'ping'", self.content)

    def test_webui_quick_profile_switcher(self):
        """Verify quick profile switcher dropdown and AJAX handler exist."""
        self.assertIn('id="quick_profile"', self.content)
        self.assertIn("['action'] === 'switch_profile'", self.content)

    def test_config_serialization_preserves_payload(self):
        """Test regex sanitization does not drop or corrupt payload."""
        sample_config = (
            "host=dz1wsoabehhmc.cloudfront.net\n"
            "port=443\n"
            "pudp=7300\n"
            "user=xxxx\n"
            "pass=xxxx\n"
            "sni=dz1wsoabehhmc.cloudfront.net\n"
            "payload=GET / HTTP/1.1[crlf]Host: dz1wsoabehhmc.cloudfront.net[crlf]Upgrade: websocket[crlf][crlf]\n"
            "mode=SSH-WS.\n"
        )

        # In index.php, sed '/.../d' deletes matching lines for vmess/trojan extraction
        filter_regex = r'host=|port=|pudp=|user=|pass=|sni=|payload=|mode='
        vmess_lines = [line for line in sample_config.splitlines() if not re.search(filter_regex, line)]
        # All lines should be filtered out because no vmess:// or trojan:// was present
        self.assertEqual(len(vmess_lines), 0)

        # And if a trojan line is present, it is preserved:
        sample_with_trojan = sample_config + "trojan://pass@server:443\n"
        trojan_lines = [line for line in sample_with_trojan.splitlines() if not re.search(filter_regex, line)]
        self.assertEqual(trojan_lines, ["trojan://pass@server:443"])

    def test_php8_compatibility_header_and_login(self):
        """Verify header.php and login.php do not access undefined array keys or fail redirect."""
        header_path = os.path.abspath(os.path.join(os.path.dirname(__file__), '..', 'header.php'))
        with open(header_path, 'r', encoding='utf-8') as f:
            header_src = f.read()

        # header.php must not directly evaluate $_SESSION['loggedin'] without checking empty/isset
        self.assertNotIn("($_SESSION['loggedin'] != 1)", header_src)
        self.assertIn("empty($_SESSION['loggedin'])", header_src)
        # header.php must terminate execution with exit; after header redirect
        self.assertRegex(header_src, r'header\("Location:\s*login\.php"\);\s*exit;')

        login_path = os.path.abspath(os.path.join(os.path.dirname(__file__), '..', 'login.php'))
        with open(login_path, 'r', encoding='utf-8') as f:
            login_src = f.read()

        # login.php must safely check $_GET['login']
        # login.php must not have unescaped single quotes inside echo '<!DOCTYPE>...'
        self.assertNotIn("typeof jQuery === 'undefined'", login_src)
        self.assertIn("typeof jQuery === \\'undefined\\'", login_src)

        # index.php must use file_exists('login.php')
        self.assertIn("file_exists('login.php')", self.content)

    def test_index_directory_and_button_robustness(self):
        """Verify index.php enforces chdir, creates log directory, and handles start button."""
        self.assertIn("chdir(__DIR__)", self.content)
        self.assertIn("!is_dir('log')", self.content)
        self.assertIn("mkdir('log'", self.content)

        # Ensure log directory exists in repository
        repo_root = os.path.abspath(os.path.join(os.path.dirname(__file__), '..'))
        log_dir = os.path.join(repo_root, 'log')
        self.assertTrue(os.path.isdir(log_dir))
        self.assertTrue(os.path.exists(os.path.join(log_dir, 'st')))
        self.assertTrue(os.path.exists(os.path.join(log_dir, '.gitkeep')))

    def test_log_endpoint_and_assets(self):
        """Verify get_log AJAX handler and asset directories (js/, img/) exist."""
        self.assertIn("['action'] === 'get_log'", self.content)
        self.assertIn("fetchActiveLog", self.content)
        self.assertIn("chmod 666 screenlog.0", self.content)

        repo_root = os.path.abspath(os.path.join(os.path.dirname(__file__), '..'))
        js_file = os.path.join(repo_root, 'js', 'jquery-2.1.3.min.js')
        self.assertTrue(os.path.isfile(js_file))

        img_file = os.path.join(repo_root, 'img', 'image.png')
        self.assertTrue(os.path.isfile(img_file))

    def test_autorekonek_toggle_and_config(self):
        """Verify Auto-Reconnect checkbox, config saving, and xderm-mini integration."""
        self.assertIn('name="use_autorekonek"', self.content)
        self.assertIn('config/autorekonek', self.content)
        self.assertIn('$is_autorek', self.content)

        repo_root = os.path.abspath(os.path.join(os.path.dirname(__file__), '..'))
        autorek_cfg = os.path.join(repo_root, 'config', 'autorekonek')
        self.assertTrue(os.path.isfile(autorek_cfg))
        with open(autorek_cfg, 'r') as f:
            val = f.read().strip()
        self.assertIn(val, ['yes', 'no'])

        script_path = os.path.join(repo_root, 'xderm-mini')
        with open(script_path, 'r', encoding='utf-8') as f:
            script_src = f.read()
        self.assertIn('config/autorekonek', script_src)
        self.assertIn('Auto-reconnect nonaktif.', script_src)

if __name__ == '__main__':
    unittest.main()



