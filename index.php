<?php
  chdir(__DIR__);
  if (!is_dir('log')) {
    @mkdir('log', 0755, true);
  }
  if (!file_exists('log/st')) {
    @file_put_contents('log/st', "Start\n");
  }

  if (file_exists('login.php')) {
    include 'header.php';
    ceklogin();
  }



  // Read active profile and mode for status header
  $active_prof = "config1";
  exec("cat config/default 2>/dev/null", $df_prof);
  if (!empty($df_prof[0])) { $active_prof = trim($df_prof[0]); }
  
  $active_mode = "SSH-WS";
  exec("cat config/mode.default 2>/dev/null", $df_mode);
  if (!empty($df_mode[0])) { $active_mode = trim(str_replace('.', '', $df_mode[0])); }

  // AJAX handler for clearing log
  if (isset($_POST['action']) && $_POST['action'] === 'clear_log') {
    exec("echo > screenlog.0");
    echo "OK";
    exit;
  }

  // AJAX handler for clearing WS log
  if (isset($_POST['action']) && $_POST['action'] === 'clear_ws_log') {
    exec("echo > log/ws.log 2>/dev/null");
    echo "OK";
    exit;
  }

  // AJAX handler for ping latency check
  if ((isset($_GET['action']) && $_GET['action'] === 'ping') || (isset($_POST['action']) && $_POST['action'] === 'ping')) {
    header('Content-Type: application/json');
    $start = microtime(true);
    $fp = @fsockopen("1.1.1.1", 53, $errno, $errstr, 1.5);
    if ($fp) {
      $latency = round((microtime(true) - $start) * 1000);
      fclose($fp);
      echo json_encode(['status' => 'ok', 'latency' => $latency]);
    } else {
      echo json_encode(['status' => 'error', 'latency' => null]);
    }
    exit;
  }

  // AJAX handler for quick profile switcher
  if (isset($_POST['action']) && $_POST['action'] === 'switch_profile') {
    header('Content-Type: application/json');
    $prof = isset($_POST['profile']) ? trim($_POST['profile']) : '';
    if (preg_match('/^config[1-5]$/', $prof)) {
      exec('echo "' . $prof . '" > config/default');
      if (file_exists("config/$prof")) {
        exec("cp config/$prof config.txt");
        exec("grep -i '^mode=' config/$prof | awk -F '=' '{print $2}'", $m_out);
        if (!empty($m_out[0])) {
          $new_mode = trim($m_out[0]);
          if (substr($new_mode, -1) !== '.') { $new_mode .= '.'; }
          exec('echo "' . $new_mode . '" > config/mode.default');
        }
      }
      exec("cat config/mode.default 2>/dev/null", $cur_m);
      $m_label = !empty($cur_m[0]) ? trim(str_replace('.', '', $cur_m[0])) : 'SSH-WS';
      echo json_encode(['status' => 'ok', 'profile' => $prof, 'mode' => $m_label]);
    } else {
      echo json_encode(['status' => 'error', 'message' => 'Invalid profile']);
    }
    exit;
  }

  function render_log_controls() {
    echo '<div class="log-controls" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px; flex-wrap:wrap; gap:4px;">
            <div style="display:flex; gap:4px;">
              <button type="button" id="tab_log_sys" class="tab-btn active" style="padding:2px 8px; font-size:11px;" onclick="switchLogTab(\'sys\')">Log Sistem</button>
              <button type="button" id="tab_log_ws" class="tab-btn" style="padding:2px 8px; font-size:11px;" onclick="switchLogTab(\'ws\')">Log WS Engine</button>
            </div>
            <div style="display:flex; align-items:center; gap:6px;">
              <label style="font-size:11px; color:#9ca3af; display:flex; align-items:center; gap:3px; cursor:pointer;">
                <input type="checkbox" id="chk_autoscroll" checked> Auto-scroll
              </label>
              <button type="button" id="btn_clear_log" class="btn-log-action" onclick="clearLog()">Clear</button>
              <button type="button" class="btn-log-action" onclick="copyLog()">Copy</button>
            </div>
          </div>';
  }
