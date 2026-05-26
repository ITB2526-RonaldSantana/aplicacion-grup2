<?php
session_start();

// ── CONFIGURACIÓ BD ──────────────────────────────────────────
define('DB_HOST', '32.197.67.184');
define('DB_USER', 'webadmin');        // Canviar pel teu usuari
define('DB_PASS', 'contrasenya_segura');    // Canviar per la teva contrasenya
define('DB_NAME', 'InnovateTech');

function getDB() {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($conn->connect_error) die(json_encode(['error' => $conn->connect_error]));
    $conn->set_charset('utf8mb4');
    return $conn;
}

// ── PERMISOS PER ROL ─────────────────────────────────────────
$ROL_PERMISOS = [
    'admin' => [
        'taules'   => ['DEPARTAMENT','EMPLEAT','USUARI','ROL','USUARI_ROL',
                       'GRUP_QUALITAT','TRUCADA','VIDEO','MESURA_AMPLADA_BANDA',
                       'CONFIGURACIO_SERVIDOR','AVIS','CONTROL_BACKUP','CONTRASENYES'],
        'readonly' => [],
    ],
    'vendes' => [
        'taules'   => ['TRUCADA','USUARI','VIDEO'],
        'readonly' => ['USUARI','VIDEO'],
    ],
    'administracio' => [
        'taules'   => ['EMPLEAT','DEPARTAMENT','USUARI','USUARI_ROL'],
        'readonly' => [],
    ],
    'treballador' => [
        'taules'   => ['VIDEO','CONFIGURACIO_SERVIDOR','TRUCADA'],
        'readonly' => ['VIDEO','CONFIGURACIO_SERVIDOR','TRUCADA'],
    ],
];

function getRol() { return $_SESSION['rol'] ?? ''; }

function tableAllowed($table) {
    global $ROL_PERMISOS;
    $rol = getRol();
    return in_array(strtoupper($table),
        array_map('strtoupper', $ROL_PERMISOS[$rol]['taules'] ?? []));
}

