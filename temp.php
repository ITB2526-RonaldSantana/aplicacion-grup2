<?php
// =====================================================
// InnovateTech - Aplicació Web de Gestió
// Fitxer únic PHP - Connecta amb BD InnovateTech
// =====================================================

session_start();

// --- CONFIGURACIÓ BD ---
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'InnovateTech');

function getDB() {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new PDO(
                "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
                DB_USER, DB_PASS,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                 PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
            );
        } catch (PDOException $e) {
            die(json_encode(['error' => 'Error de connexió: ' . $e->getMessage()]));
        }
    }
    return $pdo;
}

// --- HELPERS ---
function isLogged() { return isset($_SESSION['user']); }
function getUser() { return $_SESSION['user'] ?? null; }
function hasRole($role) {
    $roles = $_SESSION['user']['roles'] ?? [];
    return in_array($role, $roles) || in_array('admin', $roles);
}
function requireLogin() {
    if (!isLogged()) { header('Location: ?page=login'); exit; }
}
function redirect($url) { header('Location: ' . $url); exit; }
function h($s) { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

// --- ACCIONS AJAX / POST ---
$page = $_GET['page'] ?? 'dashboard';
$action = $_POST['action'] ?? $_GET['action'] ?? '';

// LOGIN
if ($action === 'login') {
    $email = $_POST['email'] ?? '';
    $pass  = $_POST['password'] ?? '';
    $db = getDB();
    $stmt = $db->prepare("SELECT u.*, GROUP_CONCAT(ur.nom_rol) as roles
        FROM USUARI u
        LEFT JOIN USUARI_ROL ur ON u.id_usuari = ur.id_usuari
        WHERE u.email = ? AND u.password_hash = SHA2(?, 256) AND u.estat = 'actiu'
        GROUP BY u.id_usuari");
    $stmt->execute([$email, $pass]);
    $user = $stmt->fetch();
    if ($user) {
        $user['roles'] = $user['roles'] ? explode(',', $user['roles']) : [];
        $_SESSION['user'] = $user;
        redirect('?page=dashboard');
    } else {
        $_SESSION['login_error'] = 'Credencials incorrectes o usuari bloquejat.';
        redirect('?page=login');
    }
}

// LOGOUT
if ($action === 'logout') {
    session_destroy();
    redirect('?page=login');
}

// AJAX: Obtenir dades
if ($action === 'get_data' && isLogged()) {
    header('Content-Type: application/json');
    $type = $_GET['type'] ?? '';
    $db = getDB();
    switch ($type) {
        case 'trucades':
            $stmt = $db->query("SELECT t.*, 
                u1.nom_complet as originador_nom, u2.nom_complet as destinatari_nom,
                gq.nom_grup as qualitat
                FROM TRUCADA t
                JOIN USUARI u1 ON t.usuari_originador = u1.id_usuari
                JOIN USUARI u2 ON t.usuari_destinatari = u2.id_usuari
                JOIN GRUP_QUALITAT gq ON t.id_grup_qualitat = gq.id_grup
                ORDER BY t.data_inici DESC LIMIT 50");
            echo json_encode($stmt->fetchAll()); break;

        case 'videos':
            $search = '%' . ($_GET['q'] ?? '') . '%';
            $stmt = $db->prepare("SELECT * FROM VIDEO WHERE titol LIKE ? OR categoria LIKE ? OR descripcio LIKE ? ORDER BY data_publicacio DESC");
            $stmt->execute([$search, $search, $search]);
            echo json_encode($stmt->fetchAll()); break;

        case 'mesures':
            $stmt = $db->query("SELECT m.*, u.nom_complet as operari_nom FROM MESURA_AMPLADA_BANDA m JOIN USUARI u ON m.operari_id = u.id_usuari ORDER BY m.data_hora DESC LIMIT 20");
            echo json_encode($stmt->fetchAll()); break;

        case 'usuaris':
            if (!hasRole('admin') && !hasRole('administracio')) { echo json_encode([]); break; }
            $stmt = $db->query("SELECT u.*, GROUP_CONCAT(ur.nom_rol) as roles, d.nom as departament
                FROM USUARI u
                LEFT JOIN USUARI_ROL ur ON u.id_usuari = ur.id_usuari
                LEFT JOIN EMPLEAT e ON u.dni_empleat = e.dni
                LEFT JOIN DEPARTAMENT d ON e.codi_departament = d.codi
                GROUP BY u.id_usuari ORDER BY u.nom_complet");
            echo json_encode($stmt->fetchAll()); break;

        case 'avisos':
            if (!hasRole('admin')) { echo json_encode([]); break; }
            $stmt = $db->query("SELECT a.*, u.nom_complet FROM AVIS a JOIN USUARI u ON a.usuari_id = u.id_usuari ORDER BY a.data_hora DESC LIMIT 30");
            echo json_encode($stmt->fetchAll()); break;

        case 'backups':
            if (!hasRole('admin')) { echo json_encode([]); break; }
            $stmt = $db->query("SELECT * FROM CONTROL_BACKUP ORDER BY data_hora DESC LIMIT 20");
            echo json_encode($stmt->fetchAll()); break;

        case 'stats':
            $stats = [];
            $stats['trucades'] = $db->query("SELECT COUNT(*) FROM TRUCADA")->fetchColumn();
            $stats['usuaris'] = $db->query("SELECT COUNT(*) FROM USUARI")->fetchColumn();
            $stats['videos'] = $db->query("SELECT COUNT(*) FROM VIDEO")->fetchColumn();
            $stats['mesures'] = $db->query("SELECT COUNT(*) FROM MESURA_AMPLADA_BANDA")->fetchColumn();
            $last = $db->query("SELECT resultat, AVG(velocitat_baixada) as avg_dl, AVG(velocitat_pujada) as avg_ul, AVG(latencia) as avg_lat FROM MESURA_AMPLADA_BANDA")->fetch();
            $stats['avg_dl'] = round($last['avg_dl'] ?? 0, 1);
            $stats['avg_ul'] = round($last['avg_ul'] ?? 0, 1);
            $stats['avg_lat'] = round($last['avg_lat'] ?? 0, 1);
            $stats['avisos'] = $db->query("SELECT COUNT(*) FROM AVIS")->fetchColumn();
            echo json_encode($stats); break;

        case 'qualitats':
            echo json_encode($db->query("SELECT * FROM GRUP_QUALITAT")->fetchAll()); break;

        case 'usuaris_select':
            echo json_encode($db->query("SELECT id_usuari, nom_complet FROM USUARI WHERE estat='actiu'")->fetchAll()); break;
    }
    exit;
}

// AJAX: POST accions
if ($action === 'nova_trucada' && isLogged()) {
    header('Content-Type: application/json');
    $db = getDB();
    try {
        $stmt = $db->prepare("INSERT INTO TRUCADA (usuari_originador, usuari_destinatari, data_inici, id_grup_qualitat) VALUES (?,?,NOW(),?)");
        $stmt->execute([$_SESSION['user']['id_usuari'], $_POST['destinatari'], $_POST['qualitat']]);
        $id = $db->lastInsertId();
        // Generar link Jitsi
        $room = 'InnovateTech-' . $id . '-' . uniqid();
        $link = 'https://meet.jit.si/' . $room;
        echo json_encode(['ok' => true, 'link' => $link, 'id' => $id]);
    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'finalitzar_trucada' && isLogged()) {
    header('Content-Type: application/json');
    $db = getDB();
    $stmt = $db->prepare("UPDATE TRUCADA SET data_fi=NOW(), durada_total=TIMESTAMPDIFF(SECOND,data_inici,NOW()), puntuacio=?, comentari=? WHERE id_trucada=?");
    $stmt->execute([$_POST['puntuacio'] ?? null, $_POST['comentari'] ?? null, $_POST['id_trucada']]);
    echo json_encode(['ok' => true]);
    exit;
}

if ($action === 'nova_mesura' && isLogged()) {
    header('Content-Type: application/json');
    $db = getDB();
    $dl  = floatval($_POST['download']);
    $ul  = floatval($_POST['upload']);
    $lat = floatval($_POST['latencia']);
    // Classificació automàtica basada en requisits streaming HLS:
    // Download >= 6 Mbps (1080p H.264), Upload >= 2 Mbps, Latència <= 150 ms
    $acceptable = ($dl >= 6 && $ul >= 2 && $lat <= 150);
    $resultat = $acceptable ? 'acceptable' : 'no acceptable';
    $notes = 'Mesura automàtica des del navegador contra servidor NGINX HLS. ';
    if (!$acceptable) {
        if ($dl < 6)   $notes .= "Baixada insuficient per 1080p ({$dl} Mbps < 6 Mbps). ";
        if ($ul < 2)   $notes .= "Pujada insuficient ({$ul} Mbps < 2 Mbps). ";
        if ($lat > 150) $notes .= "Latència alta ({$lat} ms > 150 ms). ";
    }
    $equip = $_POST['equip'] ?? 'Navegador-' . ($_SERVER['REMOTE_ADDR'] ?? 'desconegut');
    $stmt = $db->prepare("INSERT INTO MESURA_AMPLADA_BANDA
        (data_hora, usuari_equip_mesurat, velocitat_baixada, velocitat_pujada, latencia, resultat, operari_id, notes)
        VALUES (NOW(),?,?,?,?,?,?,?)");
    $stmt->execute([$equip, $dl, $ul, $lat, $resultat, $_SESSION['user']['id_usuari'], $notes]);
    echo json_encode(['ok' => true, 'resultat' => $resultat, 'dl' => $dl, 'ul' => $ul, 'lat' => $lat]);
    exit;
}

// Endpoint per generar fitxer de prova d'upload (chunk de zeros)
if ($action === 'upload_test' && isLogged()) {
    // Rep el chunk pujat pel client i respon immediament (no es guarda)
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'bytes' => intval($_SERVER['CONTENT_LENGTH'] ?? 0)]);
    exit;
}

if ($action === 'nou_video' && isLogged() && hasRole('admin')) {
    header('Content-Type: application/json');
    $db = getDB();
    $stmt = $db->prepare("INSERT INTO VIDEO (titol,descripcio,categoria,durada,data_publicacio,enllac_streaming) VALUES (?,?,?,?,?,?)");
    $stmt->execute([$_POST['titol'], $_POST['descripcio'], $_POST['categoria'], $_POST['durada'] ?? null, $_POST['data_publicacio'] ?? null, $_POST['link']]);
    echo json_encode(['ok' => true]);
    exit;
}

if ($action === 'bloquejar_usuari' && isLogged() && hasRole('admin')) {
    header('Content-Type: application/json');
    $db = getDB();
    $stmt = $db->prepare("UPDATE USUARI SET estat=? WHERE id_usuari=?");
    $stmt->execute([$_POST['estat'], $_POST['id_usuari']]);
    echo json_encode(['ok' => true]);
    exit;
}

// Redirigir si no loggejat
if (!isLogged() && $page !== 'login') {
    redirect('?page=login');
}

?>
<!DOCTYPE html>
<html lang="ca">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>InnovateTech — Portal de Gestió</title>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Mono:wght@300;400;500&display=swap" rel="stylesheet">
<style>
  :root {
    --bg: #0a0a0f;
    --bg2: #111118;
    --bg3: #1a1a24;
    --border: #2a2a3a;
    --accent: #00e5ff;
    --accent2: #7c3aed;
    --accent3: #10b981;
    --warn: #f59e0b;
    --danger: #ef4444;
    --text: #e8e8f0;
    --muted: #6b6b80;
    --card: #14141e;
  }
  * { margin:0; padding:0; box-sizing:border-box; }
  body { font-family:'DM Mono',monospace; background:var(--bg); color:var(--text); min-height:100vh; overflow-x:hidden; }

  /* ---- LOGIN ---- */
  .login-wrap {
    min-height:100vh; display:flex; align-items:center; justify-content:center;
    background: radial-gradient(ellipse at 20% 50%, #0d1a2e 0%, #0a0a0f 60%),
                radial-gradient(ellipse at 80% 20%, #120d1f 0%, transparent 50%);
  }
  .login-box {
    width:420px; background:var(--card); border:1px solid var(--border);
    padding:48px; position:relative; overflow:hidden;
  }
  .login-box::before {
    content:''; position:absolute; top:0; left:0; right:0; height:3px;
    background: linear-gradient(90deg, var(--accent2), var(--accent), var(--accent3));
  }
  .login-logo { font-family:'Syne',sans-serif; font-size:28px; font-weight:800; margin-bottom:8px; letter-spacing:-1px; }
  .login-logo span { color:var(--accent); }
  .login-sub { color:var(--muted); font-size:12px; margin-bottom:36px; }
  .form-group { margin-bottom:20px; }
  .form-group label { display:block; font-size:11px; color:var(--muted); text-transform:uppercase; letter-spacing:1px; margin-bottom:8px; }
  .form-group input, .form-group select, .form-group textarea {
    width:100%; background:var(--bg3); border:1px solid var(--border); color:var(--text);
    padding:12px 16px; font-family:'DM Mono',monospace; font-size:13px; outline:none;
    transition:border-color .2s;
  }
  .form-group input:focus, .form-group select:focus, .form-group textarea:focus { border-color:var(--accent); }
  .btn {
    display:inline-flex; align-items:center; gap:8px; padding:12px 24px;
    font-family:'Syne',sans-serif; font-size:13px; font-weight:700; cursor:pointer;
    border:none; text-transform:uppercase; letter-spacing:1px; transition:all .2s; text-decoration:none;
  }
  .btn-primary { background:var(--accent); color:#000; }
  .btn-primary:hover { background:#00b8cc; }
  .btn-danger { background:var(--danger); color:#fff; }
  .btn-warn { background:var(--warn); color:#000; }
  .btn-ghost { background:transparent; color:var(--text); border:1px solid var(--border); }
  .btn-ghost:hover { border-color:var(--accent); color:var(--accent); }
  .btn-sm { padding:7px 14px; font-size:11px; }
  .btn-full { width:100%; justify-content:center; }
  .error-msg { background:#1a0a0a; border:1px solid var(--danger); color:var(--danger); padding:12px; font-size:12px; margin-bottom:20px; }

  /* ---- LAYOUT ---- */
  .app { display:flex; min-height:100vh; }

  /* SIDEBAR */
  .sidebar {
    width:240px; min-height:100vh; background:var(--bg2); border-right:1px solid var(--border);
    display:flex; flex-direction:column; position:fixed; top:0; left:0; z-index:100;
  }
  .sidebar-logo {
    padding:24px 20px 20px; border-bottom:1px solid var(--border);
    font-family:'Syne',sans-serif; font-size:18px; font-weight:800; letter-spacing:-0.5px;
  }
  .sidebar-logo span { color:var(--accent); }
  .sidebar-user { padding:16px 20px; border-bottom:1px solid var(--border); }
  .sidebar-user .name { font-size:12px; font-weight:500; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
  .sidebar-user .role-badge {
    display:inline-block; margin-top:4px; padding:2px 8px; font-size:10px;
    background:var(--accent2); color:#fff; font-family:'Syne',sans-serif; font-weight:700;
    text-transform:uppercase; letter-spacing:1px;
  }
  .sidebar-nav { flex:1; padding:16px 0; }
  .nav-section { padding:8px 20px 4px; font-size:10px; color:var(--muted); text-transform:uppercase; letter-spacing:1.5px; }
  .nav-item {
    display:flex; align-items:center; gap:10px; padding:10px 20px; cursor:pointer;
    font-size:12px; color:var(--muted); transition:all .15s; border-left:3px solid transparent;
    text-decoration:none;
  }
  .nav-item:hover { color:var(--text); background:var(--bg3); }
  .nav-item.active { color:var(--accent); border-left-color:var(--accent); background:rgba(0,229,255,0.05); }
  .nav-item .icon { font-size:16px; }
  .sidebar-footer { padding:16px 20px; border-top:1px solid var(--border); }

  /* MAIN */
  .main { margin-left:240px; flex:1; min-height:100vh; }
  .topbar {
    padding:20px 32px; border-bottom:1px solid var(--border); background:var(--bg2);
    display:flex; align-items:center; justify-content:space-between; position:sticky; top:0; z-index:50;
  }
  .topbar-title { font-family:'Syne',sans-serif; font-size:20px; font-weight:800; }
  .topbar-title span { color:var(--accent); }
  .content { padding:32px; }

  /* CARDS / GRID */
  .grid { display:grid; gap:20px; }
  .grid-4 { grid-template-columns:repeat(4,1fr); }
  .grid-3 { grid-template-columns:repeat(3,1fr); }
  .grid-2 { grid-template-columns:repeat(2,1fr); }
  @media(max-width:1200px) { .grid-4 { grid-template-columns:repeat(2,1fr); } }
  @media(max-width:900px) { .grid-3,.grid-2 { grid-template-columns:1fr; } }

  .card {
    background:var(--card); border:1px solid var(--border); padding:24px;
    position:relative; overflow:hidden;
  }
  .stat-card { text-align:center; }
  .stat-val { font-family:'Syne',sans-serif; font-size:42px; font-weight:800; color:var(--accent); line-height:1; }
  .stat-label { font-size:11px; color:var(--muted); text-transform:uppercase; letter-spacing:1px; margin-top:8px; }
  .stat-icon { font-size:32px; margin-bottom:12px; }

  .section-header { display:flex; align-items:center; justify-content:space-between; margin-bottom:20px; }
  .section-title { font-family:'Syne',sans-serif; font-size:16px; font-weight:700; }
  .section-title::before { content:'//'; color:var(--accent); margin-right:8px; }

  /* TABLE */
  .table-wrap { overflow-x:auto; }
  table { width:100%; border-collapse:collapse; font-size:12px; }
  th { text-align:left; padding:10px 14px; color:var(--muted); text-transform:uppercase; letter-spacing:1px; font-size:10px; border-bottom:1px solid var(--border); font-weight:400; }
  td { padding:12px 14px; border-bottom:1px solid rgba(42,42,58,0.5); }
  tr:hover td { background:rgba(255,255,255,0.02); }
  .badge { display:inline-block; padding:3px 10px; font-size:10px; font-weight:700; font-family:'Syne',sans-serif; text-transform:uppercase; letter-spacing:0.5px; }
  .badge-green { background:rgba(16,185,129,0.15); color:var(--accent3); border:1px solid rgba(16,185,129,0.3); }
  .badge-red { background:rgba(239,68,68,0.15); color:var(--danger); border:1px solid rgba(239,68,68,0.3); }
  .badge-blue { background:rgba(0,229,255,0.1); color:var(--accent); border:1px solid rgba(0,229,255,0.2); }
  .badge-purple { background:rgba(124,58,237,0.15); color:#a78bfa; border:1px solid rgba(124,58,237,0.3); }
  .badge-warn { background:rgba(245,158,11,0.15); color:var(--warn); border:1px solid rgba(245,158,11,0.3); }

  /* MODALS */
  .modal-overlay {
    display:none; position:fixed; inset:0; background:rgba(0,0,0,0.8); z-index:1000;
    align-items:center; justify-content:center;
  }
  .modal-overlay.open { display:flex; }
  .modal {
    background:var(--card); border:1px solid var(--border); padding:32px; width:500px; max-width:95vw;
    position:relative; max-height:90vh; overflow-y:auto;
  }
  .modal::before { content:''; position:absolute; top:0; left:0; right:0; height:2px; background:linear-gradient(90deg,var(--accent2),var(--accent)); }
  .modal-title { font-family:'Syne',sans-serif; font-size:18px; font-weight:800; margin-bottom:24px; }
  .modal-close { position:absolute; top:16px; right:16px; background:none; border:none; color:var(--muted); cursor:pointer; font-size:20px; }
  .modal-close:hover { color:var(--text); }

  /* VIDEO GRID */
  .video-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(280px,1fr)); gap:20px; }
  .video-card { background:var(--card); border:1px solid var(--border); overflow:hidden; cursor:pointer; transition:all .2s; }
  .video-card:hover { border-color:var(--accent); transform:translateY(-2px); }
  .video-thumb { height:160px; background:linear-gradient(135deg,var(--bg3),var(--bg2)); display:flex; align-items:center; justify-content:center; font-size:48px; position:relative; }
  .video-thumb .play-btn { width:56px; height:56px; background:var(--accent); display:flex; align-items:center; justify-content:center; font-size:20px; color:#000; }
  .video-info { padding:16px; }
  .video-title { font-family:'Syne',sans-serif; font-size:14px; font-weight:700; margin-bottom:6px; }
  .video-meta { font-size:11px; color:var(--muted); }

  /* BANDWIDTH METER */
  .bw-meter { position:relative; height:8px; background:var(--bg3); border:1px solid var(--border); overflow:hidden; margin:8px 0; }
  .bw-fill { height:100%; transition:width .8s ease; }
  .bw-fill.dl { background:linear-gradient(90deg,var(--accent2),var(--accent)); }
  .bw-fill.ul { background:linear-gradient(90deg,var(--accent3),#06b6d4); }
  .bw-fill.lat { background:linear-gradient(90deg,var(--warn),var(--danger)); }
  .bw-label { display:flex; justify-content:space-between; font-size:11px; color:var(--muted); }

  /* JITSI FRAME */
  .jitsi-frame { width:100%; height:500px; border:none; background:#000; }

  /* TOAST */
  .toast {
    position:fixed; bottom:24px; right:24px; background:var(--accent3); color:#000;
    padding:14px 20px; font-family:'Syne',sans-serif; font-size:13px; font-weight:700;
    z-index:9999; transform:translateY(100px); opacity:0; transition:all .3s;
    border-left:4px solid #065f46;
  }
  .toast.show { transform:translateY(0); opacity:1; }
  .toast.error { background:var(--danger); color:#fff; border-left-color:#7f1d1d; }

  /* LOADING */
  .loading { display:flex; align-items:center; gap:10px; color:var(--muted); font-size:12px; padding:20px; }
  .spinner { width:16px; height:16px; border:2px solid var(--border); border-top-color:var(--accent); border-radius:50%; animation:spin .8s linear infinite; }
  @keyframes spin { to { transform:rotate(360deg); } }

  /* STARS */
  .stars { display:flex; gap:4px; }
  .star { font-size:20px; cursor:pointer; color:var(--border); transition:color .15s; }
  .star.active { color:var(--warn); }
  .star:hover { color:var(--warn); }

  /* SEARCH */
  .search-bar { display:flex; gap:10px; margin-bottom:20px; }
  .search-bar input { flex:1; }

  /* TABS */
  .tabs { display:flex; border-bottom:1px solid var(--border); margin-bottom:24px; }
  .tab { padding:12px 24px; font-size:12px; cursor:pointer; color:var(--muted); border-bottom:2px solid transparent; transition:all .15s; font-family:'Syne',sans-serif; font-weight:600; text-transform:uppercase; letter-spacing:1px; }
  .tab:hover { color:var(--text); }
  .tab.active { color:var(--accent); border-bottom-color:var(--accent); }
  .tab-content { display:none; }
  .tab-content.active { display:block; }

  /* PULSE DOT */
  .pulse { display:inline-block; width:8px; height:8px; border-radius:50%; background:var(--accent3); animation:pulse 2s infinite; }
  @keyframes pulse { 0%,100%{opacity:1;transform:scale(1);} 50%{opacity:.5;transform:scale(1.3);} }

  /* SCROLLBAR */
  ::-webkit-scrollbar { width:6px; height:6px; }
  ::-webkit-scrollbar-track { background:var(--bg); }
  ::-webkit-scrollbar-thumb { background:var(--border); }
  ::-webkit-scrollbar-thumb:hover { background:var(--muted); }

  select option { background:var(--bg3); }
  .hidden { display:none; }

  .alert { padding:14px 18px; font-size:12px; margin-bottom:16px; border-left:3px solid; }
  .alert-info { background:rgba(0,229,255,0.05); border-color:var(--accent); color:var(--accent); }
  .alert-warn { background:rgba(245,158,11,0.08); border-color:var(--warn); color:var(--warn); }
</style>
</head>
<body>

<?php
$loginError = $_SESSION['login_error'] ?? '';
unset($_SESSION['login_error']);
?>

<!-- ===================== LOGIN ===================== -->
<?php if (!isLogged()): ?>
<div class="login-wrap">
  <div class="login-box">
    <div class="login-logo">Innovate<span>Tech</span></div>
    <div class="login-sub">// Portal de Gestió Interna — Accés Restringit</div>
    <?php if ($loginError): ?>
      <div class="error-msg">⚠ <?= h($loginError) ?></div>
    <?php endif; ?>
    <form method="POST" action="?action=login">
      <div class="form-group">
        <label>Correu electrònic</label>
        <input type="email" name="email" placeholder="usuari@innovatech.com" required autofocus>
      </div>
      <div class="form-group">
        <label>Contrasenya</label>
        <input type="password" name="password" placeholder="••••••••" required>
      </div>
      <button type="submit" class="btn btn-primary btn-full">Iniciar sessió →</button>
    </form>
    <div style="margin-top:24px;font-size:11px;color:var(--muted);text-align:center;">
      InnovateTech · Sistema de Gestió Intern · <?= date('Y') ?>
    </div>
  </div>
</div>

<?php else: // ===================== APP ===================== ?>

<?php $user = getUser(); $roles = $user['roles']; $primaryRole = $roles[0] ?? 'treballador'; ?>

<!-- SIDEBAR -->
<nav class="sidebar">
  <div class="sidebar-logo">Innovate<span>Tech</span></div>
  <div class="sidebar-user">
    <div class="name"><?= h($user['nom_complet']) ?></div>
    <span class="role-badge"><?= h($primaryRole) ?></span>
  </div>
  <div class="sidebar-nav">
    <div class="nav-section">General</div>
    <a class="nav-item" href="#" onclick="showPage('dashboard')"><span class="icon">◈</span> Dashboard</a>
    <a class="nav-item" href="#" onclick="showPage('trucades')"><span class="icon">◎</span> Trucades</a>
    <a class="nav-item" href="#" onclick="showPage('videos')"><span class="icon">▷</span> Vídeos</a>

    <?php if (hasRole('admin') || hasRole('administracio') || hasRole('vendes')): ?>
    <div class="nav-section">Gestió</div>
    <?php endif; ?>
    <?php if (hasRole('admin') || hasRole('administracio')): ?>
    <a class="nav-item" href="#" onclick="showPage('usuaris')"><span class="icon">⊕</span> Usuaris</a>
    <?php endif; ?>
    <?php if (hasRole('admin')): ?>
    <a class="nav-item" href="#" onclick="showPage('amplada')"><span class="icon">≋</span> Amplada Banda</a>
    <a class="nav-item" href="#" onclick="showPage('auditoria')"><span class="icon">⚑</span> Auditoria</a>
    <?php endif; ?>
  </div>
  <div class="sidebar-footer">
    <div style="font-size:10px;color:var(--muted);margin-bottom:10px;">
      <span class="pulse"></span> Sessió activa
    </div>
    <form method="POST" action="?action=logout">
      <button type="submit" class="btn btn-ghost btn-sm btn-full">Tancar sessió</button>
    </form>
  </div>
</nav>

<!-- MAIN -->
<div class="main">
  <div class="topbar">
    <div class="topbar-title" id="topbar-title">◈ <span>Dashboard</span></div>
    <div style="font-size:11px;color:var(--muted);"><?= date('l, d M Y') ?></div>
  </div>
  <div class="content">

    <!-- ===== DASHBOARD ===== -->
    <div id="page-dashboard" class="page-section">
      <div class="grid grid-4" style="margin-bottom:24px;">
        <div class="card stat-card">
          <div class="stat-icon">◎</div>
          <div class="stat-val" id="stat-trucades">—</div>
          <div class="stat-label">Trucades totals</div>
        </div>
        <div class="card stat-card">
          <div class="stat-icon">⊕</div>
          <div class="stat-val" id="stat-usuaris">—</div>
          <div class="stat-label">Usuaris registrats</div>
        </div>
        <div class="card stat-card">
          <div class="stat-icon">▷</div>
          <div class="stat-val" id="stat-videos">—</div>
          <div class="stat-label">Vídeos disponibles</div>
        </div>
        <div class="card stat-card">
          <div class="stat-icon">⚑</div>
          <div class="stat-val" id="stat-avisos">—</div>
          <div class="stat-label">Avisos d'auditoria</div>
        </div>
      </div>

      <div class="grid grid-2">
        <div class="card">
          <div class="section-title" style="margin-bottom:20px;">Estat de la Xarxa</div>
          <div class="bw-label"><span>Baixada (Mbps)</span><span id="dash-dl">—</span></div>
          <div class="bw-meter"><div class="bw-fill dl" id="bar-dl" style="width:0%"></div></div>
          <div class="bw-label" style="margin-top:12px;"><span>Pujada (Mbps)</span><span id="dash-ul">—</span></div>
          <div class="bw-meter"><div class="bw-fill ul" id="bar-ul" style="width:0%"></div></div>
          <div class="bw-label" style="margin-top:12px;"><span>Latència (ms)</span><span id="dash-lat">—</span></div>
          <div class="bw-meter"><div class="bw-fill lat" id="bar-lat" style="width:0%"></div></div>
          <div style="margin-top:16px;font-size:11px;color:var(--muted);">Mitjana de totes les mesures registrades</div>
        </div>
        <div class="card">
          <div class="section-title" style="margin-bottom:16px;">Accions Ràpides</div>
          <div style="display:flex;flex-direction:column;gap:10px;">
            <button class="btn btn-primary" onclick="openModal('modal-nova-trucada');loadUsuarisSelect();loadQualitats()">
              ◎ Nova Videotrucada
            </button>
            <button class="btn btn-ghost" onclick="showPage('videos')">
              ▷ Catàleg de Vídeos
            </button>
            <?php if (hasRole('admin')): ?>
            <button class="btn btn-ghost" onclick="openModal('modal-nova-mesura')">
              ≋ Registrar Mesura de Xarxa
            </button>
            <button class="btn btn-ghost" onclick="showPage('auditoria')">
              ⚑ Veure Log d'Auditoria
            </button>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>

    <!-- ===== TRUCADES ===== -->
    <div id="page-trucades" class="page-section hidden">
      <div class="section-header">
        <div class="section-title">Gestió de Trucades</div>
        <button class="btn btn-primary btn-sm" onclick="openModal('modal-nova-trucada');loadUsuarisSelect();loadQualitats()">+ Nova Trucada</button>
      </div>
      <div class="card">
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>#</th><th>Originador</th><th>Destinatari</th>
                <th>Inici</th><th>Durada</th><th>Qualitat</th><th>Puntuació</th><th>Accions</th>
              </tr>
            </thead>
            <tbody id="trucades-tbody">
              <tr><td colspan="8"><div class="loading"><div class="spinner"></div> Carregant...</div></td></tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- ===== VÍDEOS ===== -->
    <div id="page-videos" class="page-section hidden">
      <div class="section-header">
        <div class="section-title">Catàleg de Vídeos</div>
        <?php if (hasRole('admin')): ?>
        <button class="btn btn-primary btn-sm" onclick="openModal('modal-nou-video')">+ Afegir Vídeo</button>
        <?php endif; ?>
      </div>
      <div class="search-bar">
        <input type="text" id="video-search" placeholder="Cercar per títol, categoria..." oninput="loadVideos()">
        <button class="btn btn-ghost btn-sm" onclick="loadVideos()">Cercar</button>
      </div>
      <div class="video-grid" id="videos-grid">
        <div class="loading"><div class="spinner"></div> Carregant...</div>
      </div>
    </div>

    <!-- ===== USUARIS ===== -->
    <?php if (hasRole('admin') || hasRole('administracio')): ?>
    <div id="page-usuaris" class="page-section hidden">
      <div class="section-header">
        <div class="section-title">Gestió d'Usuaris</div>
      </div>
      <div class="card">
        <div class="table-wrap">
          <table>
            <thead>
              <tr><th>ID</th><th>Nom</th><th>Email</th><th>Departament</th><th>Rols</th><th>Tipus</th><th>Estat</th><th>Accions</th></tr>
            </thead>
            <tbody id="usuaris-tbody">
              <tr><td colspan="8"><div class="loading"><div class="spinner"></div> Carregant...</div></td></tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <!-- ===== AMPLADA DE BANDA ===== -->
    <?php if (hasRole('admin')): ?>
    <div id="page-amplada" class="page-section hidden">
      <div class="section-header">
        <div class="section-title">Mesures d'Amplada de Banda</div>
      </div>

      <!-- SPEEDTEST WIDGET -->
      <div class="card" style="margin-bottom:24px;">
        <div style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:24px;">
          <div style="flex:1;min-width:260px;">
            <div class="section-title" style="margin-bottom:16px;">Test de Connexió al Servei de Vídeo NGINX</div>
            <div class="form-group">
              <label>URL Base del Servidor NGINX HLS</label>
              <input type="text" id="nginx-url" value="http://localhost" placeholder="http://ip-servidor-nginx">
            </div>
            <div class="form-group">
              <label>Ruta del segment / playlist HLS de prova</label>
              <input type="text" id="hls-path" value="/hls/test/index.m3u8" placeholder="/hls/stream/index.m3u8">
            </div>
            <div class="alert alert-info" style="margin-bottom:16px;font-size:11px;">
              El test descarrega segments HLS reals del NGINX per mesurar la velocitat real del servei de video.<br>
              <strong>Acceptable:</strong> Baixada &ge; 6 Mbps &middot; Pujada &ge; 2 Mbps &middot; Latencia &le; 150 ms
            </div>
            <button class="btn btn-primary" id="btn-speedtest" onclick="iniciarSpeedtest()">
              &equiv; Iniciar Mesura
            </button>
          </div>

          <div style="flex:1;min-width:280px;">
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;margin-bottom:20px;">
              <div style="background:var(--bg3);border:1px solid var(--border);padding:20px;text-align:center;">
                <div style="font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:1px;margin-bottom:8px;">Baixada</div>
                <div id="res-dl" style="font-family:'Syne',sans-serif;font-size:28px;font-weight:800;color:var(--accent);">--</div>
                <div style="font-size:10px;color:var(--muted);">Mbps</div>
              </div>
              <div style="background:var(--bg3);border:1px solid var(--border);padding:20px;text-align:center;">
                <div style="font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:1px;margin-bottom:8px;">Pujada</div>
                <div id="res-ul" style="font-family:'Syne',sans-serif;font-size:28px;font-weight:800;color:var(--accent3);">--</div>
                <div style="font-size:10px;color:var(--muted);">Mbps</div>
              </div>
              <div style="background:var(--bg3);border:1px solid var(--border);padding:20px;text-align:center;">
                <div style="font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:1px;margin-bottom:8px;">Latencia</div>
                <div id="res-lat" style="font-family:'Syne',sans-serif;font-size:28px;font-weight:800;color:var(--warn);">--</div>
                <div style="font-size:10px;color:var(--muted);">ms</div>
              </div>
            </div>
            <div style="margin-bottom:8px;">
              <div class="bw-label"><span>Download</span><span id="bar-dl-label">--</span></div>
              <div class="bw-meter"><div class="bw-fill dl" id="st-bar-dl" style="width:0%"></div></div>
            </div>
            <div style="margin-bottom:8px;">
              <div class="bw-label"><span>Upload</span><span id="bar-ul-label">--</span></div>
              <div class="bw-meter"><div class="bw-fill ul" id="st-bar-ul" style="width:0%"></div></div>
            </div>
            <div>
              <div class="bw-label"><span>Latencia</span><span id="bar-lat-label">--</span></div>
              <div class="bw-meter"><div class="bw-fill lat" id="st-bar-lat" style="width:0%"></div></div>
            </div>
            <div id="st-status" style="margin-top:16px;font-size:12px;color:var(--muted);">Prem "Iniciar Mesura" per comenar.</div>
            <div id="st-result-badge" style="margin-top:12px;display:none;"></div>
            <div style="margin-top:16px;display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;">
              <div style="padding:10px;background:var(--bg3);border:1px solid var(--border);font-size:10px;">
                <div style="color:var(--accent);font-weight:700;margin-bottom:4px;">Audio HLS</div>
                <div style="color:var(--muted);">&ge; 0.5 Mbps</div>
              </div>
              <div style="padding:10px;background:var(--bg3);border:1px solid var(--border);font-size:10px;">
                <div style="color:var(--accent);font-weight:700;margin-bottom:4px;">Video 1080p</div>
                <div style="color:var(--muted);">&ge; 6 Mbps</div>
              </div>
              <div style="padding:10px;background:var(--bg3);border:1px solid var(--border);font-size:10px;">
                <div style="color:var(--accent);font-weight:700;margin-bottom:4px;">Videoconf.</div>
                <div style="color:var(--muted);">&le; 150 ms</div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="section-header">
        <div class="section-title">Historial de Mesures</div>
      </div>
      <div class="card">
        <div class="table-wrap">
          <table>
            <thead>
              <tr><th>#</th><th>Data/Hora</th><th>Equip</th><th>Baixada</th><th>Pujada</th><th>Latencia</th><th>Resultat</th><th>Operari</th><th>Notes</th></tr>
            </thead>
            <tbody id="mesures-tbody">
              <tr><td colspan="9"><div class="loading"><div class="spinner"></div> Carregant...</div></td></tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <!-- ===== AUDITORIA ===== -->
    <?php if (hasRole('admin')): ?>
    <div id="page-auditoria" class="page-section hidden">
      <div class="tabs">
        <div class="tab active" onclick="switchTab('tab-avisos',this)">Log d'Avisos</div>
        <div class="tab" onclick="switchTab('tab-backups',this)">Control Backups</div>
      </div>

      <div id="tab-avisos" class="tab-content active">
        <div class="card">
          <div class="table-wrap">
            <table>
              <thead><tr><th>#</th><th>Usuari</th><th>Taula afectada</th><th>Operació</th><th>Data/Hora</th><th>Detall</th></tr></thead>
              <tbody id="avisos-tbody">
                <tr><td colspan="6"><div class="loading"><div class="spinner"></div> Carregant...</div></td></tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <div id="tab-backups" class="tab-content">
        <div class="card">
          <div class="table-wrap">
            <table>
              <thead><tr><th>#</th><th>Data/Hora</th><th>Taules incloses</th><th>Resultat</th></tr></thead>
              <tbody id="backups-tbody">
                <tr><td colspan="4"><div class="loading"><div class="spinner"></div> Carregant...</div></td></tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
    <?php endif; ?>

  </div><!-- /content -->
</div><!-- /main -->

<!-- ===== MODALS ===== -->

<!-- Modal Nova Trucada -->
<div class="modal-overlay" id="modal-nova-trucada">
  <div class="modal">
    <button class="modal-close" onclick="closeModal('modal-nova-trucada')">✕</button>
    <div class="modal-title">Nova Videotrucada</div>
    <div class="form-group">
      <label>Destinatari</label>
      <select id="nova-trucada-dest"></select>
    </div>
    <div class="form-group">
      <label>Qualitat de servei</label>
      <select id="nova-trucada-qualitat"></select>
    </div>
    <button class="btn btn-primary btn-full" onclick="iniciaTrucada()">▷ Iniciar Trucada</button>
    <div id="trucada-link-wrap" class="hidden" style="margin-top:20px;">
      <div class="alert alert-info" id="trucada-link-msg"></div>
      <a id="trucada-link-btn" href="#" target="_blank" class="btn btn-primary btn-full">Obrir Jitsi Meet →</a>
      <div style="margin-top:16px;">
        <div class="form-group">
          <label>Puntuació de la trucada</label>
          <div class="stars" id="rating-stars">
            <span class="star" data-v="1">★</span>
            <span class="star" data-v="2">★</span>
            <span class="star" data-v="3">★</span>
            <span class="star" data-v="4">★</span>
            <span class="star" data-v="5">★</span>
          </div>
        </div>
        <div class="form-group">
          <label>Comentari (opcional)</label>
          <textarea id="trucada-comentari" rows="2" placeholder="Com ha anat la trucada?"></textarea>
        </div>
        <button class="btn btn-ghost btn-sm" onclick="finalitzaTrucada()">✓ Finalitzar i valorar</button>
      </div>
    </div>
  </div>
</div>

<!-- Modal Nou Vídeo -->
<?php if (hasRole('admin')): ?>
<div class="modal-overlay" id="modal-nou-video">
  <div class="modal">
    <button class="modal-close" onclick="closeModal('modal-nou-video')">✕</button>
    <div class="modal-title">Afegir Vídeo</div>
    <div class="form-group"><label>Títol</label><input type="text" id="v-titol" placeholder="Títol del vídeo"></div>
    <div class="form-group"><label>Descripció</label><textarea id="v-desc" rows="2"></textarea></div>
    <div class="form-group"><label>Categoria</label><input type="text" id="v-cat" placeholder="Corporatiu, Tutorial, Formació..."></div>
    <div class="form-group"><label>Durada (segons)</label><input type="number" id="v-durada" placeholder="120"></div>
    <div class="form-group"><label>Data de publicació</label><input type="date" id="v-data"></div>
    <div class="form-group"><label>Enllaç Streaming</label><input type="url" id="v-link" placeholder="https://..."></div>
    <button class="btn btn-primary btn-full" onclick="nouVideo()">+ Afegir Vídeo</button>
  </div>
</div>
<?php endif; ?>

<!-- Modal Nova Mesura -->
<?php if (hasRole('admin')): ?>
<div class="modal-overlay" id="modal-nova-mesura">
  <div class="modal">
    <button class="modal-close" onclick="closeModal('modal-nova-mesura')">✕</button>
    <div class="modal-title">Registrar Mesura d'Amplada de Banda</div>
    <div class="form-group"><label>Equip / Servidor mesurat</label><input type="text" id="m-equip" placeholder="ex: Servidor Principal, PC-001..."></div>
    <div class="form-group"><label>Velocitat Baixada (Mbps)</label><input type="number" id="m-dl" step="0.1" placeholder="95.5"></div>
    <div class="form-group"><label>Velocitat Pujada (Mbps)</label><input type="number" id="m-ul" step="0.1" placeholder="45.2"></div>
    <div class="form-group"><label>Latència (ms)</label><input type="number" id="m-lat" step="0.1" placeholder="12.3"></div>
    <div class="form-group"><label>Notes</label><textarea id="m-notes" rows="2" placeholder="Observacions..."></textarea></div>
    <button class="btn btn-primary btn-full" onclick="novaMesura()">≋ Registrar Mesura</button>
    <div id="mesura-result" class="hidden" style="margin-top:16px;"></div>
  </div>
</div>
<?php endif; ?>

<!-- Modal Reproductor Vídeo -->
<div class="modal-overlay" id="modal-player">
  <div class="modal" style="width:700px;max-width:95vw;">
    <button class="modal-close" onclick="closeModal('modal-player')">✕</button>
    <div class="modal-title" id="player-title">Reproductor</div>
    <div style="background:#000;padding:16px;margin-bottom:16px;text-align:center;">
      <div style="font-size:11px;color:var(--muted);margin-bottom:12px;">Enllaç de streaming:</div>
      <a id="player-link" href="#" target="_blank" class="btn btn-primary">▷ Obrir en nova finestra</a>
    </div>
    <div style="font-size:11px;color:var(--muted);" id="player-desc"></div>
  </div>
</div>

<!-- Modal Finalitzar Trucada (des de taula) -->
<div class="modal-overlay" id="modal-finalitzar">
  <div class="modal">
    <button class="modal-close" onclick="closeModal('modal-finalitzar')">✕</button>
    <div class="modal-title">Finalitzar Trucada</div>
    <input type="hidden" id="fin-id">
    <div class="form-group">
      <label>Puntuació</label>
      <div class="stars" id="fin-stars">
        <span class="star" data-v="1">★</span><span class="star" data-v="2">★</span>
        <span class="star" data-v="3">★</span><span class="star" data-v="4">★</span>
        <span class="star" data-v="5">★</span>
      </div>
    </div>
    <div class="form-group"><label>Comentari</label><textarea id="fin-comentari" rows="2"></textarea></div>
    <button class="btn btn-primary btn-full" onclick="submitFinalitzar()">✓ Confirmar</button>
  </div>
</div>

<!-- TOAST -->
<div class="toast" id="toast"></div>

<?php endif; // end isLogged ?>

<script>
// =====================================================
// JAVASCRIPT PRINCIPAL
// =====================================================

const USER_ID = <?= json_encode($user['id_usuari'] ?? null) ?>;
const USER_ROLES = <?= json_encode($roles ?? []) ?>;
let currentTrucadaId = null;
let ratingVal = 0;

// --- PAGE NAVIGATION ---
function showPage(name) {
  document.querySelectorAll('.page-section').forEach(el => el.classList.add('hidden'));
  const el = document.getElementById('page-' + name);
  if (el) el.classList.remove('hidden');

  // Update sidebar active
  document.querySelectorAll('.nav-item').forEach(n => n.classList.remove('active'));
  document.querySelectorAll('.nav-item').forEach(n => {
    if (n.getAttribute('onclick') && n.getAttribute('onclick').includes("'" + name + "'")) n.classList.add('active');
  });

  // Update topbar title
  const titles = {
    dashboard: '◈ Dashboard', trucades: '◎ Trucades', videos: '▷ Vídeos',
    usuaris: '⊕ Usuaris', amplada: '≋ Amplada de Banda', auditoria: '⚑ Auditoria'
  };
  document.getElementById('topbar-title').innerHTML = titles[name] || name;

  // Load data for page
  if (name === 'dashboard') loadDashboard();
  if (name === 'trucades') loadTrucades();
  if (name === 'videos') loadVideos();
  if (name === 'usuaris') loadUsuaris();
  if (name === 'amplada') loadMesures();
  if (name === 'auditoria') { loadAvisos(); loadBackups(); }
}

// --- TABS ---
function switchTab(tabId, el) {
  document.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
  document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
  document.getElementById(tabId).classList.add('active');
  el.classList.add('active');
}

// --- MODALS ---
function openModal(id) { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }
document.querySelectorAll('.modal-overlay').forEach(m => {
  m.addEventListener('click', e => { if (e.target === m) m.classList.remove('open'); });
});

// --- TOAST ---
function showToast(msg, isError = false) {
  const t = document.getElementById('toast');
  t.textContent = msg;
  t.className = 'toast' + (isError ? ' error' : '') + ' show';
  setTimeout(() => t.classList.remove('show'), 3000);
}

// --- FETCH HELPER ---
async function api(type, params = '') {
  const r = await fetch('?action=get_data&type=' + type + params);
  return r.json();
}

// --- DASHBOARD ---
async function loadDashboard() {
  const stats = await api('stats');
  document.getElementById('stat-trucades').textContent = stats.trucades;
  document.getElementById('stat-usuaris').textContent = stats.usuaris;
  document.getElementById('stat-videos').textContent = stats.videos;
  document.getElementById('stat-avisos').textContent = stats.avisos;

  const maxDl = 200, maxUl = 100, maxLat = 300;
  document.getElementById('dash-dl').textContent = stats.avg_dl + ' Mbps';
  document.getElementById('dash-ul').textContent = stats.avg_ul + ' Mbps';
  document.getElementById('dash-lat').textContent = stats.avg_lat + ' ms';
  document.getElementById('bar-dl').style.width = Math.min(100, (stats.avg_dl / maxDl) * 100) + '%';
  document.getElementById('bar-ul').style.width = Math.min(100, (stats.avg_ul / maxUl) * 100) + '%';
  document.getElementById('bar-lat').style.width = Math.min(100, (stats.avg_lat / maxLat) * 100) + '%';
}

// --- TRUCADES ---
async function loadTrucades() {
  const data = await api('trucades');
  const tbody = document.getElementById('trucades-tbody');
  if (!data.length) { tbody.innerHTML = '<tr><td colspan="8" style="color:var(--muted);padding:20px;">Cap trucada registrada</td></tr>'; return; }
  tbody.innerHTML = data.map(t => {
    const durada = t.durada_total ? Math.floor(t.durada_total/60) + 'min ' + (t.durada_total%60) + 's' : '<span style="color:var(--warn)">En curs</span>';
    const stars = t.puntuacio ? '★'.repeat(t.puntuacio) + '☆'.repeat(5-t.puntuacio) : '—';
    const starColor = t.puntuacio ? 'color:var(--warn)' : 'color:var(--muted)';
    const actions = !t.data_fi ? `<button class="btn btn-warn btn-sm" onclick="obreFinalitzar(${t.id_trucada})">Finalitzar</button>` : '—';
    return `<tr>
      <td>#${t.id_trucada}</td>
      <td>${esc(t.originador_nom)}</td>
      <td>${esc(t.destinatari_nom)}</td>
      <td style="font-size:11px;">${t.data_inici?.replace('T',' ')?.substring(0,16) || '—'}</td>
      <td>${durada}</td>
      <td><span class="badge badge-blue">${esc(t.qualitat)}</span></td>
      <td style="${starColor}">${stars}</td>
      <td>${actions}</td>
    </tr>`;
  }).join('');
}

// --- VÍDEOS ---
async function loadVideos() {
  const q = document.getElementById('video-search')?.value || '';
  const data = await api('videos', '&q=' + encodeURIComponent(q));
  const grid = document.getElementById('videos-grid');
  if (!data.length) { grid.innerHTML = '<div style="color:var(--muted);padding:20px;">Cap vídeo trobat</div>'; return; }
  grid.innerHTML = data.map(v => `
    <div class="video-card" onclick="obreVideo(${JSON.stringify(v).replace(/"/g,'&quot;')})">
      <div class="video-thumb">
        <div class="play-btn">▷</div>
      </div>
      <div class="video-info">
        <div class="video-title">${esc(v.titol)}</div>
        <div class="video-meta">
          ${v.categoria ? '<span class="badge badge-purple">' + esc(v.categoria) + '</span> ' : ''}
          ${v.durada ? Math.floor(v.durada/60) + 'min' : ''} 
          ${v.data_publicacio ? '· ' + v.data_publicacio : ''}
        </div>
      </div>
    </div>
  `).join('');
}

function obreVideo(v) {
  document.getElementById('player-title').textContent = v.titol;
  document.getElementById('player-link').href = v.enllac_streaming;
  document.getElementById('player-desc').textContent = v.descripcio || '';
  openModal('modal-player');
}

// --- USUARIS ---
async function loadUsuaris() {
  const data = await api('usuaris');
  const tbody = document.getElementById('usuaris-tbody');
  if (!data.length) { tbody.innerHTML = '<tr><td colspan="8" style="color:var(--muted);padding:20px;">Sense dades</td></tr>'; return; }
  tbody.innerHTML = data.map(u => {
    const roles = u.roles ? u.roles.split(',').map(r => `<span class="badge badge-purple">${esc(r)}</span>`).join(' ') : '—';
    const estatBadge = u.estat === 'actiu' ? '<span class="badge badge-green">Actiu</span>' : '<span class="badge badge-red">Bloquejat</span>';
    const tipusBadge = u.tipus === 'intern' ? '<span class="badge badge-blue">Intern</span>' : '<span class="badge badge-warn">Extern</span>';
    const btnEstat = u.estat === 'actiu'
      ? `<button class="btn btn-danger btn-sm" onclick="canviaEstat(${u.id_usuari},'bloquejat')">Bloquejar</button>`
      : `<button class="btn btn-ghost btn-sm" onclick="canviaEstat(${u.id_usuari},'actiu')">Activar</button>`;
    return `<tr>
      <td>#${u.id_usuari}</td>
      <td><strong>${esc(u.nom_complet)}</strong></td>
      <td style="font-size:11px;">${esc(u.email)}</td>
      <td>${esc(u.departament || '—')}</td>
      <td>${roles}</td>
      <td>${tipusBadge}</td>
      <td>${estatBadge}</td>
      <td>${btnEstat}</td>
    </tr>`;
  }).join('');
}

async function canviaEstat(id, estat) {
  const fd = new FormData();
  fd.append('action', 'bloquejar_usuari'); fd.append('id_usuari', id); fd.append('estat', estat);
  await fetch('', { method:'POST', body:fd });
  showToast('Estat actualitzat correctament');
  loadUsuaris();
}

// --- MESURES ---
async function loadMesures() {
  const data = await api('mesures');
  const tbody = document.getElementById('mesures-tbody');
  if (!data.length) { tbody.innerHTML = '<tr><td colspan="9" style="color:var(--muted);padding:20px;">Cap mesura registrada</td></tr>'; return; }
  tbody.innerHTML = data.map(m => {
    const badge = m.resultat === 'acceptable'
      ? '<span class="badge badge-green">✓ Acceptable</span>'
      : '<span class="badge badge-red">✗ No acceptable</span>';
    return `<tr>
      <td>#${m.id_mesura}</td>
      <td style="font-size:11px;">${m.data_hora?.substring(0,16)||'—'}</td>
      <td>${esc(m.usuari_equip_mesurat)}</td>
      <td><strong>${m.velocitat_baixada}</strong> Mbps</td>
      <td><strong>${m.velocitat_pujada}</strong> Mbps</td>
      <td>${m.latencia} ms</td>
      <td>${badge}</td>
      <td style="font-size:11px;">${esc(m.operari_nom)}</td>
      <td style="font-size:11px;color:var(--muted);">${esc(m.notes||'')}</td>
    </tr>`;
  }).join('');
}

async function novaMesura() {
  const fd = new FormData();
  fd.append('action','nova_mesura');
  fd.append('equip', document.getElementById('m-equip').value);
  fd.append('download', document.getElementById('m-dl').value);
  fd.append('upload', document.getElementById('m-ul').value);
  fd.append('latencia', document.getElementById('m-lat').value);
  fd.append('notes', document.getElementById('m-notes').value);
  const r = await fetch('', {method:'POST', body:fd});
  const d = await r.json();
  const wrap = document.getElementById('mesura-result');
  wrap.classList.remove('hidden');
  wrap.className = d.resultat === 'acceptable' ? 'alert alert-info' : 'alert alert-warn';
  wrap.textContent = 'Resultat: ' + d.resultat.toUpperCase() + (d.resultat === 'acceptable' ? ' ✓' : ' ✗');
  showToast('Mesura registrada: ' + d.resultat);
  loadMesures();
}

// --- AUDITORIA ---
async function loadAvisos() {
  const data = await api('avisos');
  const tbody = document.getElementById('avisos-tbody');
  if (!data.length) { tbody.innerHTML = '<tr><td colspan="6" style="color:var(--muted);padding:20px;">Cap avís registrat</td></tr>'; return; }
  tbody.innerHTML = data.map(a => `<tr>
    <td>#${a.id_avis}</td>
    <td>${esc(a.nom_complet)}</td>
    <td><span class="badge badge-warn">${esc(a.taula_afectada)}</span></td>
    <td><span class="badge badge-red">${esc(a.operacio_intentada)}</span></td>
    <td style="font-size:11px;">${a.data_hora?.substring(0,16)||'—'}</td>
    <td style="font-size:11px;color:var(--muted);">${esc(a.detall||'')}</td>
  </tr>`).join('');
}

async function loadBackups() {
  const data = await api('backups');
  const tbody = document.getElementById('backups-tbody');
  if (!data.length) { tbody.innerHTML = '<tr><td colspan="4" style="color:var(--muted);padding:20px;">Cap backup registrat</td></tr>'; return; }
  tbody.innerHTML = data.map(b => `<tr>
    <td>#${b.id_backup}</td>
    <td style="font-size:11px;">${b.data_hora?.substring(0,16)||'—'}</td>
    <td style="font-size:11px;">${esc(b.taules_incloses)}</td>
    <td><span class="badge badge-green">${esc(b.resultat)}</span></td>
  </tr>`).join('');
}

// --- NOVA TRUCADA ---
async function loadUsuarisSelect() {
  const data = await api('usuaris_select');
  const sel = document.getElementById('nova-trucada-dest');
  sel.innerHTML = data.filter(u => u.id_usuari != USER_ID).map(u =>
    `<option value="${u.id_usuari}">${esc(u.nom_complet)}</option>`
  ).join('');
}

async function loadQualitats() {
  const data = await api('qualitats');
  const sel = document.getElementById('nova-trucada-qualitat');
  sel.innerHTML = data.map(g => `<option value="${g.id_grup}">${esc(g.nom_grup)}</option>`).join('');
}

async function iniciaTrucada() {
  const fd = new FormData();
  fd.append('action','nova_trucada');
  fd.append('destinatari', document.getElementById('nova-trucada-dest').value);
  fd.append('qualitat', document.getElementById('nova-trucada-qualitat').value);
  const r = await fetch('', {method:'POST', body:fd});
  const d = await r.json();
  if (d.ok) {
    currentTrucadaId = d.id;
    const wrap = document.getElementById('trucada-link-wrap');
    wrap.classList.remove('hidden');
    document.getElementById('trucada-link-msg').textContent = '✓ Trucada #' + d.id + ' creada. Sala Jitsi preparada.';
    document.getElementById('trucada-link-btn').href = d.link;
    setupStars('rating-stars');
    showToast('Trucada iniciada!');
  } else {
    showToast('Error: ' + d.error, true);
  }
}

async function finalitzaTrucada() {
  if (!currentTrucadaId) return;
  const fd = new FormData();
  fd.append('action','finalitzar_trucada');
  fd.append('id_trucada', currentTrucadaId);
  fd.append('puntuacio', ratingVal || '');
  fd.append('comentari', document.getElementById('trucada-comentari').value);
  await fetch('', {method:'POST', body:fd});
  closeModal('modal-nova-trucada');
  showToast('Trucada finalitzada i valorada');
  if (document.getElementById('page-trucades') && !document.getElementById('page-trucades').classList.contains('hidden')) loadTrucades();
  currentTrucadaId = null; ratingVal = 0;
}

function obreFinalitzar(id) {
  document.getElementById('fin-id').value = id;
  setupStars('fin-stars');
  openModal('modal-finalitzar');
}

async function submitFinalitzar() {
  const fd = new FormData();
  fd.append('action','finalitzar_trucada');
  fd.append('id_trucada', document.getElementById('fin-id').value);
  fd.append('puntuacio', ratingVal || '');
  fd.append('comentari', document.getElementById('fin-comentari').value);
  await fetch('', {method:'POST', body:fd});
  closeModal('modal-finalitzar');
  showToast('Trucada finalitzada');
  loadTrucades();
}

// --- NOU VIDEO ---
async function nouVideo() {
  const fd = new FormData();
  fd.append('action','nou_video');
  fd.append('titol', document.getElementById('v-titol').value);
  fd.append('descripcio', document.getElementById('v-desc').value);
  fd.append('categoria', document.getElementById('v-cat').value);
  fd.append('durada', document.getElementById('v-durada').value);
  fd.append('data_publicacio', document.getElementById('v-data').value);
  fd.append('link', document.getElementById('v-link').value);
  await fetch('', {method:'POST', body:fd});
  closeModal('modal-nou-video');
  showToast('Vídeo afegit correctament');
  loadVideos();
}

// --- STARS ---
function setupStars(containerId) {
  ratingVal = 0;
  const stars = document.querySelectorAll('#' + containerId + ' .star');
  stars.forEach(s => {
    s.classList.remove('active');
    s.onclick = () => {
      ratingVal = parseInt(s.dataset.v);
      stars.forEach(st => st.classList.toggle('active', parseInt(st.dataset.v) <= ratingVal));
    };
  });
}

// --- ESCAPE HTML ---
function esc(s) { if (!s) return ''; return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

// --- INIT ---
document.addEventListener('DOMContentLoaded', () => {
  <?php if (isLogged()): ?>
  showPage('dashboard');
  <?php endif; ?>
});
</script>
</body>
</html>