?>
<!DOCTYPE html>
<html>
<head>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="shortcut icon" href="img/ico.png">
<script type="text/javascript" src="js/jquery-2.1.3.min.js"></script>
<meta charset="UTF-8"><title>Xderm Mini</title>
<style>
		body {
			margin: 0;
			padding: 10px;
			font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
			color: #e5e7eb;
			background-color: #111827;
			display: flex;
			flex-direction: column;
			justify-content: center;
			align-items: center;
			min-height: 95vh;
		}

		.box_script {
			width: 100%;
			max-width: 520px;
			background-color: #1f2937;
			border: 1px solid #374151;
			border-radius: 10px;
			padding: 16px;
			box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.5);
			box-sizing: border-box;
		}

		.btn {
			cursor: pointer;
			padding: 8px 14px;
			font-size: 13px;
			font-weight: 600;
			border: 1px solid #374151;
			border-radius: 6px;
			background: #111827;
			color: #f3f4f6;
			transition: all 0.2s ease;
		}

		.btn:hover {
			background: #374151;
			color: #ffffff;
		}

		.btn-start {
			background: #065f46;
			border-color: #059669;
			color: #a7f3d0;
		}
		.btn-start:hover {
			background: #047857;
			color: #ffffff;
		}
		.btn-stop {
			background: #881337;
			border-color: #e11d48;
			color: #fecdd3;
		}
		.btn-stop:hover {
			background: #be123c;
			color: #ffffff;
		}

		.nav-bar {
			display: flex;
			gap: 6px;
			justify-content: center;
			margin: 12px 0;
			flex-wrap: wrap;
		}

		.status-bar {
			display: flex;
			justify-content: space-between;
			align-items: center;
			background: #111827;
			border: 1px solid #374151;
			border-radius: 6px;
			padding: 6px 12px;
			margin-bottom: 12px;
			font-size: 12px;
		}

		.badge {
			padding: 3px 8px;
			border-radius: 9999px;
			font-weight: bold;
			font-size: 11px;
		}
		.badge-connected { background: #065f46; color: #34d399; }
		.badge-connecting { background: #78350f; color: #fbbf24; }
		.badge-disconnected { background: #4b5563; color: #9ca3af; }
		.badge-info { background: #1e3a8a; color: #93c5fd; }

		.terminal-box {
			background-color: #030712;
			border: 1px solid #374151;
			border-radius: 6px;
			padding: 10px;
			font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
			font-size: 12px;
			color: #10b981;
			height: 220px;
			overflow-y: auto;
			text-align: left;
			white-space: pre-wrap;
			word-break: break-all;
		}

		.log-controls {
			display: flex;
			justify-content: flex-end;
			gap: 6px;
			margin-bottom: 6px;
		}
		.btn-log-action {
			padding: 3px 8px;
			font-size: 11px;
			background: #374151;
			border: none;
			border-radius: 4px;
			color: #e5e7eb;
			cursor: pointer;
		}
		.btn-log-action:hover { background: #4b5563; }

		/* Form Editor Styles */
		.config-tabs {
			display: flex;
			gap: 4px;
			margin-bottom: 10px;
			border-bottom: 1px solid #374151;
			padding-bottom: 6px;
		}
		.tab-btn {
			padding: 5px 12px;
			font-size: 12px;
			font-weight: 600;
			background: transparent;
			border: none;
			border-radius: 4px;
			color: #9ca3af;
			cursor: pointer;
		}
		.tab-btn.active {
			background: #374151;
			color: #ffffff;
		}

		.form-grid {
			display: flex;
			flex-direction: column;
			gap: 8px;
			text-align: left;
		}
		.form-row {
			display: flex;
			gap: 8px;
		}
		.form-group {
			display: flex;
			flex-direction: column;
			gap: 3px;
			flex: 1;
		}
		.form-group label {
			font-size: 11px;
			font-weight: 600;
			color: #9ca3af;
		}
		.input-field {
			width: 100%;
			padding: 6px 8px;
			background-color: #111827;
			border: 1px solid #374151;
			border-radius: 4px;
			color: #f3f4f6;
			font-size: 12px;
			box-sizing: border-box;
			font-family: inherit;
		}
		.input-field:focus {
			border-color: #10b981;
			outline: none;
		}

		.pass-wrapper {
			position: relative;
			display: flex;
			align-items: center;
		}
		.btn-toggle-pass {
			position: absolute;
			right: 6px;
			background: none;
			border: none;
			color: #9ca3af;
			cursor: pointer;
			font-size: 12px;
		}

		.preset-bar {
			display: flex;
			gap: 6px;
			margin-bottom: 4px;
		}
		.btn-preset {
			padding: 3px 8px;
			font-size: 10px;
			background: #064e3b;
			border: 1px solid #059669;
			border-radius: 4px;
			color: #6ee7b7;
			cursor: pointer;
		}
		.btn-preset:hover { background: #047857; color: #ffffff; }

		.options-grid {
			display: grid;
			grid-template-columns: repeat(2, 1fr);
			gap: 6px;
			margin: 10px 0;
			text-align: left;
			font-size: 12px;
		}

		.footer {
			margin-top: 14px;
			font-size: 11px;
			color: #6b7280;
			text-align: center;
		}
		.footer a { color: #10b981; text-decoration: none; }
</style>
<script>
function shipping_calc() {
  var val = document.getElementById("idconf").value;
  var data = "";
  if (val === "config1") { data = document.getElementById("isi1").value; }
  else if (val === "config2") { data = document.getElementById("isi2").value; }
  else if (val === "config3") { data = document.getElementById("isi3").value; }
  else if (val === "config4") { data = document.getElementById("isi4").value; }
  else if (val === "config5") { data = document.getElementById("isi5").value; }
  
  document.getElementById("isi").value = data;
  syncRawToForm();
}

function switchConfigTab(mode) {
  if (mode === 'form') {
    document.getElementById("form_editor").style.display = "flex";
    document.getElementById("raw_editor").style.display = "none";
    document.getElementById("tab_form").className = "tab-btn active";
    document.getElementById("tab_raw").className = "tab-btn";
    syncRawToForm();
  } else {
    document.getElementById("form_editor").style.display = "none";
    document.getElementById("raw_editor").style.display = "block";
    document.getElementById("tab_form").className = "tab-btn";
    document.getElementById("tab_raw").className = "tab-btn active";
    syncFormToRaw();
  }
}

function togglePassVisibility() {
  var passInput = document.getElementById("f_pass");
  if (passInput.type === "password") {
    passInput.type = "text";
  } else {
    passInput.type = "password";
  }
}

function presetCloudFront() {
  var host = document.getElementById("f_host").value || "dz1wsoabehhmc.cloudfront.net";
  var payloadInput = document.getElementById("f_payload");
  payloadInput.value = "GET / HTTP/1.1[crlf]Host: " + host + "[crlf]Upgrade: websocket[crlf][crlf]";
  syncFormToRaw();
}

function syncFormToRaw() {
  var host = document.getElementById("f_host").value.trim();
  var port = document.getElementById("f_port").value.trim() || "443";
  var sni = document.getElementById("f_sni").value.trim();
  var user = document.getElementById("f_user").value.trim();
  var pass = document.getElementById("f_pass").value.trim();
  var pudp = document.getElementById("f_pudp").value.trim() || "7300";
  var payload = document.getElementById("f_payload").value.trim();

  var lines = [];
  if (host) lines.push("host=" + host);
  if (port) lines.push("port=" + port);
  if (pudp) lines.push("pudp=" + pudp);
  if (user) lines.push("user=" + user);
  if (pass) lines.push("pass=" + pass);
  if (sni) lines.push("sni=" + sni);
  if (payload) lines.push("payload=" + payload);

  document.getElementById("isi").value = lines.join("\n") + "\n";
}

function syncRawToForm() {
  var raw = document.getElementById("isi").value;
  var lines = raw.split("\n");
  var cfg = {};
  for (var i = 0; i < lines.length; i++) {
    var line = lines[i].trim();
    if (!line || line.startsWith("#")) continue;
    var idx = line.indexOf("=");
    if (idx !== -1) {
      var k = line.substring(0, idx).trim().toLowerCase();
      var v = line.substring(idx + 1).trim();
      cfg[k] = v;
    }
  }

  if (document.getElementById("f_host")) document.getElementById("f_host").value = cfg["host"] || "";
  if (document.getElementById("f_port")) document.getElementById("f_port").value = cfg["port"] || "443";
  if (document.getElementById("f_sni")) document.getElementById("f_sni").value = cfg["sni"] || "";
  if (document.getElementById("f_user")) document.getElementById("f_user").value = cfg["user"] || "";
  if (document.getElementById("f_pass")) document.getElementById("f_pass").value = cfg["pass"] || "";
  if (document.getElementById("f_pudp")) document.getElementById("f_pudp").value = cfg["pudp"] || "7300";
  if (document.getElementById("f_payload")) document.getElementById("f_payload").value = cfg["payload"] || "";
}

var activeLogTab = "sys";

function switchLogTab(tab) {
  activeLogTab = tab;
  if (tab === "sys") {
    $("#tab_log_sys").addClass("active");
    $("#tab_log_ws").removeClass("active");
  } else {
    $("#tab_log_sys").removeClass("active");
    $("#tab_log_ws").addClass("active");
  }
  fetchActiveLog();
}

function clearLog() {
  var act = (activeLogTab === "ws") ? "clear_ws_log" : "clear_log";
  $.post("index.php", { action: act }, function() {
    if (document.getElementById("log")) document.getElementById("log").innerHTML = "";
    if (document.getElementById("loglain")) document.getElementById("loglain").innerHTML = "";
  });
}

function copyLog() {
  var el = document.getElementById("log") || document.getElementById("loglain");
  if (el) {
    var txt = el.innerText;
    navigator.clipboard.writeText(txt).then(function() {
      alert("Log berhasil disalin ke clipboard!");
    });
  }
}

function fetchActiveLog() {
  var targetUrl = (activeLogTab === "ws") ? "log/ws.log" : "screenlog.0";
  $.ajax({
    url: targetUrl,
    cache: false,
    success: function(result) {
      var el = $("#log");
      if (el.length) {
        el.html(result);
        if ($("#chk_autoscroll").length === 0 || $("#chk_autoscroll").is(":checked")) {
          var textarea = document.getElementById("log");
          if (textarea) textarea.scrollTop = textarea.scrollHeight;
        }
      }
    }
  });
}

function checkPing() {
  $.ajax({
    url: "index.php?action=ping",
    cache: false,
    timeout: 2000,
    success: function(res) {
      var el = $("#ping_badge");
      if (res && res.status === "ok" && res.latency !== null) {
        var lat = res.latency;
        el.text("⚡ " + lat + " ms");
        if (lat < 100) {
          el.css({ "color": "#34d399", "border-color": "#059669" });
        } else if (lat < 250) {
          el.css({ "color": "#fbbf24", "border-color": "#d97706" });
        } else {
          el.css({ "color": "#f87171", "border-color": "#dc2626" });
        }
      } else {
        el.text("⚡ Timeout").css({ "color": "#f87171", "border-color": "#dc2626" });
      }
    },
    error: function() {
      $("#ping_badge").text("⚡ Timeout").css({ "color": "#f87171", "border-color": "#dc2626" });
    }
  });
}

function switchQuickProfile(prof) {
  $.post("index.php", { action: "switch_profile", profile: prof }, function(res) {
    if (res && res.status === "ok") {
      $("#profile_badge").text("Profile: " + res.profile + " (" + res.mode + ")");
      if (document.getElementById("idconf")) {
        document.getElementById("idconf").value = prof;
        shipping_calc();
      }
      $("#quick_msg").fadeIn(150).delay(1200).fadeOut(300);
    }
  });
}
</script>
<script type="text/javascript">
    var pingTick = 0;
    $(document).ready(function() {
        setInterval(function() {
            // 1. Fetch system status from screenlog.0
            $.ajax({
                url: "screenlog.0",
                cache: false,
                success: function(result) {
                    if (activeLogTab === "sys") {
                        var el = $("#log");
                        if (el.length) {
                            el.html(result);
                            if ($("#chk_autoscroll").length === 0 || $("#chk_autoscroll").is(":checked")) {
                                var textarea = document.getElementById("log");
                                if (textarea) textarea.scrollTop = textarea.scrollHeight;
                            }
                        }
                    }
                    // Update Status Badge dynamically
                    var badge = $("#status_badge");
                    if (badge.length) {
                        if (result.indexOf("HTTP/1.1 200 OK") !== -1 || result.indexOf("Terhubung") !== -1 || result.indexOf("Sukses") !== -1) {
                            badge.attr("class", "badge badge-connected").text("● Connected");
                            pingTick++;
                            if (pingTick % 4 === 0) {
                                checkPing();
                            }
                        } else if (result.indexOf("Menjalankan") !== -1 || result.indexOf("Menguji") !== -1 || result.indexOf("Menghubungkan") !== -1) {
                            badge.attr("class", "badge badge-connecting").text("● Connecting...");
                            $("#ping_badge").text("⚡ ...").css({ "color": "#fbbf24", "border-color": "#374151" });
                        } else {
                            badge.attr("class", "badge badge-disconnected").text("● Disconnected");
                            $("#ping_badge").text("⚡ -- ms").css({ "color": "#9ca3af", "border-color": "#374151" });
                        }
                    }
                }
            });

            // 2. If WS Engine tab is active, fetch log/ws.log
            if (activeLogTab === "ws") {
                $.ajax({
                    url: "log/ws.log",
                    cache: false,
                    success: function(ws_result) {
                        var el = $("#log");
                        if (el.length) {
                            el.html(ws_result);
                            if ($("#chk_autoscroll").length === 0 || $("#chk_autoscroll").is(":checked")) {
                                var textarea = document.getElementById("log");
                                if (textarea) textarea.scrollTop = textarea.scrollHeight;
                            }
                        }
                    }
                });
            }
        }, 1000);
    });
    $(document).ready(function() {
        setInterval(function() {
            $.ajax({
                url: "loglain.txt",
                cache: false,
                success: function(result) {
                    var el = $("#loglain");
                    if (el.length) {
                        el.html(result);
                        var textarea = document.getElementById("loglain");
                        if (textarea) textarea.scrollTop = textarea.scrollHeight;
                    }
                }
            });
        }, 1000);
        syncRawToForm();
    });
if ( window.history.replaceState ) {
  window.history.replaceState( null, null, window.location.href );
}
</script>
</head>

<body>
<div class="box_script" style="text-align:center">
	<center>
<?php
$filename = 'login.php';
if (file_exists($filename)) {
    echo '<a href="login.php">';
} else {
    echo '<a href="index.php">';
}
?>
		<img src="img/image.png" style="max-width: 90%; height: auto;"></a>
	</center>

    <!-- Real-time Status Header with Ping & Profile -->
    <div class="status-bar">
      <div style="display:flex; align-items:center; gap:6px;">
        <span id="status_badge" class="badge badge-disconnected">● Disconnected</span>
        <span id="ping_badge" class="badge" style="background:#111827; color:#9ca3af; border:1px solid #374151;">⚡ -- ms</span>
      </div>
      <div style="display:flex; align-items:center; gap:6px;">
        <span id="profile_badge" class="badge badge-info">Profile: <?php echo htmlspecialchars($active_prof); ?> (<?php echo htmlspecialchars($active_mode); ?>)</span>
      </div>
    </div>

    <!-- Quick Profile Switcher Bar -->
    <div style="display:flex; justify-content:space-between; align-items:center; background:#111827; border:1px solid #374151; border-radius:6px; padding:4px 10px; margin-bottom:10px; font-size:11px;">
      <span style="color:#9ca3af; font-weight:600;">⚡ Quick Profile:</span>
      <div style="display:flex; align-items:center; gap:6px;">
        <select id="quick_profile" class="input-field" style="width:auto; padding:2px 8px; font-size:11px;" onchange="switchQuickProfile(this.value)">
          <?php
            for ($qp = 1; $qp <= 5; $qp++) {
              $qp_name = "config" . $qp;
              $sel = ($active_prof === $qp_name) ? "selected" : "";
              echo "<option value=\"$qp_name\" $sel>$qp_name</option>";
            }
          ?>
        </select>
        <span id="quick_msg" style="color:#10b981; font-size:10px; display:none;">Updated!</span>
      </div>
    </div>

    <form method="post">
		<div class="nav-bar">
			<?php $status_btn = trim(exec('cat log/st 2>/dev/null')); ?>
			<input type="submit" name="button1" class="btn <?php echo ($status_btn === 'Stop' ? 'btn-stop' : 'btn-start'); ?>" id="strp"
				value="<?php echo ($status_btn ? $status_btn : 'Start'); ?>"/>

			<input type="submit" name="button3" class="btn" id="logg" value="Log"/>
			<input type="submit" name="button2" class="btn" id="config" value="Config"/>
			<input type="submit" name="button5" class="btn" id="about" value="About"/>
		</div>

<?php
  exec('cat /var/update.xderm 2>/dev/null',$z);
  if (!empty($z[0]) && $z[0] != '3.1') {
    echo '<div style="color:#34d399; font-size:12px; margin-bottom:8px;">New version GUI Detected, Please Update!</div>';
  }

  if (isset($_POST['button1'])) {
    if (!is_dir('log')) {
      @mkdir('log', 0755, true);
    }
    $req = isset($_POST['button1']) ? trim($_POST['button1']) : '';
    $o = trim(exec('cat log/st 2>/dev/null'));
    $action = ($req === 'Start' || $req === 'Stop') ? $req : ($o === 'Start' || empty($o) ? 'Start' : 'Stop');

    $xderm_bin = file_exists('/www/xderm/xderm-mini') ? '/www/xderm/xderm-mini' : './xderm-mini';

    if ($action === 'Start') {
      exec('killall -q xderm-mini');
      exec('echo > screenlog.0');
      exec('chmod +x ' . $xderm_bin);
      exec('screen -L -dmS gua ' . $xderm_bin . ' start');
      exec('echo Stop > log/st');
      render_log_controls();
      echo "<div id='log' class='terminal-box'></div>";
      echo '<script>document.getElementById("strp").value="Stop"; document.getElementById("strp").className="btn btn-stop";</script>';
    } else {
      exec('killall -q xderm-mini');
      exec('echo > screenlog.0');
      exec('chmod +x ' . $xderm_bin);
      exec('screen -L -dmS gu ' . $xderm_bin . ' stop');
      exec('echo Start > log/st');
      render_log_controls();
      echo "<div id='log' class='terminal-box'></div>";
      echo '<script>document.getElementById("strp").value="Start"; document.getElementById("strp").className="btn btn-start";</script>';
    }
  }

  if (isset($_POST['button4'])) {
    $xderm_bin = file_exists('/www/xderm/xderm-mini') ? '/www/xderm/xderm-mini' : './xderm-mini';
    exec('killall -q xderm-mini');
    exec('chmod +x ' . $xderm_bin);
    exec('screen -L -dmS upd ' . $xderm_bin . ' update');
    echo "<div id='loglain' class='terminal-box'></div>";
  }

  if (isset($_POST['simpan'])) {
    $config = isset($_POST['configbox']) ? $_POST['configbox'] : '';
    $conf = isset($_POST['profile']) ? $_POST['profile'] : 'config1';
    $use_stunnel = isset($_POST['use_stunnel']) ? $_POST['use_stunnel'] : 'no';
    $use_gotun = isset($_POST['use_gotun']) ? $_POST['use_gotun'] : 'no';
    $use_restfw = isset($_POST['use_restfw']) ? $_POST['use_restfw'] : 'no';
    $use_waitmodem = isset($_POST['use_waitmodem']) ? $_POST['use_waitmodem'] : 'no';
    $mode = isset($_POST['mode']) ? $_POST['mode'] : 'SSH-WS.';

    $config = str_replace("\r", "", $config);
    exec('echo "'.$mode.'" > config/mode.default');
    exec('echo "'.$config.'" > config/'.$conf);
    exec('sed \'/host=\|port=\|pudp=\|user=\|pass=\|sni=\|payload=\|mode=\|trojan\|\n/d\' config/\''.$conf.'\' > /var/vmess1.txt');
    exec('awk \'{ printf "%s", $0 }\' /var/vmess1.txt > /var/vmess2.txt');
    exec('sed \'/host=\|port=\|pudp=\|user=\|pass=\|sni=\|payload=\|mode=\|vmess\|\n/d\' config/\''.$conf.'\' > /var/trojan1.txt');
    exec('awk \'{ printf "%s", $0 }\' /var/trojan1.txt > /var/trojan2.txt');
    exec('echo "'.$config.'" > config.txt');
    exec('sed -i \'s/\r$//g\' config.txt');
    exec('sed -i \'s/\r$//g\' config/'.$conf);
    exec('sed -i \':a;N;$!ba;s/\n\n//g\' config/'.$conf);
    exec('sed -i \':a;N;$!ba;s/\n\n//g\' config.txt');
    exec('sed -i \'/^#/!s/mode=.*//\' config/'.$conf);
    exec('sed -i \'/^#/!s/mode=.*//\' config.txt');
    exec('echo "'.$use_stunnel.'" > config/stun');
    exec('echo "'.$use_gotun.'" > config/gotun');
    exec('echo "'.$use_restfw.'" > config/firewall');
    exec('echo "'.$use_waitmodem.'" > config/modem');
    exec('echo "'.$conf.'" > config/default');
    exec('echo "Config telah di update." > loglain.txt');
    exec('echo "\''.$conf.'\' Menjadi default Config. !" >> loglain.txt');
    
    $use_boot = isset($_POST['use_boot']) ? $_POST['use_boot'] : 'no';
    if ($use_boot <> 'yes' ){ exec('./xderm-mini disable'); }
    else { exec('./xderm-mini enable'); }
    render_log_controls();
    echo "<div id='loglain' class='terminal-box'></div>";
  }

  if (isset($_POST['button5'])) {
    echo "<div style='font-size:14px; font-weight:bold; margin-bottom:8px;'>Xderm Mini Informations</div>";
    echo "<textarea name='aboutbox' id='aboutbox' rows='12' class='input-field' style='font-family:monospace; font-size:11px;' wrap='hard' readonly>
Xderm Mini is simple injector tool based on shell script and python commands for OpenWrt by @ryanfauzi1.

=============================================
           Supported Modes
=============================================
1. SSH-WS  : SSH WebSocket CDN (CloudFront/Cloudflare)
2. SSH     : SSH SSL Direct (Stunnel / Python)
3. Vmess   : V2Ray VMess Protocol
4. Trojan  : Trojan VPN
5. Multi   : Auto-Switching Multi Inject

=============================================
          Default config.txt (SSH-WS)
=============================================
host=dz1wsoabehhmc.cloudfront.net
port=443
pudp=7300
user=xxxx
pass=xxxx
sni=dz1wsoabehhmc.cloudfront.net
payload=GET / HTTP/1.1[crlf]Host: [host][crlf]Upgrade: websocket[crlf][crlf]
mode=SSH-WS.
=============================================
</textarea>";
    echo '<div style="margin-top:10px; display:flex; gap:6px; justify-content:center; flex-wrap:wrap;">
            <input type="submit" name="button6" class="btn" id="rmlogin" value="Remove / Install Login Page"/>
            <input type="submit" name="button7" class="btn" id="reinstall" value="Force Reinstall"/>
            <input type="submit" name="button4" class="btn" id="update" value="Check Update"/>
          </div>';
  }

  if (isset($_POST['button2'])) {
    exec("cat config/mode.list|awk 'NR==1'", $adamode);
    if (empty($adamode[0])) {
      exec("echo SSH. >> config/mode.list");
      exec("echo SSH-WS. >> config/mode.list");
      exec("echo Vmess. >> config/mode.list");
      exec("echo Trojan. >> config/mode.list");
      exec("echo Multi. >> config/mode.list");
    }

    exec("cat config/config.list|awk 'NR==1'", $ada);
    if (!empty($ada[0])) {
      exec("cat config/default", $default);
      $cur_def = !empty($default[0]) ? trim($default[0]) : "config1";
      $data = file_exists("config/$cur_def") ? file_get_contents("config/$cur_def") : file_get_contents("config.txt");
    } else {
      exec("mkdir -p config; touch config/config.list config/config1 config/config2 config/config3 config/config4 config/config5 config/mode.list");
      exec("echo config1 >> config/config.list");
      exec("echo config2 >> config/config.list");
      exec("echo config3 >> config/config.list");
      exec("echo config4 >> config/config.list");
      exec("echo config5 >> config/config.list");
      exec("echo config1 >> config/default");
      $cur_def = "config1";
      $data = file_get_contents("config.txt");
    }

    $data1 = file_exists("config/config1") ? file_get_contents("config/config1") : "";
    $data2 = file_exists("config/config2") ? file_get_contents("config/config2") : "";
    $data3 = file_exists("config/config3") ? file_get_contents("config/config3") : "";
    $data4 = file_exists("config/config4") ? file_get_contents("config/config4") : "";
    $data5 = file_exists("config/config5") ? file_get_contents("config/config5") : "";

    echo "<div style='font-size:13px; font-weight:600; margin-bottom:8px;'>Active Profile: [ <span style='color:#10b981;'>$cur_def</span> ]</div>";

    // Dual-Mode Tabs: Form Mode vs Raw Mode
    echo '<div class="config-tabs">
            <button type="button" class="tab-btn active" id="tab_form" onclick="switchConfigTab(\'form\')">Form Editor (Mudah)</button>
            <button type="button" class="tab-btn" id="tab_raw" onclick="switchConfigTab(\'raw\')">Raw Text (Manual)</button>
          </div>';

    // 1. Easy Form Editor
    echo '<div id="form_editor" class="form-grid">
            <div class="form-group">
              <label>Host / CDN Domain:</label>
              <input type="text" id="f_host" class="input-field" placeholder="dz1wsoabehhmc.cloudfront.net" oninput="syncFormToRaw()">
            </div>
            <div class="form-row">
              <div class="form-group">
                <label>Port:</label>
                <input type="text" id="f_port" class="input-field" placeholder="443" oninput="syncFormToRaw()">
              </div>
              <div class="form-group">
                <label>SNI Bug:</label>
                <input type="text" id="f_sni" class="input-field" placeholder="dz1wsoabehhmc.cloudfront.net" oninput="syncFormToRaw()">
              </div>
            </div>
            <div class="form-row">
              <div class="form-group">
                <label>Username:</label>
                <input type="text" id="f_user" class="input-field" placeholder="username" oninput="syncFormToRaw()">
              </div>
              <div class="form-group">
                <label>Password:</label>
                <div class="pass-wrapper">
                  <input type="password" id="f_pass" class="input-field" placeholder="password" oninput="syncFormToRaw()">
                  <button type="button" class="btn-toggle-pass" onclick="togglePassVisibility()">👁️</button>
                </div>
              </div>
            </div>
            <div class="form-group">
              <label>UDPgw Port:</label>
              <input type="text" id="f_pudp" class="input-field" placeholder="7300" oninput="syncFormToRaw()">
            </div>
            <div class="form-group">
              <div style="display:flex; justify-content:space-between; align-items:center;">
                <label>Payload (WebSocket / HTTP):</label>
                <button type="button" id="btn_preset_cf" class="btn-preset" onclick="presetCloudFront()">+ CloudFront WS Preset</button>
              </div>
              <textarea id="f_payload" class="input-field" rows="3" placeholder="GET / HTTP/1.1[crlf]Host: [host][crlf]Upgrade: websocket[crlf][crlf]" oninput="syncFormToRaw()"></textarea>
            </div>
          </div>';

    // 2. Raw Text Editor
    echo '<div id="raw_editor" style="display:none;">
            <textarea name="configbox" id="isi" class="input-field" rows="9" wrap="hard" oninput="syncRawToForm()">' . htmlspecialchars($data) . '</textarea>
          </div>';

    // Hidden textareas for profile switching
    echo "<textarea id='isi1' style='display:none;'>" . htmlspecialchars($data1) . "</textarea>";
    echo "<textarea id='isi2' style='display:none;'>" . htmlspecialchars($data2) . "</textarea>";
    echo "<textarea id='isi3' style='display:none;'>" . htmlspecialchars($data3) . "</textarea>";
    echo "<textarea id='isi4' style='display:none;'>" . htmlspecialchars($data4) . "</textarea>";
    echo "<textarea id='isi5' style='display:none;'>" . htmlspecialchars($data5) . "</textarea>";

    // Options and Profile Selector
    echo '<div style="margin-top:12px; background:#111827; border:1px solid #374151; border-radius:6px; padding:10px;">';
    echo '<div style="display:flex; gap:8px; align-items:center; margin-bottom:8px;">
            <label style="font-size:12px; font-weight:600; color:#9ca3af;">Profile:</label>
            <select name="profile" id="idconf" class="input-field" style="width:auto; flex:1;" onchange="shipping_calc()">';
    exec("cat config/config.list", $list);
    exec("cat config/default", $default);
    $default = !empty($default[0]) ? trim($default[0]) : "config1";
    for ($x = 0; $x < count($list); $x++) {
      $item = trim($list[$x]);
      if ($default === $item) { echo "<option value=\"$item\" selected>$item</option>"; }
      else { echo "<option value=\"$item\">$item</option>"; }
    }
    echo '</select>';

    echo '<label style="font-size:12px; font-weight:600; color:#9ca3af; margin-left:6px;">Mode:</label>
          <select name="mode" id="idmode" class="input-field" style="width:auto; flex:1;">';
    exec("cat config/mode.list", $modelist);
    exec("cat config/mode.default", $modedefault);
    $modedefault = !empty($modedefault[0]) ? trim($modedefault[0]) : "SSH-WS.";
    for ($u = 0; $u < count($modelist); $u++) {
      $mitem = trim($modelist[$u]);
      if ($modedefault === $mitem) { echo "<option value=\"$mitem\" selected>$mitem</option>"; }
      else { echo "<option value=\"$mitem\">$mitem</option>"; }
    }
    echo '</select></div>';

    // Checkbox Options
    exec("cat config/stun 2>/dev/null", $stun);
    $is_stun = (!empty($stun[0]) && trim($stun[0]) === "yes");
    exec("cat config/gotun 2>/dev/null", $gotun);
    $is_gotun = (!empty($gotun[0]) && trim($gotun[0]) === "yes");
    exec("cat config/firewall 2>/dev/null", $restfw);
    $is_restfw = (!empty($restfw[0]) && trim($restfw[0]) === "yes");
    exec("cat config/modem 2>/dev/null", $modem);
    $is_modem = (!empty($modem[0]) && trim($modem[0]) === "yes");
    exec("cat /etc/rc.local 2>/dev/null|grep xderm|grep button|awk '{print $2}'|awk 'NR==1'", $boot);
    $is_boot = !empty($boot[0]);

    echo '<div class="options-grid">
            <label><input type="checkbox" name="use_stunnel" value="yes" ' . ($is_stun ? 'checked' : '') . '> stunnel</label>
            <label><input type="checkbox" name="use_gotun" value="yes" ' . ($is_gotun ? 'checked' : '') . '> go-tun2socks</label>
            <label><input type="checkbox" name="use_restfw" value="yes" ' . ($is_restfw ? 'checked' : '') . '> Restart Firewall</label>
            <label><input type="checkbox" name="use_waitmodem" value="yes" ' . ($is_modem ? 'checked' : '') . '> Waiting Modem</label>
            <label><input type="checkbox" name="use_boot" value="yes" ' . ($is_boot ? 'checked' : '') . '> Auto ON-Boot</label>
          </div>';

    echo '<input type="submit" name="simpan" class="btn" style="width:100%; background:#059669; color:#fff; padding:10px; font-size:14px; margin-top:8px;" value="Simpan Konfigurasi"/>';
    echo '</div>';
    echo '<script>syncRawToForm();</script>';
  } else {
    if (!isset($_POST['button5']) && !isset($_POST['simpan']) && !isset($_POST['button6']) && !isset($_POST['button7']) && !isset($_POST['button4']) && !isset($_POST['button1'])) {
      render_log_controls();
      echo "<div id='log' class='terminal-box'></div>";
    }
  }

  if (isset($_POST['button6'])) {
    if (file_exists("login.php") || file_exists("header.php")) {
      rename("login.php", "login.php.xdrtool");
      rename("header.php", "header.php.xdrtool");
      echo '<div style="color:#10b981; margin:10px 0;">Login page terhapus!</div>';
    } elseif (file_exists("login.php.xdrtool") || file_exists("header.php.xdrtool")) {
      rename("login.php.xdrtool", "login.php");
      rename("header.php.xdrtool", "header.php");
      echo '<div style="color:#10b981; margin:10px 0;">Login page terinstall!</div>';
    }
  }

  if (isset($_POST['button7'])) {
    echo '<div style="color:#fbbf24; margin:10px 0;">Reinstalling files...</div>';
    exec('wget -O /www/xderm/index.html https://raw.githubusercontent.com/anjadcode/xderm-ws/main/index.html -q');
    exec('wget -O /www/xderm/xderm-mini https://raw.githubusercontent.com/anjadcode/xderm-ws/main/xderm-mini -q');
    exec('wget -O /www/xderm/xderm_ws.py https://raw.githubusercontent.com/anjadcode/xderm-ws/main/xderm_ws.py -q');
    exec('chmod +x /www/xderm/xderm-mini /www/xderm/xderm_ws.py');
    exec('wget -O /www/xderm/js/jquery-2.1.3.min.js https://raw.githubusercontent.com/anjadcode/xderm-ws/main/jquery-2.1.3.min.js -q');
    exec('wget -O /www/xderm/index.php https://raw.githubusercontent.com/anjadcode/xderm-ws/main/index.php -q');
    echo '<div style="color:#10b981; margin:10px 0;">Reinstall selesai! Silahkan refresh halaman.</div>';
  }
?>
    </form>

    <div class="footer">
      <span>Xderm Mini GUI • SSH WS CDN Supported</span><br>
      <span>OpenWrt aarch64 • Dual-Stack Firewall (iptables + nftables)</span>
    </div>
</div>
</body>
</html>