function isReadonly($table) {
    global $ROL_PERMISOS;
    $rol = getRol();
    return in_array(strtoupper($table),
        array_map('strtoupper', $ROL_PERMISOS[$rol]['readonly'] ?? []));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');

    // LOGIN DINÀMIC DES DE LA BASE DE DADES (AMB TAULA CONTRASENYES)
    if ($_POST['action'] === 'login') {
        $email = $_POST['username'] ?? '';
        $p = $_POST['password'] ?? '';

        if (empty($email) || empty($p)) {
            echo json_encode(['ok' => false, 'msg' => 'Introdueix l\'email i la contrasenya']);
            exit;
        }

        $db = getDB();

        // Consulta unint USUARI, la taula CONTRASENYES (només l'activa) i el seu ROL
        $stmt = $db->prepare("
            SELECT u.id_usuari, u.nom_complet, u.estat, u.email, c.hash_contrasenya AS pass_db, ur.nom_rol
            FROM USUARI u
            INNER JOIN CONTRASENYES c ON u.id_usuari = c.usuari_id
            LEFT JOIN USUARI_ROL ur ON u.id_usuari = ur.id_usuari
            WHERE u.email = ? AND c.activa = 1
            LIMIT 1
        ");

        if (!$stmt) {
            echo json_encode(['ok' => false, 'msg' => 'Error en preparar la consulta a la Base de Dades']);
            exit;
        }

        $stmt->bind_param('s', $email);
        $stmt->execute();
        $res = $stmt->get_result();

        if ($res && $userRow = $res->fetch_assoc()) {
            if ($userRow['estat'] === 'bloquejat') {
                echo json_encode(['ok' => false, 'msg' => 'Aquest usuari està bloquejat']);
                exit;
            }

            $pwd_db = $userRow['pass_db'];
            // Verificació dual: suporta hash bcrypt (creat pel script) i text pla (dades de prova)
            $validPassword = password_verify($p, $pwd_db) || ($p === $pwd_db);

            if ($validPassword) {
                $_SESSION['user']        = $userRow['email'];
                $_SESSION['nom_complet'] = $userRow['nom_complet'];
                $_SESSION['rol']         = $userRow['nom_rol'] ?? 'treballador';

                echo json_encode([
                    'ok' => true,
                    'rol' => $_SESSION['rol'],
                    'nom_complet' => $_SESSION['nom_complet']
                ]);
            } else {
                echo json_encode(['ok' => false, 'msg' => 'Contrasenya incorrecta']);
            }
        } else {
            echo json_encode(['ok' => false, 'msg' => 'No s\'ha trobat cap usuari actiu amb aquest email']);
        }
        $stmt->close();
        $db->close();
        exit;
    }

    if (!isset($_SESSION['user'])) { echo json_encode(['error' => 'No autenticat']); exit; }

    $db     = getDB();
    $action = $_POST['action'];
    $table  = preg_replace('/[^a-zA-Z_]/', '', $_POST['table'] ?? '');

    // LLEGIR
    if ($action === 'read') {
        if (!tableAllowed($table)) {
            echo json_encode(['error' => 'Acces denegat: el teu rol no pot veure aquesta taula.']); exit;
        }
        $search = isset($_POST['search']) ? '%' . $db->real_escape_string($_POST['search']) . '%' : '%';
        $cols   = $db->query("SHOW COLUMNS FROM `$table`");
        $fields = [];
        while ($c = $cols->fetch_assoc()) $fields[] = $c['Field'];
        $whereClause = '';
        if ($search !== '%%') {
            $parts = array_map(fn($f) => "`$f` LIKE '$search'", $fields);
            $whereClause = 'WHERE ' . implode(' OR ', $parts);
        }
        $res  = $db->query("SELECT * FROM `$table` $whereClause LIMIT 200");
        $rows = [];
        while ($r = $res->fetch_assoc()) $rows[] = $r;
        echo json_encode(['cols' => $fields, 'rows' => $rows, 'readonly' => isReadonly($table)]);
        exit;
    }

    // INSERIR
    if ($action === 'insert') {
        if (!tableAllowed($table) || isReadonly($table)) {
            echo json_encode(['error' => 'Acces denegat: no tens permisos per inserir en aquesta taula.']); exit;
        }
        $data = json_decode($_POST['data'], true);
        $cols = implode('`,`', array_keys($data));
        $vals = implode("','", array_map([$db, 'real_escape_string'], array_values($data)));
        $db->query("INSERT INTO `$table` (`$cols`) VALUES ('$vals')");
        echo json_encode(['ok' => true, 'id' => $db->insert_id]);
        exit;
    }

    // ACTUALITZAR
    if ($action === 'update') {
        if (!tableAllowed($table) || isReadonly($table)) {
            echo json_encode(['error' => 'Acces denegat: no tens permisos per modificar aquesta taula.']); exit;
        }
        $data = json_decode($_POST['data'], true);
        $id   = (int)$_POST['id'];
        $pk   = $db->real_escape_string($_POST['pk']);
        $set  = implode(',', array_map(fn($k,$v) => "`$k`='" . $db->real_escape_string($v) . "'", array_keys($data), $data));
        $db->query("UPDATE `$table` SET $set WHERE `$pk`=$id");
        echo json_encode(['ok' => true]);
        exit;
    }

    // ESBORRAR
    if ($action === 'delete') {
        if (!tableAllowed($table) || isReadonly($table)) {
            echo json_encode(['error' => 'Acces denegat: no tens permisos per eliminar en aquesta taula.']); exit;
        }
        $id = (int)$_POST['id'];
        $pk = $db->real_escape_string($_POST['pk']);
        $db->query("DELETE FROM `$table` WHERE `$pk`=$id");
        echo json_encode(['ok' => true]);
        exit;
    }

    // LOGOUT
    if ($action === 'logout') {
        session_destroy();
        echo json_encode(['ok' => true]);
        exit;
    }

    // TAULES DISPONIBLES
    if ($action === 'tables') {
        global $ROL_PERMISOS;
        $rol = getRol();
        echo json_encode($ROL_PERMISOS[$rol]['taules'] ?? []);
        exit;
    }

    echo json_encode(['error' => 'Accio desconeguda']);
    exit;
}
?>
<!DOCTYPE html>
<html lang="ca">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>InnovateTech — Panel de gestió</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Mono:wght@300;400;500&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
  --bg:       #0a0a0f;
  --bg2:      #111118;
  --bg3:      #1a1a24;
  --border:   #2a2a3a;
  --accent:   #6c63ff;
  --accent2:  #ff6584;
  --accent3:  #43e8b0;
  --text:     #e8e8f0;
  --muted:    #6b6b80;
  --success:  #43e8b0;
  --danger:   #ff6584;
  --font:     'Syne', sans-serif;
  --mono:     'DM Mono', monospace;
  --radius:   12px;
  --shadow:   0 8px 32px rgba(108,99,255,0.15);
}

body {
  font-family: var(--font);
  background: var(--bg);
  color: var(--text);
  min-height: 100vh;
  overflow-x: hidden;
}

body::before {
  content: '';
  position: fixed;
  inset: 0;
  background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 256 256' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='noise'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23noise)' opacity='0.03'/%3E%3C/svg%3E");
  pointer-events: none;
  z-index: 0;
}

#login-screen {
  position: fixed;
  inset: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 100;
  background: var(--bg);
}

.login-card {
  width: 420px;
  padding: 56px 48px;
  background: var(--bg2);
  border: 1px solid var(--border);
  border-radius: 24px;
  box-shadow: var(--shadow), 0 0 80px rgba(108,99,255,0.08);
  animation: fadeUp 0.6s ease;
}

.login-logo {
  font-size: 13px;
  font-weight: 600;
  letter-spacing: 0.2em;
  text-transform: uppercase;
  color: var(--accent);
  margin-bottom: 8px;
  font-family: var(--mono);
}

.login-title {
  font-size: 32px;
  font-weight: 800;
  line-height: 1.1;
  margin-bottom: 40px;
  background: linear-gradient(135deg, var(--text) 0%, var(--muted) 100%);
  -webkit-background-clip: text;
  -webkit-text-fill-color: transparent;
}

.field { margin-bottom: 20px; }
.field label {
  display: block;
  font-size: 12px;
  font-weight: 600;
  letter-spacing: 0.1em;
  text-transform: uppercase;
  color: var(--muted);
  margin-bottom: 8px;
  font-family: var(--mono);
}
.field input {
  width: 100%;
  padding: 14px 16px;
  background: var(--bg3);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  color: var(--text);
  font-family: var(--mono);
  font-size: 14px;
  outline: none;
  transition: border-color 0.2s, box-shadow 0.2s;
}
.field input:focus {
  border-color: var(--accent);
  box-shadow: 0 0 0 3px rgba(108,99,255,0.15);
}

.btn {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 14px 24px;
  border: none;
  border-radius: var(--radius);
  font-family: var(--font);
  font-size: 14px;
  font-weight: 700;
  cursor: pointer;
  transition: all 0.2s;
  letter-spacing: 0.03em;
}
.btn-primary {
  background: var(--accent);
  color: #fff;
  width: 100%;
  justify-content: center;
  font-size: 15px;
}
.btn-primary:hover { background: #7c74ff; transform: translateY(-1px); box-shadow: 0 8px 24px rgba(108,99,255,0.4); }
.btn-sm { padding: 8px 14px; font-size: 12px; }
.btn-success { background: rgba(67,232,176,0.15); color: var(--success); border: 1px solid rgba(67,232,176,0.3); }
.btn-success:hover { background: rgba(67,232,176,0.25); }
.btn-danger  { background: rgba(255,101,132,0.15); color: var(--danger);  border: 1px solid rgba(255,101,132,0.3); }
.btn-danger:hover  { background: rgba(255,101,132,0.25); }
.btn-ghost { background: transparent; color: var(--muted); border: 1px solid var(--border); }
.btn-ghost:hover { color: var(--text); border-color: var(--muted); }

.login-error {
  margin-top: 16px;
  padding: 12px;
  background: rgba(255,101,132,0.1);
  border: 1px solid rgba(255,101,132,0.3);
  border-radius: 8px;
  color: var(--danger);
  font-size: 13px;
  font-family: var(--mono);
  display: none;
}

#app { display: none; min-height: 100vh; }

.sidebar {
  position: fixed;
  left: 0; top: 0; bottom: 0;
  width: 260px;
  background: var(--bg2);
  border-right: 1px solid var(--border);
  display: flex;
  flex-direction: column;
  z-index: 50;
  padding: 32px 0;
}

.sidebar-logo {
  padding: 0 28px 32px;
  border-bottom: 1px solid var(--border);
}
.sidebar-logo .logo-tag {
  font-size: 10px;
  font-family: var(--mono);
  color: var(--accent);
  letter-spacing: 0.2em;
  text-transform: uppercase;
  margin-bottom: 4px;
}
.sidebar-logo h1 {
  font-size: 20px;
  font-weight: 800;
  background: linear-gradient(135deg, var(--accent), var(--accent2));
  -webkit-background-clip: text;
  -webkit-text-fill-color: transparent;
}

.sidebar-section {
  padding: 24px 16px 8px;
}
.sidebar-section-label {
  font-size: 10px;
  font-family: var(--mono);
  letter-spacing: 0.15em;
  text-transform: uppercase;
  color: var(--muted);
  padding: 0 12px;
  margin-bottom: 8px;
}

.table-btn {
  display: flex;
  align-items: center;
  gap: 10px;
  width: 100%;
  padding: 10px 12px;
  background: transparent;
  border: none;
  border-radius: 8px;
  color: var(--muted);
  font-family: var(--font);
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
  transition: all 0.2s;
  text-align: left;
}
.table-btn:hover { background: var(--bg3); color: var(--text); }
.table-btn.active { background: rgba(108,100,255,0.15); color: var(--accent); }
.table-btn .dot {
  width: 6px; height: 6px;
  border-radius: 50%;
  background: var(--border);
  flex-shrink: 0;
}
.table-btn.active .dot { background: var(--accent); box-shadow: 0 0 6px var(--accent); }

.sidebar-footer {
  margin-top: auto;
  padding: 24px 16px 0;
  border-top: 1px solid var(--border);
}
.user-badge {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 12px;
  background: var(--bg3);
  border-radius: var(--radius);
  margin-bottom: 12px;
}
.user-avatar {
  width: 36px; height: 36px;
  border-radius: 50%;
  background: linear-gradient(135deg, var(--accent), var(--accent2));
  display: flex;
  align-items: center;
  justify-content: center;
  font-weight: 800;
  font-size: 14px;
  color: #fff;
  flex-shrink: 0;
}
.user-info { flex: 1; overflow: hidden; }
.user-name { font-size: 13px; font-weight: 700; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.user-rol { font-size: 11px; font-family: var(--mono); color: var(--muted); }

.main {
  margin-left: 260px;
  padding: 40px;
  min-height: 100vh;
}

.page-header {
  display: flex;
  align-items: flex-end;
  justify-content: space-between;
  margin-bottom: 32px;
  gap: 16px;
  flex-wrap: wrap;
}
.page-title-wrap .breadcrumb {
  font-size: 11px;
  font-family: var(--mono);
  color: var(--muted);
  letter-spacing: 0.1em;
  text-transform: uppercase;
  margin-bottom: 6px;
}
.page-title {
  font-size: 28px;
  font-weight: 800;
  background: linear-gradient(135deg, var(--text), var(--muted));
  -webkit-background-clip: text;
  -webkit-text-fill-color: transparent;
}

.toolbar {
  display: flex;
  gap: 12px;
  align-items: center;
  flex-wrap: wrap;
}

.search-wrap {
  position: relative;
}
.search-wrap input {
  padding: 10px 16px 10px 40px;
  background: var(--bg2);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  color: var(--text);
  font-family: var(--mono);
  font-size: 13px;
  outline: none;
  width: 240px;
  transition: border-color 0.2s;
}
.search-wrap input:focus { border-color: var(--accent); }
.search-wrap::before {
  content: '⌕';
  position: absolute;
  left: 14px;
  top: 50%;
  transform: translateY(-50%);
  color: var(--muted);
  font-size: 16px;
}

.stats-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
  gap: 16px;
  margin-bottom: 32px;
}
.stat-card {
  background: var(--bg2);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  padding: 20px 24px;
  position: relative;
  overflow: hidden;
  transition: border-color 0.2s;
}
.stat-card::before {
  content: '';
  position: absolute;
  top: 0; left: 0; right: 0;
  height: 2px;
  background: linear-gradient(90deg, var(--accent), var(--accent2));
}
.stat-card:hover { border-color: var(--accent); }
.stat-label { font-size: 11px; font-family: var(--mono); color: var(--muted); letter-spacing: 0.1em; text-transform: uppercase; margin-bottom: 8px; }
.stat-value { font-size: 28px; font-weight: 800; background: linear-gradient(135deg, var(--text), var(--accent)); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }

.table-card {
  background: var(--bg2);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  overflow: hidden;
}
.table-wrap { overflow-x: auto; }

table {
  width: 100%;
  border-collapse: collapse;
  font-size: 13px;
}
thead tr {
  background: var(--bg3);
  border-bottom: 1px solid var(--border);
}
th {
  padding: 14px 16px;
  text-align: left;
  font-family: var(--mono);
  font-size: 11px;
  font-weight: 500;
  letter-spacing: 0.1em;
  text-transform: uppercase;
  color: var(--muted);
  white-space: nowrap;
}
td {
  padding: 13px 16px;
  border-bottom: 1px solid rgba(42,42,58,0.5);
  color: var(--text);
  font-family: var(--mono);
  font-size: 12px;
  white-space: nowrap;
  max-width: 200px;
  overflow: hidden;
  text-overflow: ellipsis;
}
tbody tr { transition: background 0.15s; }
tbody tr:hover { background: rgba(108,99,255,0.05); }
tbody tr:last-child td { border-bottom: none; }

.actions-cell { display: flex; gap: 6px; }

.modal-overlay {
  position: fixed;
  inset: 0;
  background: rgba(0,0,0,0.7);
  backdrop-filter: blur(4px);
  z-index: 200;
  display: flex;
  align-items: center;
  justify-content: center;
  opacity: 0;
  pointer-events: none;
  transition: opacity 0.2s;
}
.modal-overlay.open { opacity: 1; pointer-events: all; }
.modal {
  background: var(--bg2);
  border: 1px solid var(--border);
  border-radius: 20px;
  padding: 40px;
  width: 560px;
  max-width: 95vw;
  max-height: 85vh;
  overflow-y: auto;
  box-shadow: var(--shadow);
  transform: translateY(20px);
  transition: transform 0.2s;
}
.modal-overlay.open .modal { transform: translateY(0); }
.modal-title {
  font-size: 20px;
  font-weight: 800;
  margin-bottom: 28px;
  display: flex;
  align-items: center;
  gap: 12px;
}
.modal-title span { font-size: 13px; font-family: var(--mono); color: var(--muted); font-weight: 400; }
.modal-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 28px; }
.modal-grid .field:only-child,
.modal-grid .field.full { grid-column: 1 / -1; }
.modal-actions { display: flex; gap: 12px; justify-content: flex-end; }

.toast {
  position: fixed;
  bottom: 32px;
  right: 32px;
  padding: 14px 20px;
  border-radius: var(--radius);
  font-size: 13px;
  font-family: var(--mono);
  font-weight: 500;
  z-index: 999;
  transform: translateY(100px);
  transition: transform 0.3s cubic-bezier(0.34,1.56,0.64,1);
  pointer-events: none;
}
.toast.show { transform: translateY(0); }
.toast.success { background: rgba(67,232,176,0.15); border: 1px solid rgba(67,232,176,0.4); color: var(--success); }
.toast.error   { background: rgba(255,101,132,0.15); border: 1px solid rgba(255,101,132,0.4); color: var(--danger); }

.empty {
  text-align: center;
  padding: 64px 32px;
  color: var(--muted);
}
.empty-icon { font-size: 40px; margin-bottom: 16px; opacity: 0.4; }
.empty-text { font-size: 14px; font-family: var(--mono); }

.loading {
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 64px;
  gap: 8px;
  color: var(--muted);
  font-family: var(--mono);
  font-size: 13px;
}
.spinner {
  width: 16px; height: 16px;
  border: 2px solid var(--border);
  border-top-color: var(--accent);
  border-radius: 50%;
  animation: spin 0.8s linear infinite;
}

@keyframes spin    { to { transform: rotate(360deg); } }
@keyframes fadeUp  { from { opacity: 0; transform: translateY(24px); } to { opacity: 1; transform: translateY(0); } }

.welcome {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  min-height: 60vh;
  text-align: center;
  animation: fadeUp 0.5s ease;
}
.welcome-emoji { font-size: 64px; margin-bottom: 24px; }
.welcome h2 { font-size: 32px; font-weight: 800; margin-bottom: 12px; }
.welcome p { font-size: 14px; color: var(--muted); font-family: var(--mono); }

.tag {
  display: inline-flex;
  align-items: center;
  padding: 3px 10px;
  border-radius: 20px;
  font-size: 11px;
  font-family: var(--mono);
  font-weight: 500;
}
.tag-accent { background: rgba(108,99,255,0.15); color: var(--accent); }
</style>
</head>
<body>

<div id="login-screen">
  <div class="login-card">
    <div class="login-logo">InnovateTech · CPD</div>
    <h2 class="login-title">Panel de<br>gestió</h2>
    <div class="field">
      <label>Email de l'usuari</label>
      <input type="text" id="login-user" placeholder="joan.garcia@innovatech.com" autocomplete="username">
    </div>
    <div class="field">
      <label>Contrasenya</label>
      <input type="password" id="login-pass" placeholder="••••••••" autocomplete="current-password">
    </div>
    <button class="btn btn-primary" onclick="doLogin()">Accedir →</button>
    <div class="login-error" id="login-error"></div>
  </div>
</div>

<div id="app">
  <aside class="sidebar">
    <div class="sidebar-logo">
      <div class="logo-tag">Sistema de gestió</div>
      <h1>InnovateTech</h1>
    </div>
    <div class="sidebar-section">
      <div class="sidebar-section-label">Taules</div>
      <div id="table-list"></div>
    </div>
    <div class="sidebar-footer">
      <div class="user-badge">
        <div class="user-avatar" id="user-avatar">A</div>
        <div class="user-info">
          <div class="user-name" id="user-name">Admin</div>
          <div class="user-rol" id="user-rol">admin</div>
        </div>
      </div>
      <button class="btn btn-ghost" style="width:100%;justify-content:center" onclick="doLogout()">Tancar sessió</button>
    </div>
  </aside>

  <main class="main">
    <div id="content">
      <div class="welcome">
        <div class="welcome-emoji">⚡</div>
        <h2>Benvingut al CPD</h2>
        <p>Selecciona una taula al menú lateral<br>per començar a gestionar les dades.</p>
      </div>
    </div>
  </main>
</div>

<div class="modal-overlay" id="modal">
  <div class="modal">
    <div class="modal-title" id="modal-title">Nou registre</div>
    <div class="modal-grid" id="modal-fields"></div>
    <div class="modal-actions">
      <button class="btn btn-ghost" onclick="closeModal()">Cancel·lar</button>
      <button class="btn btn-primary" id="modal-save" onclick="saveModal()">Desar</button>
    </div>
  </div>
</div>

<div class="toast" id="toast"></div>

<script>
let currentTable    = null;
let currentCols     = [];
let currentRows     = [];
let currentReadonly = false;
let editingId       = null;
let editingPk       = null;

// ── LOGIN ─────────────────────────────────────────────────────
async function doLogin() {
  const u = document.getElementById('login-user').value.trim();
  const p = document.getElementById('login-pass').value;
  const errorEl = document.getElementById('login-error');

  errorEl.style.display = 'none';

  try {
    const r = await post({ action:'login', username:u, password:p });
    if (r && r.ok) {
      document.getElementById('login-screen').style.display = 'none';
      document.getElementById('app').style.display = 'block';

      document.getElementById('user-name').textContent = r.nom_complet;
      document.getElementById('user-rol').textContent  = r.rol;
      document.getElementById('user-avatar').textContent = r.nom_complet ? r.nom_complet[0].toUpperCase() : 'U';

      loadTables();
    } else {
      errorEl.textContent = r.msg || 'Error desconegut en iniciar sessió.';
      errorEl.style.display = 'block';
    }
  } catch (err) {
    errorEl.textContent = 'Error de connexió amb el servidor.';
    errorEl.style.display = 'block';
  }
}

async function doLogout() {
  await post({ action:'logout' });
  location.reload();
}

document.getElementById('login-pass').addEventListener('keydown', e => {
  if (e.key === 'Enter') doLogin();
});

// ── TAULES ────────────────────────────────────────────────────
async function loadTables() {
  const tables = await post({ action:'tables' });
  const list   = document.getElementById('table-list');
  list.innerHTML = '';
  tables.forEach(t => {
    const btn = document.createElement('button');
    btn.className = 'table-btn';
    btn.innerHTML = `<span class="dot"></span>${t}`;
    btn.onclick = () => loadTable(t, btn);
    list.appendChild(btn);
  });
}

async function loadTable(table, btn) {
  document.querySelectorAll('.table-btn').forEach(b => b.classList.remove('active'));
  if (btn) btn.classList.add('active');
  currentTable = table;
  document.getElementById('content').innerHTML = '<div class="loading"><div class="spinner"></div> Carregant...</div>';
  const data = await post({ action:'read', table });
  currentCols     = data.cols || [];
  currentRows     = data.rows || [];
  currentReadonly = data.readonly || false;
  renderTable();
}

function renderTable(rows) {
  rows = rows || currentRows;
  const pk = currentCols[0];
  document.getElementById('content').innerHTML = `
    <div class="page-header">
      <div class="page-title-wrap">
        <div class="breadcrumb">InnovateTech · Base de dades</div>
        <h2 class="page-title">${currentTable}</h2>
      </div>
      <div class="toolbar">
        <div class="search-wrap">
          <input type="text" placeholder="Cercar..." oninput="doSearch(this.value)" id="search-input">
        </div>
        ${currentReadonly ? '' : '<button class="btn btn-primary btn-sm" onclick="openInsert()">+ Nou registre</button>'}
      </div>
    </div>
    <div class="stats-grid">
      <div class="stat-card"><div class="stat-label">Total registres</div><div class="stat-value">${rows.length}</div></div>
      <div class="stat-card"><div class="stat-label">Columnes</div><div class="stat-value">${currentCols.length}</div></div>
      <div class="stat-card"><div class="stat-label">Taula activa</div><div class="stat-value" style="font-size:16px;padding-top:6px">${currentTable}</div></div>
    </div>
    <div class="table-card">
      <div class="table-wrap">
        ${rows.length === 0
          ? '<div class="empty"><div class="empty-icon">◎</div><div class="empty-text">Sense registres</div></div>'
          : `<table>
              <thead><tr>
                ${currentCols.map(c => `<th>${c}</th>`).join('')}
                <th>Accions</th>
              </tr></thead>
              <tbody>
                ${rows.map(r => `<tr>
                  ${currentCols.map(c => `<td title="${r[c] ?? ''}">${r[c] ?? '<span style="color:var(--muted)">—</span>'}</td>`).join('')}
                  <td><div class="actions-cell">
                    ${currentReadonly ? '<span class="tag tag-accent">Només lectura</span>' : `<button class="btn btn-sm btn-success" onclick='openEdit(${JSON.stringify(r)})'>Editar</button><button class="btn btn-sm btn-danger" onclick="deleteRow(${r[pk]}, '${pk}')">Eliminar</button>`}
                  </div></td>
                </tr>`).join('')}
              </tbody>
            </table>`
        }
      </div>
    </div>`;
}

async function doSearch(q) {
  const data = await post({ action:'read', table: currentTable, search: q });
  currentRows = data.rows || [];
  renderTable(currentRows);
  const inp = document.getElementById('search-input');
  if (inp) { inp.value = q; inp.focus(); }
}

// ── MODAL ─────────────────────────────────────────────────────
function openInsert() {
  editingId = null; editingPk = null;
  document.getElementById('modal-title').innerHTML = `Nou registre <span>${currentTable}</span>`;
  buildFields({});
  document.getElementById('modal').classList.add('open');
}

function openEdit(row) {
  editingPk = currentCols[0];
  editingId = row[editingPk];
  document.getElementById('modal-title').innerHTML = `Editar registre <span>#${editingId}</span>`;
  buildFields(row);
  document.getElementById('modal').classList.add('open');
}

function buildFields(row) {
  const fields = editingId ? currentCols : currentCols.slice(1);
  document.getElementById('modal-fields').innerHTML = fields.map(c => `
    <div class="field ${fields.length === 1 ? 'full' : ''}">
      <label>${c}</label>
      <input type="text" id="field-${c}" value="${(row[c] ?? '').toString().replace(/"/g,'&quot;')}" placeholder="${c}">
    </div>`).join('');
}

function closeModal() {
  document.getElementById('modal').classList.remove('open');
}

async function saveModal() {
  const fields = editingId ? currentCols : currentCols.slice(1);
  const data = {};
  for (const c of fields) {
    data[c] = document.getElementById('field-' + c)?.value ?? '';
  }
  let r;
  if (editingId) {
    r = await post({ action:'update', table: currentTable, id: editingId, pk: editingPk, data: JSON.stringify(data) });
  } else {
    r = await post({ action:'insert', table: currentTable, data: JSON.stringify(data) });
  }
  if (r.ok) {
    closeModal();
    toast('Desat correctament', 'success');
    loadTable(currentTable);
  } else {
    toast('Error en desar: ' + (r.error || ''), 'error');
  }
}

async function deleteRow(id, pk) {
  if (!confirm(`Eliminar el registre #${id}?`)) return;
  const r = await post({ action:'delete', table: currentTable, id, pk });
  if (r.ok) {
    toast('Registre eliminat', 'success');
    loadTable(currentTable);
  } else {
    toast('Error en eliminar', 'error');
  }
}

// ── UTILS ─────────────────────────────────────────────────────
async function post(data) {
  const fd = new FormData();
  for (const [k, v] of Object.entries(data)) fd.append(k, v);
  const r = await fetch('', { method:'POST', body: fd });
  return r.json();
}

function toast(msg, type = 'success') {
  const el = document.getElementById('toast');
  el.textContent = msg;
  el.className = `toast ${type} show`;
  setTimeout(() => el.classList.remove('show'), 3000);
}

document.getElementById('modal').addEventListener('click', e => {
  if (e.target === document.getElementById('modal')) closeModal();
});
</script>
</body>
</html>
