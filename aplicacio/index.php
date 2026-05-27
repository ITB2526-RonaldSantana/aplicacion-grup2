<?php
mysqli_report(MYSQLI_REPORT_OFF);

define('DB_HOST','32.197.67.184');
define('DB_USER','webadmin');
define('DB_PASS','pirineus');
define('DB_NAME','InnovateTech');
define('JITSI_HOST','3.234.196.49');
define('STREAMING_HOST','23.23.53.151');

// ── CLI MODE: fallback si fastcgi_finish_request no està disponible ───────────
if(php_sapi_name()==='cli' && ($argv[1]??'')==='mesura'){
    $uid=(int)($argv[2]??1);
    $db=new mysqli(DB_HOST,DB_USER,DB_PASS,DB_NAME);
    if(!$db->connect_error){$db->set_charset('utf8mb4');runMesuraBanda($uid,$db);$db->close();}
    exit;
}
// ─────────────────────────────────────────────────────────────────────────────

session_save_path(sys_get_temp_dir());
session_start();

function getDB(){
    $c=new mysqli(DB_HOST,DB_USER,DB_PASS,DB_NAME);
    if($c->connect_error)die(json_encode(['error'=>$c->connect_error]));
    $c->set_charset('utf8mb4');
    return $c;
}
function dbq($db,$sql){$r=$db->query($sql);return $r?:null;}
function dbrow($db,$sql){$r=dbq($db,$sql);return $r?$r->fetch_assoc():null;}

$ROL_PERMISOS=[
    'admin'        =>['taules'=>['DEPARTAMENT','EMPLEAT','USUARI','ROL','USUARI_ROL','GRUP_QUALITAT','TRUCADA','VIDEO','MESURA_AMPLADA_BANDA','CONFIGURACIO_SERVIDOR','AVIS','CONTROL_BACKUP','CONTRASENYES'],'readonly'=>[]],
    'vendes'       =>['taules'=>['TRUCADA','USUARI','VIDEO'],'readonly'=>['USUARI','VIDEO']],
    'administracio'=>['taules'=>['EMPLEAT','DEPARTAMENT','USUARI','USUARI_ROL'],'readonly'=>[]],
    'treballador'  =>['taules'=>['VIDEO','TRUCADA'],'readonly'=>['VIDEO','TRUCADA']],
];

function getRol()     {return $_SESSION['rol'] ?? '';}
function getUID()     {return (int)($_SESSION['uid'] ?? 0);}
function isAdmin()    {return in_array(getRol(),['admin','administracio']);}
function isSuperAdmin(){return getRol()==='admin';}
function tableAllowed($t){global $ROL_PERMISOS;return in_array(strtoupper($t),array_map('strtoupper',$ROL_PERMISOS[getRol()]['taules']??[]));}
function isReadonly($t)  {global $ROL_PERMISOS;return in_array(strtoupper($t),array_map('strtoupper',$ROL_PERMISOS[getRol()]['readonly']??[]));}
function addAvis($db,$taula,$operacio,$detall){
    $uid=getUID();$d=$db->real_escape_string($detall);
    $db->query("INSERT INTO AVIS (usuari_id,taula_afectada,operacio_intentada,data_hora,detall) VALUES ($uid,'$taula','$operacio',NOW(),'$d')");
}
function runMesuraBanda($uid,$db){
    $host=STREAMING_HOST;
    $dl_mbps=0;$ul_mbps=0;$lat=0;$method='curl';

    // Mètode 1: speedtest-cli (més precís, mesura contra servidors reals de Speedtest.net)
    exec('which speedtest-cli 2>/dev/null',$_w,$rc);
    if($rc===0){
        exec('speedtest-cli --simple 2>&1',$stOut,$stRet);
        $stText=implode("\n",$stOut);
        if($stRet===0&&!preg_match('/error/i',$stText)){
            if(preg_match('/Ping:\s*([\d.]+)/i',$stText,$m))     $lat=round((float)$m[1],2);
            if(preg_match('/Download:\s*([\d.]+)/i',$stText,$m)) $dl_mbps=round((float)$m[1],2);
            if(preg_match('/Upload:\s*([\d.]+)/i',$stText,$m))   $ul_mbps=round((float)$m[1],2);
            if($dl_mbps>0||$ul_mbps>0) $method='speedtest-cli';
        }
    }

    // Mètode 2 (fallback): curl si speedtest-cli no està disponible o ha fallat
    if($method==='curl'){
        // Baixada: provar servidor de streaming, fallback a Cloudflare
        foreach(["http://$host/videos/videoplayback.mp4","https://speed.cloudflare.com/__down?bytes=10000000"] as $url){
            $bytes=0;$t0=microtime(true);
            $ch=curl_init($url);
            curl_setopt_array($ch,[CURLOPT_TIMEOUT=>15,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_SSL_VERIFYPEER=>false,
                CURLOPT_WRITEFUNCTION=>function($c,$d)use(&$bytes,&$t0){
                    $bytes+=strlen($d);
                    return (microtime(true)-$t0>10||$bytes>10*1024*1024)?-1:strlen($d);
                }]);
            curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$el=microtime(true)-$t0;curl_close($ch);
            if($bytes>1024&&$code>=200&&$code<400){$dl_mbps=round($bytes/$el/125000,2);break;}
        }
        // Pujada mètode 1: endpoints externs (httpbin → Cloudflare)
        $payload=random_bytes(5*1024*1024);$ul_mbps=0;
        foreach(['https://httpbin.org/post','https://speed.cloudflare.com/__up'] as $ulUrl){
            $ch=curl_init($ulUrl);
            curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$payload,
                CURLOPT_TIMEOUT=>30,CURLOPT_SSL_VERIFYPEER=>false,
                CURLOPT_HTTPHEADER=>['Content-Type: application/octet-stream']]);
            $t0=microtime(true);$res=curl_exec($ch);$el=microtime(true)-$t0;
            $ulCode=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$ulErr=curl_errno($ch);curl_close($ch);
            if($res!==false&&$ulErr===0&&$el>0.001&&$ulCode>=200&&$ulCode<500){
                $ul_mbps=round((5*1024*1024)/$el/125000,2);break;
            }
        }
        unset($payload);
        // Pujada mètode 2 (fallback TCP): escriure al servidor de streaming durant 5 s
        if($ul_mbps===0){
            $sock=@stream_socket_client("tcp://$host:80",$se,$sm,5);
            if($sock){
                stream_set_blocking($sock,true);
                fwrite($sock,"POST / HTTP/1.1\r\nHost: $host\r\nContent-Type: application/octet-stream\r\nContent-Length: 52428800\r\nExpect:\r\n\r\n");
                $chunk=str_repeat('A',65536);$sent=0;$t0=microtime(true);$deadline=$t0+5;
                while(microtime(true)<$deadline){$n=@fwrite($sock,$chunk);if(!$n)break;$sent+=$n;}
                $el=max(microtime(true)-$t0,0.001);@fclose($sock);
                if($sent>1024) $ul_mbps=round($sent/$el/125000,2);
            }
        }
    }

    // Latència + jitter al servidor de streaming (sempre, independent del mètode)
    $rtts=[];
    for($i=0;$i<6;$i++){
        $t0=microtime(true);$s=@fsockopen($host,80,$e,$es,2);$rtt=(microtime(true)-$t0)*1000;
        if($s){fclose($s);$rtts[]=$rtt;}
    }
    sort($rtts);
    if(count($rtts)>=4){array_shift($rtts);array_pop($rtts);}
    if($method==='curl'&&count($rtts)>0) $lat=round(array_sum($rtts)/count($rtts),2);
    $jitter=0;
    if(count($rtts)>1){
        $mean=array_sum($rtts)/count($rtts);
        $jitter=round(sqrt(array_sum(array_map(fn($x)=>($x-$mean)**2,$rtts))/count($rtts)),2);
    }

    $resultat=($dl_mbps>=50&&$ul_mbps>=10&&$lat>0&&$lat<=150)?'acceptable':'no acceptable';
    $eq=$db->real_escape_string('Servidor Streaming ('.$host.')');
    $notes=$db->real_escape_string("Mètode: $method | Jitter: {$jitter}ms");
    $db->query("INSERT INTO MESURA_AMPLADA_BANDA (data_hora,usuari_equip_mesurat,velocitat_baixada,velocitat_pujada,latencia,resultat,operari_id,notes) VALUES (NOW(),'$eq',$dl_mbps,$ul_mbps,$lat,'$resultat',$uid,'$notes')");
}

if($_SERVER['REQUEST_METHOD']==='POST'&&isset($_POST['action'])){
    header('Content-Type: application/json');
    $act=$_POST['action'];

    /* LOGIN */
    if($act==='login'){
        $email=$_POST['username']??''; $p=$_POST['password']??'';
        if(!$email||!$p){echo json_encode(['ok'=>false,'msg'=>"Introdueix l'email i la contrasenya"]);exit;}
        $db=getDB();
        $st=$db->prepare("SELECT u.id_usuari,u.nom_complet,u.estat,u.tipus,c.hash_contrasenya pass,ur.nom_rol FROM USUARI u INNER JOIN CONTRASENYES c ON u.id_usuari=c.usuari_id LEFT JOIN USUARI_ROL ur ON u.id_usuari=ur.id_usuari WHERE u.email=? AND c.activa=1 LIMIT 1");
        $st->bind_param('s',$email);$st->execute();$row=$st->get_result()->fetch_assoc();
        if($row){
            if($row['tipus']==='extern'){echo json_encode(['ok'=>false,'msg'=>'Els clients externs no tenen accés a aquesta aplicació.']);$st->close();$db->close();exit;}
            // usuaris bloquejats poden iniciar sessió però no fer trucades
            if(password_verify($p,$row['pass'])||$p===$row['pass']){
                $_SESSION['user']=$email;$_SESSION['uid']=$row['id_usuari'];
                $_SESSION['nom_complet']=$row['nom_complet'];$_SESSION['rol']=$row['nom_rol']??'treballador';
                echo json_encode(['ok'=>true,'rol'=>$_SESSION['rol'],'nom_complet'=>$_SESSION['nom_complet'],'uid'=>$row['id_usuari']]);
            }else echo json_encode(['ok'=>false,'msg'=>'Contrasenya incorrecta']);
        }else echo json_encode(['ok'=>false,'msg'=>"No s'ha trobat cap usuari actiu"]);
        $st->close();$db->close();exit;
    }

    if(!isset($_SESSION['user'])){echo json_encode(['error'=>'No autenticat']);exit;}
    $db=getDB();

    if($act==='logout'){session_destroy();echo json_encode(['ok'=>true]);exit;}

    if($act==='tables'){global $ROL_PERMISOS;echo json_encode($ROL_PERMISOS[getRol()]['taules']??[]);exit;}

    /* DASHBOARD STATS */
    if($act==='dashboard_stats'){
        $uid=getUID();$stats=[];
        $stats['trucades']=(int)(dbrow($db,"SELECT COUNT(*) c FROM TRUCADA WHERE usuari_originador=$uid OR usuari_destinatari=$uid")['c']??0);
        $stats['videos']  =(int)(dbrow($db,"SELECT COUNT(*) c FROM VIDEO")['c']??0);
        if(isAdmin()){
            $stats['usuaris'] =(int)(dbrow($db,"SELECT COUNT(*) c FROM USUARI WHERE estat='actiu'")['c']??0);
            $stats['empleats']=(int)(dbrow($db,"SELECT COUNT(*) c FROM EMPLEAT")['c']??0);
            $stats['alertes'] =(int)(dbrow($db,"SELECT COUNT(*) c FROM MESURA_AMPLADA_BANDA WHERE resultat='no acceptable'")['c']??0);
            $bk=dbrow($db,"SELECT data_hora,resultat FROM CONTROL_BACKUP ORDER BY data_hora DESC LIMIT 1");
            $stats['backup_data']=$bk?$bk['data_hora']:null;
            $stats['backup_ok']  =$bk?$bk['resultat']:null;
        }
        if(getRol()==='vendes') $stats['clients']=(int)(dbrow($db,"SELECT COUNT(*) c FROM USUARI WHERE tipus='extern' AND estat='actiu'")['c']??0);
        echo json_encode($stats);exit;
    }

    /* TRUCADES */
    if($act==='trucades'){
        $uid=getUID();$rol=getRol();
        if($rol==='administracio'){
            addAvis($db,'TRUCADA','SELECT','Rol administracio intenta veure historial de trucades');
            echo json_encode(['ok'=>false,'audit'=>true,'msg'=>"Accés denegat. Acció registrada a l'auditoria."]);exit;
        }
        // admin/vendes see all; treballador sees only own
        $own="(t.usuari_originador=$uid OR t.usuari_destinatari=$uid)";
        $where=($rol==='treballador')?"WHERE $own":"";
        $res=$db->query("SELECT t.id_trucada,t.data_inici,t.data_fi,t.durada_total,t.puntuacio,t.comentari,u1.nom_complet nom_orig,u2.nom_complet nom_dest,g.nom_grup qualitat FROM TRUCADA t JOIN USUARI u1 ON t.usuari_originador=u1.id_usuari JOIN USUARI u2 ON t.usuari_destinatari=u2.id_usuari JOIN GRUP_QUALITAT g ON t.id_grup_qualitat=g.id_grup $where ORDER BY t.data_inici DESC LIMIT 50");
        $rows=[];while($r=$res->fetch_assoc())$rows[]=$r;
        echo json_encode($rows);exit;
    }
    if($act==='iniciar_trucada'){
        $rol=getRol();$orig=getUID();$dest=(int)$_POST['destinatari_id'];
        if($rol==='administracio'){
            addAvis($db,'TRUCADA','INSERT','Rol administracio intenta iniciar trucada amb usuari '.$dest);
            echo json_encode(['ok'=>false,'audit'=>true,'msg'=>"Accés denegat. Acció registrada a l'auditoria."]);exit;
        }
        $destRow=dbrow($db,"SELECT tipus,estat FROM USUARI WHERE id_usuari=$dest");
        if(!$destRow){echo json_encode(['ok'=>false,'msg'=>"L'usuari no existeix."]);exit;}
        if($destRow['estat']==='bloquejat'){echo json_encode(['ok'=>false,'msg'=>"L'usuari està bloquejat i no pot rebre trucades."]);exit;}
        if($rol==='treballador'&&$destRow['tipus']==='extern'){
            echo json_encode(['ok'=>false,'msg'=>'Els treballadors no poden trucar a clients externs.']);exit;
        }
        $grpRow=dbrow($db,"SELECT id_grup FROM GRUP_QUALITAT ORDER BY id_grup LIMIT 1");
        $grp=$grpRow?(int)$grpRow['id_grup']:1;
        $db->query("INSERT INTO TRUCADA (usuari_originador,usuari_destinatari,data_inici,id_grup_qualitat) VALUES ($orig,$dest,NOW(),$grp)");
        if($db->errno!==0){
            $msg=$db->errno===1644?$db->error:'Error en registrar la trucada.';
            echo json_encode(['ok'=>false,'msg'=>$msg]);exit;
        }
        echo json_encode(['ok'=>true,'id'=>$db->insert_id]);exit;
    }
    if($act==='finalitzar_trucada'){
        $id=(int)$_POST['id'];
        $db->query("UPDATE TRUCADA SET data_fi=NOW(),durada_total=TIMESTAMPDIFF(SECOND,data_inici,NOW()) WHERE id_trucada=$id AND data_fi IS NULL");
        echo json_encode(['ok'=>true]);exit;
    }
    if($act==='trucada_entrant'){
        $uid=getUID();
        $estatRow=dbrow($db,"SELECT estat FROM USUARI WHERE id_usuari=$uid");
        $estat=$estatRow?$estatRow['estat']:'actiu';
        if($estat==='bloquejat'){echo json_encode(['ok'=>false]);exit;}
        $res=$db->query("SELECT t.id_trucada,u.nom_complet nom_orig FROM TRUCADA t JOIN USUARI u ON t.usuari_originador=u.id_usuari WHERE t.usuari_destinatari=$uid AND t.data_fi IS NULL AND t.data_inici >= NOW() - INTERVAL 5 MINUTE ORDER BY t.data_inici DESC LIMIT 1");
        $row=$res->fetch_assoc();
        echo json_encode($row?['ok'=>true,'trucada'=>$row]:['ok'=>false]);exit;
    }
    if($act==='valorar_trucada'){
        $id=(int)$_POST['id'];$pun=max(1,min(5,(int)$_POST['puntuacio']));
        $com=$db->real_escape_string($_POST['comentari']??'');
        $db->query("UPDATE TRUCADA SET puntuacio=$pun,comentari='$com' WHERE id_trucada=$id");
        echo json_encode(['ok'=>true]);exit;
    }
    if($act==='usuaris_llista'){
        $uid=getUID();$rol=getRol();
        if($rol==='administracio'){echo json_encode([]);exit;}
        $extra=($rol==='treballador')?" AND tipus='intern'":'';
        $res=$db->query("SELECT id_usuari,nom_complet,email,tipus FROM USUARI WHERE estat='actiu' AND id_usuari!=$uid$extra ORDER BY nom_complet");
        $rows=[];while($r=$res->fetch_assoc())$rows[]=$r;
        echo json_encode($rows);exit;
    }

    /* VÍDEOS */
    if($act==='videos'){
        $s=isset($_POST['search'])?'%'.$db->real_escape_string($_POST['search']).'%':'';
        $w=$s?"WHERE titol LIKE '$s' OR categoria LIKE '$s' OR descripcio LIKE '$s'":'';
        $res=$db->query("SELECT * FROM VIDEO $w ORDER BY data_publicacio DESC");
        $rows=[];while($r=$res->fetch_assoc())$rows[]=$r;
        echo json_encode($rows);exit;
    }

    /* ÀUDIO */
    if($act==='canals_audio'){
        $res=$db->query("SELECT * FROM CONFIGURACIO_SERVIDOR WHERE parametre LIKE 'audio_%' ORDER BY parametre");
        $rows=[];while($r=$res->fetch_assoc())$rows[]=$r;
        echo json_encode($rows);exit;
    }

    /* GESTIÓ USUARIS / BLOQUEIG */
    if($act==='usuaris_gestio'){
        if(!isAdmin()){echo json_encode(['error'=>'Accés denegat']);exit;}
        $res=$db->query("SELECT u.id_usuari,u.nom_complet,u.email,u.estat,u.tipus,ur.nom_rol FROM USUARI u LEFT JOIN USUARI_ROL ur ON u.id_usuari=ur.id_usuari ORDER BY u.estat DESC,u.nom_complet");
        $rows=[];while($r=$res->fetch_assoc())$rows[]=$r;
        echo json_encode($rows);exit;
    }
    if($act==='bloquejar_usuari'||$act==='desbloquejar_usuari'){
        if(!isAdmin()){echo json_encode(['error'=>'Accés denegat']);exit;}
        $id=(int)$_POST['id'];$nou=$act==='bloquejar_usuari'?'bloquejat':'actiu';
        $db->query("UPDATE USUARI SET estat='$nou' WHERE id_usuari=$id");
        if($nou==='bloquejat')addAvis($db,'USUARI','UPDATE','Usuari '.$id.' bloquejat per '.getUID());
        echo json_encode(['ok'=>true]);exit;
    }

    /* ADMIN PANELS */
    if($act==='executar_mesura_banda'){
        if(!isSuperAdmin()){echo json_encode(['error'=>'Accés denegat']);exit;}
        $uid=getUID();
        if(function_exists('fastcgi_finish_request')){
            // PHP-FPM: enviar resposta al client i continuar mesurant en background
            echo json_encode(['ok'=>true,'msg'=>'Mesura iniciada. Els resultats apareixeran en uns 60 segons.']);
            fastcgi_finish_request();
            ignore_user_abort(true);set_time_limit(120);
            runMesuraBanda($uid,$db);
        }else{
            // Fallback: procés CLI en background
            $log=sys_get_temp_dir().'/mesura_banda.log';
            exec("nohup ".PHP_BINARY." ".escapeshellarg(__FILE__)." mesura $uid > ".escapeshellarg($log)." 2>&1 &");
            echo json_encode(['ok'=>true,'msg'=>'Mesura iniciada en segon pla. Els resultats apareixeran en uns 60 segons.']);
        }
        exit;
    }
    if($act==='mesures_banda'){
        if(!isSuperAdmin()){echo json_encode(['error'=>'Accés denegat']);exit;}
        $res=$db->query("SELECT m.*,u.nom_complet FROM MESURA_AMPLADA_BANDA m JOIN USUARI u ON m.operari_id=u.id_usuari ORDER BY m.data_hora DESC LIMIT 100");
        $rows=[];while($r=$res->fetch_assoc())$rows[]=$r;
        echo json_encode($rows);exit;
    }
    if($act==='avisos_log'){
        if(!isSuperAdmin()){echo json_encode(['error'=>'Accés denegat']);exit;}
        $res=$db->query("SELECT a.*,u.nom_complet FROM AVIS a JOIN USUARI u ON a.usuari_id=u.id_usuari ORDER BY a.data_hora DESC");
        $rows=[];while($r=$res->fetch_assoc())$rows[]=$r;
        echo json_encode($rows);exit;
    }
    if($act==='backups_log'){
        if(!isSuperAdmin()){echo json_encode(['error'=>'Accés denegat']);exit;}
        $res=$db->query("SELECT * FROM CONTROL_BACKUP ORDER BY data_hora DESC");
        $rows=[];while($r=$res->fetch_assoc())$rows[]=$r;
        echo json_encode($rows);exit;
    }

    /* CRUD */
    $table=preg_replace('/[^a-zA-Z_]/','', $_POST['table']??'');
    if($act==='read'){
        if(!tableAllowed($table)){echo json_encode(['error'=>'Accés denegat']);exit;}
        $s=isset($_POST['search'])?'%'.$db->real_escape_string($_POST['search']).'%':'%';
        $cols=$db->query("SHOW COLUMNS FROM `$table`");$fields=[];
        while($c=$cols->fetch_assoc())$fields[]=$c['Field'];
        $w=($s!=='%%')?'WHERE '.implode(' OR ',array_map(fn($f)=>"`$f` LIKE '$s'",$fields)):'';
        $res=$db->query("SELECT * FROM `$table` $w LIMIT 200");
        $rows=[];while($r=$res->fetch_assoc())$rows[]=$r;
        echo json_encode(['cols'=>$fields,'rows'=>$rows,'readonly'=>isReadonly($table)]);exit;
    }
    if($act==='insert'){
        if(!tableAllowed($table)||isReadonly($table)){echo json_encode(['error'=>'Accés denegat']);exit;}
        $data=json_decode($_POST['data'],true);
        $db->query("INSERT INTO `$table` (`".implode('`,`',array_keys($data))."`) VALUES ('".implode("','",array_map([$db,'real_escape_string'],array_values($data)))."')");
        echo json_encode(['ok'=>true,'id'=>$db->insert_id]);exit;
    }
    if($act==='update'){
        if(!tableAllowed($table)||isReadonly($table)){echo json_encode(['error'=>'Accés denegat']);exit;}
        $data=json_decode($_POST['data'],true);$id=(int)$_POST['id'];$pk=$db->real_escape_string($_POST['pk']);
        $set=implode(',',array_map(fn($k,$v)=>"`$k`='".$db->real_escape_string($v)."'",array_keys($data),$data));
        $db->query("UPDATE `$table` SET $set WHERE `$pk`=$id");
        echo json_encode(['ok'=>true]);exit;
    }
    if($act==='delete'){
        if(!tableAllowed($table)||isReadonly($table)){echo json_encode(['error'=>'Accés denegat']);exit;}
        $id=(int)$_POST['id'];$pk=$db->real_escape_string($_POST['pk']);
        $db->query("DELETE FROM `$table` WHERE `$pk`=$id");
        echo json_encode(['ok'=>true]);exit;
    }
    echo json_encode(['error'=>'Acció desconeguda']);exit;
}
?>
<!DOCTYPE html>
<html lang="ca">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>InnovateTech — Panel de gestió</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Mono:wght@300;400;500&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#0a0a0f;--bg2:#111118;--bg3:#1a1a24;--border:#2a2a3a;
  --accent:#6c63ff;--accent2:#ff6584;--accent3:#43e8b0;
  --text:#e8e8f0;--muted:#6b6b80;--success:#43e8b0;--danger:#ff6584;
  --font:'Syne',sans-serif;--mono:'DM Mono',monospace;
  --radius:12px;--shadow:0 8px 32px rgba(108,99,255,.15);
}
body{font-family:var(--font);background:var(--bg);color:var(--text);min-height:100vh;overflow-x:hidden}
/* LOGIN */
#login-screen{position:fixed;inset:0;display:flex;align-items:center;justify-content:center;z-index:100;background:var(--bg)}
.login-card{width:420px;padding:56px 48px;background:var(--bg2);border:1px solid var(--border);border-radius:24px;box-shadow:var(--shadow),0 0 80px rgba(108,99,255,.08);animation:fadeUp .6s ease}
.login-logo{font-size:13px;font-weight:600;letter-spacing:.2em;text-transform:uppercase;color:var(--accent);margin-bottom:8px;font-family:var(--mono)}
.login-title{font-size:32px;font-weight:800;line-height:1.1;margin-bottom:40px;background:linear-gradient(135deg,var(--text),var(--muted));-webkit-background-clip:text;-webkit-text-fill-color:transparent}
.login-error{margin-top:16px;padding:12px;background:rgba(255,101,132,.1);border:1px solid rgba(255,101,132,.3);border-radius:8px;color:var(--danger);font-size:13px;font-family:var(--mono);display:none}
/* FIELDS & BUTTONS */
.field{margin-bottom:20px}
.field label{display:block;font-size:12px;font-weight:600;letter-spacing:.1em;text-transform:uppercase;color:var(--muted);margin-bottom:8px;font-family:var(--mono)}
.field input,.field textarea{width:100%;padding:14px 16px;background:var(--bg3);border:1px solid var(--border);border-radius:var(--radius);color:var(--text);font-family:var(--mono);font-size:14px;outline:none;transition:border-color .2s,box-shadow .2s}
.field input:focus,.field textarea:focus{border-color:var(--accent);box-shadow:0 0 0 3px rgba(108,99,255,.15)}
.input-suffix-wrap{display:flex;align-items:center;background:var(--bg3);border:1px solid var(--border);border-radius:var(--radius);transition:border-color .2s,box-shadow .2s}
.input-suffix-wrap:focus-within{border-color:var(--accent);box-shadow:0 0 0 3px rgba(108,99,255,.15)}
.input-suffix-wrap input{flex:1;background:transparent;border:none;padding:14px 0 14px 16px;color:var(--text);font-family:var(--mono);font-size:14px;outline:none;min-width:0}
.input-suffix{padding:14px 16px 14px 0;color:var(--muted);font-family:var(--mono);font-size:14px;white-space:nowrap;user-select:none}
.field textarea{resize:vertical;min-height:80px}
.btn{display:inline-flex;align-items:center;gap:8px;padding:14px 24px;border:none;border-radius:var(--radius);font-family:var(--font);font-size:14px;font-weight:700;cursor:pointer;transition:all .2s;letter-spacing:.03em}
.btn-primary{background:var(--accent);color:#fff;justify-content:center;font-size:15px}
.btn-primary:hover{background:#7c74ff;transform:translateY(-1px);box-shadow:0 8px 24px rgba(108,99,255,.4)}
.btn-sm{padding:8px 14px;font-size:12px}
.btn-success{background:rgba(67,232,176,.15);color:var(--success);border:1px solid rgba(67,232,176,.3)}
.btn-success:hover{background:rgba(67,232,176,.25)}
.btn-danger{background:rgba(255,101,132,.15);color:var(--danger);border:1px solid rgba(255,101,132,.3)}
.btn-danger:hover{background:rgba(255,101,132,.25)}
.btn-ghost{background:transparent;color:var(--muted);border:1px solid var(--border)}
.btn-ghost:hover{color:var(--text);border-color:var(--muted)}
.btn-info{background:rgba(108,99,255,.12);color:var(--accent);border:1px solid rgba(108,99,255,.3)}
.btn-info:hover{background:rgba(108,99,255,.22)}
.btn-accent2{background:rgba(255,101,132,.15);color:var(--accent2);border:1px solid rgba(255,101,132,.3)}
.btn-accent2:hover{background:rgba(255,101,132,.25)}
/* LAYOUT */
#app{display:none;min-height:100vh}
.sidebar{position:fixed;left:0;top:0;bottom:0;width:300px;background:var(--bg2);border-right:1px solid var(--border);display:flex;flex-direction:column;z-index:50;overflow-y:auto}
.sidebar-logo{padding:28px 28px 24px;border-bottom:1px solid var(--border);flex-shrink:0}
.sidebar-logo .logo-tag{font-size:10px;font-family:var(--mono);color:var(--accent);letter-spacing:.2em;text-transform:uppercase;margin-bottom:4px}
.sidebar-logo h1{font-size:20px;font-weight:800;background:linear-gradient(135deg,var(--accent),var(--accent2));-webkit-background-clip:text;-webkit-text-fill-color:transparent}
.sidebar-nav{flex:1;padding:16px 0}
.nav-section{margin-bottom:8px}
.nav-section-label{font-size:10px;font-family:var(--mono);letter-spacing:.15em;text-transform:uppercase;color:var(--muted);padding:0 24px;margin-bottom:4px;margin-top:16px;display:block}
.nav-item{display:flex;align-items:center;gap:10px;width:100%;padding:9px 12px;margin:0 8px;width:calc(100% - 16px);background:transparent;border:none;border-radius:8px;color:var(--muted);font-family:var(--font);font-size:13px;font-weight:600;cursor:pointer;transition:all .2s;text-align:left}
.nav-item:hover{background:var(--bg3);color:var(--text)}
.nav-item.active{background:rgba(108,99,255,.15);color:var(--accent)}
.nav-dot{width:6px;height:6px;border-radius:50%;background:var(--border);flex-shrink:0;transition:all .2s}
.nav-item.active .nav-dot{background:var(--accent);box-shadow:0 0 6px var(--accent)}
.nav-divider{height:1px;background:var(--border);margin:8px 16px}
.sidebar-footer{padding:16px;border-top:1px solid var(--border);flex-shrink:0}
.user-badge{display:flex;align-items:center;gap:12px;padding:12px;background:var(--bg3);border-radius:var(--radius);margin-bottom:12px}
.user-avatar{width:36px;height:36px;border-radius:50%;background:linear-gradient(135deg,var(--accent),var(--accent2));display:flex;align-items:center;justify-content:center;font-weight:800;font-size:14px;color:#fff;flex-shrink:0}
.user-info{flex:1;overflow:hidden}
.user-name{font-size:13px;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.user-rol{font-size:11px;font-family:var(--mono);color:var(--muted)}
.main{margin-left:300px;padding:40px;min-height:100vh}
/* PAGE */
.page-header{display:flex;align-items:flex-end;justify-content:space-between;margin-bottom:32px;gap:16px;flex-wrap:wrap}
.breadcrumb{font-size:11px;font-family:var(--mono);color:var(--muted);letter-spacing:.1em;text-transform:uppercase;margin-bottom:6px}
.page-title{font-size:28px;font-weight:800;background:linear-gradient(135deg,var(--text),var(--muted));-webkit-background-clip:text;-webkit-text-fill-color:transparent}
.toolbar{display:flex;gap:12px;align-items:center;flex-wrap:wrap}
.search-wrap{position:relative}
.search-wrap input{padding:10px 16px 10px 40px;background:var(--bg2);border:1px solid var(--border);border-radius:var(--radius);color:var(--text);font-family:var(--mono);font-size:13px;outline:none;width:240px;transition:border-color .2s}
.search-wrap input:focus{border-color:var(--accent)}
.search-wrap::before{content:'⌕';position:absolute;left:14px;top:50%;transform:translateY(-50%);color:var(--muted);font-size:16px}
/* CARDS & STATS */
.cards-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:16px;margin-bottom:32px}
.card{background:var(--bg2);border:1px solid var(--border);border-radius:var(--radius);padding:20px 24px;position:relative;overflow:hidden;transition:border-color .2s}
.card::before{content:'';position:absolute;top:0;left:0;right:0;height:2px;background:linear-gradient(90deg,var(--accent),var(--accent2))}
.card:hover{border-color:var(--accent)}
.card-label{font-size:11px;font-family:var(--mono);color:var(--muted);letter-spacing:.1em;text-transform:uppercase;margin-bottom:8px}
.card-value{font-size:28px;font-weight:800;background:linear-gradient(135deg,var(--text),var(--accent));-webkit-background-clip:text;-webkit-text-fill-color:transparent}
.card-value.sm{font-size:15px;padding-top:6px;line-height:1.4}
.card-alert::before{background:linear-gradient(90deg,var(--danger),var(--accent2))}
/* TABLE COMPONENT */
.table-card{background:var(--bg2);border:1px solid var(--border);border-radius:var(--radius);overflow:hidden}
.table-wrap{overflow-x:auto}
table{width:100%;border-collapse:collapse;font-size:13px}
thead tr{background:var(--bg3);border-bottom:1px solid var(--border)}
th{padding:14px 16px;text-align:left;font-family:var(--mono);font-size:11px;font-weight:500;letter-spacing:.1em;text-transform:uppercase;color:var(--muted);white-space:nowrap}
td{padding:12px 16px;border-bottom:1px solid rgba(42,42,58,.5);color:var(--text);font-family:var(--mono);font-size:12px;white-space:nowrap;max-width:200px;overflow:hidden;text-overflow:ellipsis}
tbody tr{transition:background .15s}
tbody tr:hover{background:rgba(108,99,255,.05)}
tbody tr:last-child td{border-bottom:none}
.actions-cell{display:flex;gap:6px}
/* VIDEO CATALOG */
.video-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:20px;margin-top:24px}
.video-card{background:var(--bg2);border:1px solid var(--border);border-radius:var(--radius);overflow:hidden;transition:border-color .2s,transform .2s;cursor:pointer}
.video-card:hover{border-color:var(--accent);transform:translateY(-2px)}
.video-thumb{height:140px;background:linear-gradient(135deg,var(--bg3),var(--bg));display:flex;align-items:center;justify-content:center;font-size:40px;border-bottom:1px solid var(--border);position:relative}
.video-thumb-overlay{position:absolute;inset:0;background:rgba(108,99,255,.15);display:flex;align-items:center;justify-content:center;opacity:0;transition:opacity .2s}
.video-card:hover .video-thumb-overlay{opacity:1}
.video-play{width:48px;height:48px;border-radius:50%;background:var(--accent);display:flex;align-items:center;justify-content:center;font-size:18px;color:#fff}
.video-info{padding:16px}
.video-title{font-size:14px;font-weight:700;margin-bottom:6px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.video-meta{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.badge{display:inline-flex;padding:3px 10px;border-radius:20px;font-size:11px;font-family:var(--mono)}
.badge-accent{background:rgba(108,99,255,.15);color:var(--accent)}
.badge-ok{background:rgba(67,232,176,.15);color:var(--success)}
.badge-err{background:rgba(255,101,132,.15);color:var(--danger)}
.badge-muted{background:var(--bg3);color:var(--muted)}
/* CALL HISTORY */
.stars{color:#fbbf24;letter-spacing:2px;font-size:14px}
.stars.muted{color:var(--border)}
/* JITSI */
.jitsi-setup{display:flex;flex-direction:column;align-items:center;gap:24px;padding:48px 40px;text-align:center}
.jitsi-icon{width:72px;height:72px;border-radius:50%;background:linear-gradient(135deg,rgba(108,99,255,.2),rgba(255,101,132,.2));border:1px solid var(--border);display:flex;align-items:center;justify-content:center;font-size:28px}
.jitsi-setup h3{font-size:20px;font-weight:800}
.jitsi-setup p{font-size:13px;color:var(--muted);font-family:var(--mono);max-width:400px;line-height:1.6}
/* MODALS */
.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.7);backdrop-filter:blur(4px);z-index:200;display:flex;align-items:center;justify-content:center;opacity:0;pointer-events:none;transition:opacity .2s}
.modal-overlay.open{opacity:1;pointer-events:all}
.modal{background:var(--bg2);border:1px solid var(--border);border-radius:20px;padding:40px;width:560px;max-width:95vw;max-height:85vh;overflow-y:auto;box-shadow:var(--shadow);transform:translateY(20px);transition:transform .2s}
.modal-overlay.open .modal{transform:translateY(0)}
.modal-title{font-size:20px;font-weight:800;margin-bottom:24px;display:flex;align-items:center;gap:12px}
.modal-title span{font-size:13px;font-family:var(--mono);color:var(--muted);font-weight:400}
.modal-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:28px}
.modal-grid .field:only-child,.modal-grid .field.full{grid-column:1/-1}
.modal-actions{display:flex;gap:12px;justify-content:flex-end}
/* STAR RATING */
.star-rating{display:flex;gap:8px;margin-bottom:4px}
.star-rating span{font-size:32px;color:var(--border);cursor:pointer;transition:color .15s;line-height:1}
.star-rating span.on{color:#fbbf24}
/* VIEW MODAL */
.view-list{display:flex;flex-direction:column;margin-bottom:28px}
.view-row{display:grid;grid-template-columns:150px 1fr;gap:16px;padding:12px 0;border-bottom:1px solid rgba(42,42,58,.6)}
.view-row:last-child{border-bottom:none}
.view-key{font-family:var(--mono);font-size:11px;letter-spacing:.1em;text-transform:uppercase;color:var(--muted);padding-top:2px}
.view-val{font-family:var(--mono);font-size:13px;color:var(--text);word-break:break-word}
.view-link{display:inline-flex;align-items:center;gap:8px;padding:7px 14px;background:rgba(108,99,255,.1);border:1px solid rgba(108,99,255,.3);border-radius:8px;color:var(--accent);text-decoration:none;font-size:12px;word-break:break-all;transition:all .2s}
.view-link:hover{background:rgba(108,99,255,.2)}
.cell-link{color:var(--accent);text-decoration:none;border-bottom:1px dashed rgba(108,99,255,.5)}
.cell-link:hover{color:#9d96ff}
/* USER LIST (new call modal) */
.user-list{display:flex;flex-direction:column;gap:8px;max-height:320px;overflow-y:auto;margin-bottom:24px}
.user-list-item{display:flex;align-items:center;justify-content:space-between;padding:12px 16px;background:var(--bg3);border:1px solid var(--border);border-radius:var(--radius);cursor:pointer;transition:border-color .2s}
.user-list-item:hover{border-color:var(--accent)}
.user-list-item .uname{font-size:14px;font-weight:700}
.user-list-item .uemail{font-size:12px;font-family:var(--mono);color:var(--muted)}
/* VIDEO PLAYER MODAL */
.video-modal-inner{padding:0;overflow:hidden}
.video-player-wrap{background:#000;aspect-ratio:16/9;width:100%}
.video-player-wrap iframe,.video-player-wrap video{width:100%;height:100%;border:none;display:block}
/* MISC */
.empty{text-align:center;padding:64px 32px;color:var(--muted)}
.empty-icon{font-size:40px;margin-bottom:16px;opacity:.4}
.loading{display:flex;align-items:center;justify-content:center;padding:64px;gap:8px;color:var(--muted);font-family:var(--mono);font-size:13px}
.spinner{width:16px;height:16px;border:2px solid var(--border);border-top-color:var(--accent);border-radius:50%;animation:spin .8s linear infinite}
.welcome{display:flex;flex-direction:column;align-items:center;justify-content:center;min-height:60vh;text-align:center;animation:fadeUp .5s ease}
.tag{display:inline-flex;align-items:center;padding:3px 10px;border-radius:20px;font-size:11px;font-family:var(--mono)}
.tag-accent{background:rgba(108,99,255,.15);color:var(--accent)}
.toast{position:fixed;bottom:32px;right:32px;padding:14px 20px;border-radius:var(--radius);font-size:13px;font-family:var(--mono);font-weight:500;z-index:999;transform:translateY(100px);transition:transform .3s cubic-bezier(.34,1.56,.64,1);pointer-events:none}
.toast.show{transform:translateY(0)}
.toast.success{background:rgba(67,232,176,.15);border:1px solid rgba(67,232,176,.4);color:var(--success)}
.toast.error{background:rgba(255,101,132,.15);border:1px solid rgba(255,101,132,.4);color:var(--danger)}
@keyframes spin{to{transform:rotate(360deg)}}
@keyframes fadeUp{from{opacity:0;transform:translateY(24px)}to{opacity:1;transform:translateY(0)}}
@keyframes ringPulse{0%,100%{transform:scale(1)}50%{transform:scale(1.15)}}
/* INCOMING CALL BANNER */
#incoming-banner{position:fixed;top:24px;left:50%;transform:translateX(-50%);z-index:1200;
  background:linear-gradient(135deg,#1e1e30,#252535);border:1px solid rgba(108,99,255,.5);
  box-shadow:0 8px 40px rgba(108,99,255,.35);border-radius:20px;padding:18px 28px;
  display:flex;align-items:center;gap:20px;min-width:360px;max-width:90vw;
  animation:fadeUp .4s ease}
.incoming-pulse{font-size:32px;animation:ringPulse .7s ease infinite}
.incoming-label{font-size:11px;color:var(--muted);font-family:var(--mono);margin-bottom:4px}
.incoming-caller{font-size:17px;font-weight:700;color:var(--text)}
.incoming-actions{display:flex;gap:10px;margin-left:auto;flex-shrink:0}
.btn-accept{background:rgba(67,232,176,.2);border:1px solid rgba(67,232,176,.5);color:var(--success);
  padding:10px 18px;border-radius:10px;cursor:pointer;font-weight:600;font-size:13px;transition:background .2s}
.btn-accept:hover{background:rgba(67,232,176,.35)}
.btn-decline{background:rgba(255,101,132,.15);border:1px solid rgba(255,101,132,.4);color:var(--danger);
  padding:10px 18px;border-radius:10px;cursor:pointer;font-weight:600;font-size:13px;transition:background .2s}
.btn-decline:hover{background:rgba(255,101,132,.3)}
</style>
<script src="https://cdn.jsdelivr.net/npm/hls.js@1/dist/hls.min.js"></script>
</head>
<body>

<div id="login-screen">
  <div class="login-card">
    <div class="login-logo">InnovateTech · CPD</div>
    <h2 class="login-title">Panel de<br>gestió</h2>
    <div class="field"><label>Email</label><div class="input-suffix-wrap"><input type="text" id="lu" placeholder="joan.garcia" autocomplete="username"><span class="input-suffix">@innovatech.com</span></div></div>
    <div class="field"><label>Contrasenya</label><input type="password" id="lp" placeholder="••••••••" autocomplete="current-password"></div>
    <button class="btn btn-primary" style="width:100%" onclick="doLogin()">Accedir →</button>
    <div class="login-error" id="lerr"></div>
  </div>
</div>

<div id="app">
  <aside class="sidebar">
    <div class="sidebar-logo">
      <div class="logo-tag">Sistema de gestió</div>
      <h1>InnovateTech</h1>
    </div>
    <nav class="sidebar-nav" id="sidebar-nav"></nav>
    <div class="sidebar-footer">
      <div class="user-badge">
        <div class="user-avatar" id="uavatar">A</div>
        <div class="user-info">
          <div class="user-name" id="uname">–</div>
          <div class="user-rol" id="urol">–</div>
        </div>
      </div>
      <button class="btn btn-ghost" style="width:100%;justify-content:center" onclick="doLogout()">Tancar sessió</button>
    </div>
  </aside>
  <main class="main"><div id="content"></div></main>
</div>

<!-- EDIT MODAL -->
<div class="modal-overlay" id="modal">
  <div class="modal">
    <div class="modal-title" id="modal-title">Registre</div>
    <div class="modal-grid" id="modal-fields"></div>
    <div class="modal-actions">
      <button class="btn btn-ghost" onclick="closeModal()">Cancel·lar</button>
      <button class="btn btn-primary" onclick="saveModal()">Desar</button>
    </div>
  </div>
</div>

<!-- VIEW MODAL -->
<div class="modal-overlay" id="view-modal">
  <div class="modal">
    <div class="modal-title" id="view-title">Registre</div>
    <div class="view-list" id="view-fields"></div>
    <div class="modal-actions"><button class="btn btn-ghost" onclick="closeView()">Tancar</button></div>
  </div>
</div>

<!-- VIDEO PLAYER MODAL -->
<div class="modal-overlay" id="video-modal">
  <div class="modal video-modal-inner" style="width:720px;padding:0">
    <div style="padding:20px 28px;display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid var(--border)">
      <div class="modal-title" id="vp-title" style="margin:0;font-size:18px">Vídeo</div>
      <button class="btn btn-ghost btn-sm" onclick="closeVideoPlayer()">Tancar ✕</button>
    </div>
    <div class="video-player-wrap" id="vp-wrap"></div>
    <div id="vp-desc" style="padding:16px 28px;font-family:var(--mono);font-size:13px;color:var(--muted);line-height:1.6"></div>
  </div>
</div>

<!-- RATING MODAL -->
<div class="modal-overlay" id="rating-modal">
  <div class="modal" style="width:440px">
    <div class="modal-title">Valora la trucada <span id="rating-id"></span></div>
    <div class="field"><label>Puntuació</label><div class="star-rating" id="stars">
      <span onclick="setStar(1)">★</span><span onclick="setStar(2)">★</span>
      <span onclick="setStar(3)">★</span><span onclick="setStar(4)">★</span>
      <span onclick="setStar(5)">★</span>
    </div></div>
    <div class="field"><label>Comentari (opcional)</label><textarea id="rating-comment" placeholder="Com ha anat la trucada?"></textarea></div>
    <div class="modal-actions">
      <button class="btn btn-ghost" onclick="closeRating()">Ara no</button>
      <button class="btn btn-primary" onclick="submitRating()">Enviar valoració</button>
    </div>
  </div>
</div>

<!-- NEW CALL MODAL -->
<div class="modal-overlay" id="newcall-modal">
  <div class="modal" style="width:480px">
    <div class="modal-title">Nova trucada <span>selecciona un usuari</span></div>
    <div class="user-list" id="newcall-list"></div>
    <div class="modal-actions"><button class="btn btn-ghost" onclick="closeNewCall()">Cancel·lar</button></div>
  </div>
</div>

<!-- INCOMING CALL BANNER -->
<div id="incoming-banner" style="display:none">
  <div class="incoming-pulse">📞</div>
  <div class="incoming-info">
    <div class="incoming-label">Trucada entrant de</div>
    <div class="incoming-caller" id="incoming-name">—</div>
  </div>
  <div class="incoming-actions">
    <button class="btn-accept" onclick="acceptCall()">✔ Acceptar</button>
    <button class="btn-decline" onclick="declineCall()">✕ Declinar</button>
  </div>
</div>

<div class="toast" id="toast"></div>
<script>
// ── CONFIGURACIÓ JITSI ───────────────────────────────────────
const JITSI_HOST='3.234.196.49'; // canvia per la IP/domini del teu servidor Jitsi
const RING_URL='https://www.myinstants.com/media/sounds/john-pork-is-calling-moooort.mp3'; // URL del so de truca entrant (mp3/wav/ogg). Deixa buit per usar el to generat.

// ── STATE ─────────────────────────────────────────────────────
let currentTable,currentCols=[],currentRows=[],currentReadonly=false;
let editingId,editingPk,jitsiApi,currentCallId,ratingCallId,currentStars=0;
let activeNavId='';
let pollTimer=null,incomingCallData=null;
let ringCtx=null,ringTimer=null;

// ── RINGTONE ──────────────────────────────────────────────────
let ringAudio=null;
function startRingtone(){
  stopRingtone();
  if(RING_URL){
    ringAudio=new Audio(RING_URL);
    ringAudio.loop=true;
    ringAudio.volume=0.8;
    ringAudio.play().catch(()=>{});
  }else{
    function ring(){
      try{
        const ctx=new(window.AudioContext||window.webkitAudioContext)();
        [440,480].forEach(freq=>{
          const osc=ctx.createOscillator();
          const gain=ctx.createGain();
          osc.connect(gain);gain.connect(ctx.destination);
          osc.frequency.value=freq;
          gain.gain.setValueAtTime(0.25,ctx.currentTime);
          gain.gain.exponentialRampToValueAtTime(0.001,ctx.currentTime+0.9);
          osc.start();osc.stop(ctx.currentTime+0.9);
        });
        setTimeout(()=>ctx.close(),1100);
      }catch(e){}
    }
    ring();
    ringTimer=setInterval(ring,2000);
  }
}
function stopRingtone(){
  if(ringAudio){ringAudio.pause();ringAudio.currentTime=0;ringAudio=null;}
  if(ringTimer){clearInterval(ringTimer);ringTimer=null;}
}

// ── POLLING TRUCADES ENTRANTS ─────────────────────────────────
function startPolling(){
  if(pollTimer)return;
  checkIncomingCalls();
  pollTimer=setInterval(checkIncomingCalls,4000);
}
function stopPolling(){
  if(pollTimer){clearInterval(pollTimer);pollTimer=null;}
}
async function checkIncomingCalls(){
  if(incomingCallData)return;
  if(jitsiApi)return;
  try{
    const r=await post({action:'trucada_entrant'});
    console.debug('[poll]',r);
    if(r.ok&&r.trucada){
      incomingCallData=r.trucada;
      document.getElementById('incoming-name').textContent=r.trucada.nom_orig;
      document.getElementById('incoming-banner').style.display='flex';
      startRingtone();
    }
  }catch(e){console.error('[poll error]',e);}
}
async function acceptCall(){
  if(!incomingCallData)return;
  stopRingtone();
  const callId=incomingCallData.id_trucada;
  document.getElementById('incoming-banner').style.display='none';
  incomingCallData=null;
  setActive('videocall');
  await showVideoSection();
  const room='InnovateTech-Call-'+callId;
  const myName=document.getElementById('uname').textContent;
  document.getElementById('call-setup').style.display='none';
  const wrap=document.getElementById('jitsi-wrap');
  wrap.style.display='block';
  loadJitsiScript(()=>initJitsi(room,myName,wrap,callId));
}
async function declineCall(){
  if(!incomingCallData)return;
  stopRingtone();
  const callId=incomingCallData.id_trucada;
  document.getElementById('incoming-banner').style.display='none';
  incomingCallData=null;
  await post({action:'finalitzar_trucada',id:callId});
  toast('Trucada declinada','error');
}

// ── LOGIN / LOGOUT ────────────────────────────────────────────
async function doLogin(){
  let u=document.getElementById('lu').value.trim();
  if(u&&!u.includes('@')) u=u+'@innovatech.com';
  const p=document.getElementById('lp').value;
  const err=document.getElementById('lerr');
  err.style.display='none';
  try{
    const r=await post({action:'login',username:u,password:p});
    if(r&&r.ok){
      document.getElementById('login-screen').style.display='none';
      document.getElementById('app').style.display='block';
      document.getElementById('uname').textContent=r.nom_complet;
      document.getElementById('uname').dataset.uid=r.uid||'';
      document.getElementById('urol').textContent=r.rol;
      document.getElementById('uavatar').textContent=r.nom_complet?r.nom_complet[0].toUpperCase():'U';
      startPolling();
      buildSidebarWithTables(r.rol).catch(()=>{});
      showDashboard();
    }else{err.textContent=r.msg||'Error desconegut';err.style.display='block';}
  }catch{err.textContent='Error de connexió';err.style.display='block';}
}
async function doLogout(){stopPolling();await post({action:'logout'});location.reload();}
document.getElementById('lp').addEventListener('keydown',e=>{if(e.key==='Enter')doLogin();});

// ── SIDEBAR ───────────────────────────────────────────────────
function buildSidebar(rol){
  const nav=document.getElementById('sidebar-nav');
  const general=[{id:'dashboard',label:'🏠 Dashboard',fn:'showDashboard()'}];
  if(rol!=='administracio'){
    general.push({id:'videocall',label:'📹 Videoconferència',fn:'showVideoSection()'});
    general.push({id:'trucades', label:'📞 Historial trucades',fn:'showCallHistory()'});
  }
  general.push({id:'videos',label:'🎬 Catàleg de vídeo',fn:'showVideoCatalog()'});
  let html=renderSection('General',general);
  if(['admin','administracio'].includes(rol)){
    const adminItems=[{id:'bloqueig',label:'🔒 Bloqueig d\'usuaris',fn:'showUserBlocking()'}];
    if(rol==='admin'){
      adminItems.push({id:'banda',   label:'📶 Amplada de banda',  fn:'showBandwidth()'});
      adminItems.push({id:'avisos',  label:'🔔 Avisos / Auditoria',fn:'showAvisos()'});
      adminItems.push({id:'backups', label:'💾 Backups',            fn:'showBackups()'});
    }
    html+=renderSection('Administració',adminItems);
  }
  const tables=await_tables_placeholder();
  if(tables.length){
    html+='<div class="nav-divider"></div><span class="nav-section-label">Base de dades</span>';
    html+=tables.map(t=>`<button class="nav-item" id="nav-crud-${t}" onclick="showCrudTable('${t}',this)"><span class="nav-dot"></span>${t}</button>`).join('');
  }
  nav.innerHTML=html;
}
function renderSection(label,items){
  return `<span class="nav-section-label">${label}</span>`
    +items.map(i=>`<button class="nav-item" id="nav-${i.id}" onclick="setActive('${i.id}');${i.fn}"><span class="nav-dot"></span>${i.label}</button>`).join('');
}
// tables loaded async after sidebar renders
async function buildSidebarWithTables(rol){
  buildSidebar(rol);
  const tables=await post({action:'tables'});
  if(!Array.isArray(tables)){location.reload();return;}
  if(tables.length){
    const nav=document.getElementById('sidebar-nav');
    let extra='<div class="nav-divider"></div><span class="nav-section-label">Base de dades</span>';
    const tableEmoji={DEPARTAMENT:'🏢',EMPLEAT:'👤',USUARI:'👥',ROL:'🎭',USUARI_ROL:'🔑',GRUP_QUALITAT:'✅',TRUCADA:'📞',VIDEO:'🎬',MESURA_AMPLADA_BANDA:'📶',CONFIGURACIO_SERVIDOR:'⚙️',AVIS:'🔔',CONTROL_BACKUP:'💾',CONTRASENYES:'🔐'};
    extra+=tables.map(t=>`<button class="nav-item" id="nav-crud-${t}" onclick="setActive('nav-crud-${t}');showCrudTable('${t}',this)"><span class="nav-dot"></span>${(tableEmoji[t]||'🗄️')+' '+t}</button>`).join('');
    nav.innerHTML=nav.innerHTML.replace('@@TABLES@@','');
    nav.insertAdjacentHTML('beforeend',extra);
  }
}
function await_tables_placeholder(){return [];}
function setActive(id){
  document.querySelectorAll('.nav-item').forEach(b=>b.classList.remove('active'));
  const el=document.getElementById('nav-'+id)||document.getElementById(id);
  if(el)el.classList.add('active');
  activeNavId=id;
}

// ── DASHBOARD ─────────────────────────────────────────────────
async function showDashboard(){
  setActive('dashboard');
  if(jitsiApi){jitsiApi.dispose();jitsiApi=null;}
  document.getElementById('content').innerHTML='<div class="loading"><div class="spinner"></div> Carregant...</div>';
  const s=await post({action:'dashboard_stats'});
  const uname=document.getElementById('uname').textContent;
  const rol  =document.getElementById('urol').textContent;

  let cards=`
    <div class="card"><div class="card-label">Trucades pròpies</div><div class="card-value">${s.trucades}</div></div>
    <div class="card"><div class="card-label">Vídeos disponibles</div><div class="card-value">${s.videos}</div></div>`;
  if(s.usuaris!==undefined) cards+=`<div class="card"><div class="card-label">Usuaris actius</div><div class="card-value">${s.usuaris}</div></div>`;
  if(s.empleats!==undefined) cards+=`<div class="card"><div class="card-label">Empleats</div><div class="card-value">${s.empleats}</div></div>`;
  if(s.alertes!==undefined) cards+=`<div class="card ${s.alertes>0?'card-alert':''}"><div class="card-label">Alertes de banda</div><div class="card-value">${s.alertes}</div></div>`;
  if(s.clients!==undefined) cards+=`<div class="card"><div class="card-label">Clients actius</div><div class="card-value">${s.clients}</div></div>`;
  if(s.backup_data) cards+=`<div class="card"><div class="card-label">Últim backup</div><div class="card-value sm">${fmtDate(s.backup_data)}<br><span style="font-size:11px;color:${s.backup_ok==='èxit'?'var(--success)':'var(--danger)'}">${s.backup_ok}</span></div></div>`;

  let actions=`<button class="btn btn-ghost btn-sm" onclick="setActive('videos');showVideoCatalog()">Catàleg de vídeo</button>`;
  if(rol!=='administracio')
    actions=`<button class="btn btn-info btn-sm" onclick="setActive('videocall');showVideoSection()">Nova videotrucada</button>`+actions;
  if(rol==='admin')
    actions+=`<button class="btn btn-ghost btn-sm" onclick="setActive('banda');showBandwidth()">Amplada de banda</button>`;

  document.getElementById('content').innerHTML=`
    <div class="page-header">
      <div class="page-title-wrap">
        <div class="breadcrumb">InnovateTech · CPD</div>
        <h2 class="page-title">Benvingut, ${uname}</h2>
      </div>
      <div class="toolbar">${actions}</div>
    </div>
    <div class="cards-grid">${cards}</div>`;
}

// ── VIDEOCONFERÈNCIA ──────────────────────────────────────────
async function showVideoSection(){
  setActive('videocall');
  if(jitsiApi){jitsiApi.dispose();jitsiApi=null;}
  currentTable=null;
  const sfx=Math.random().toString(36).substr(2,6).toUpperCase();
  document.getElementById('content').innerHTML=`
    <div class="page-header">
      <div class="page-title-wrap"><div class="breadcrumb">InnovateTech · Comunicació</div><h2 class="page-title">Videoconferència</h2></div>
      <div class="toolbar">
        <button class="btn btn-accent2 btn-sm" onclick="openNewCallModal()">Trucada a usuari →</button>
      </div>
    </div>
    <div class="table-card">
      <div id="call-setup" class="jitsi-setup">
        <div class="jitsi-icon">📹</div>
        <h3>Sala de videoconferència</h3>
        <p>Crea o uneix-te a una sala lliure. Per registrar la trucada amb un usuari del sistema, usa el botó "Trucada a usuari".</p>
        <div class="field" style="width:100%;max-width:380px;text-align:left">
          <label>Nom de la sala</label>
          <input type="text" id="room-name" value="InnovateTech-${sfx}">
        </div>
        <button class="btn btn-primary" style="width:auto;padding:14px 36px" onclick="startCall()">Iniciar sala →</button>
      </div>
      <div id="jitsi-wrap" style="display:none;background:#000;border-radius:0 0 var(--radius) var(--radius);overflow:hidden"></div>
    </div>`;
}

function loadJitsiScript(cb){
  if(typeof JitsiMeetExternalAPI!=='undefined'){cb();return;}
  let tag=document.querySelector('script[data-jitsi]');
  if(tag){tag.addEventListener('load',cb);return;}
  tag=document.createElement('script');
  tag.src=`https://${JITSI_HOST}/external_api.js`;
  tag.setAttribute('data-jitsi','1');
  tag.onload=cb;
  document.head.appendChild(tag);
}

function startCall(){
  const room=(document.getElementById('room-name').value.trim()||'InnovateTech-'+Date.now()).replace(/\s+/g,'-');
  const uname=document.getElementById('uname').textContent;
  document.getElementById('call-setup').style.display='none';
  const wrap=document.getElementById('jitsi-wrap');
  wrap.style.display='block';
  loadJitsiScript(()=>initJitsi(room,uname,wrap,null));
}

async function openNewCallModal(){
  const users=await post({action:'usuaris_llista'});
  if(!Array.isArray(users)||!users.length){toast('No hi ha usuaris disponibles','error');return;}
  document.getElementById('newcall-list').innerHTML=users.map(u=>`
    <div class="user-list-item" onclick="startCallWith(${u.id_usuari},'${u.nom_complet.replace(/'/g,"\\'")}','${u.tipus}')">
      <div><div class="uname">${u.nom_complet}</div><div class="uemail">${u.email}</div></div>
      <span class="badge ${u.tipus==='extern'?'badge-muted':'badge-accent'}">${u.tipus==='extern'?'Client extern':'Intern'}</span>
    </div>`).join('');
  document.getElementById('newcall-modal').classList.add('open');
}

async function startCallWith(uid,uname,tipus){
  try{
    closeNewCall();
    const r=await post({action:'iniciar_trucada',destinatari_id:uid});
    if(!r.ok){toast(r.msg||'Error en registrar la trucada','error');return;}
    currentCallId=r.id;
    const room='InnovateTech-Call-'+r.id;
    const jitsiUrl=`https://${JITSI_HOST}/${room}`;
    const myName=document.getElementById('uname').textContent;
    await showVideoSection();
    document.getElementById('call-setup').style.display='none';
    const wrap=document.getElementById('jitsi-wrap');
    wrap.style.display='block';
    if(tipus==='extern'){
      let shareBar=document.getElementById('share-link-bar');
      if(!shareBar){
        shareBar=document.createElement('div');
        shareBar.id='share-link-bar';
        shareBar.style.cssText='background:var(--bg3);border:1px solid var(--accent);border-radius:var(--radius);padding:12px 16px;margin-bottom:12px;display:flex;align-items:center;gap:12px;flex-wrap:wrap';
        wrap.parentNode.insertBefore(shareBar,wrap);
      }
      shareBar.innerHTML=`<span style="font-size:13px;color:var(--muted)">Comparteix amb <strong style="color:var(--fg)">${uname}</strong>:</span>
        <code style="flex:1;font-size:12px;font-family:var(--mono);background:var(--bg);padding:6px 10px;border-radius:6px;border:1px solid var(--border);word-break:break-all">${jitsiUrl}</code>
        <button class="btn btn-sm btn-accent" onclick="navigator.clipboard.writeText('${jitsiUrl}').then(()=>toast('Enllaç copiat!'))">Copiar</button>`;
    }
    loadJitsiScript(()=>initJitsi(room,myName,wrap,r.id));
  }catch(e){
    console.error('[startCallWith]',e);
    toast('Error inesperat: '+e.message,'error');
  }
}

function initJitsi(room,uname,container,callId){
  if(jitsiApi){jitsiApi.dispose();jitsiApi=null;}
  jitsiApi=new JitsiMeetExternalAPI(JITSI_HOST,{
    roomName:room,width:'100%',height:620,parentNode:container,
    userInfo:{displayName:uname},
    configOverwrite:{startWithAudioMuted:true,startWithVideoMuted:false,prejoinPageEnabled:false,disableDeepLinking:true},
    interfaceConfigOverwrite:{SHOW_JITSI_WATERMARK:false,SHOW_WATERMARK_FOR_GUESTS:false,
      TOOLBAR_BUTTONS:['microphone','camera','desktop','chat','tileview','fullscreen','hangup']},
  });
  jitsiApi.addEventListener('readyToClose',async()=>{
    jitsiApi.dispose();jitsiApi=null;
    const sb=document.getElementById('share-link-bar');
    if(sb)sb.remove();
    if(callId){
      await post({action:'finalitzar_trucada',id:callId});
      openRatingModal(callId);
    }else showVideoSection();
  });
}

// ── CALL HISTORY ──────────────────────────────────────────────
async function showCallHistory(){
  setActive('trucades');
  if(jitsiApi){jitsiApi.dispose();jitsiApi=null;}
  document.getElementById('content').innerHTML='<div class="loading"><div class="spinner"></div> Carregant...</div>';
  const resp=await post({action:'trucades'});
  if(resp&&resp.audit){toast(resp.msg,'error');document.getElementById('content').innerHTML=`<div class="page-header"><div class="page-title-wrap"><div class="breadcrumb">InnovateTech · Comunicació</div><h2 class="page-title">Historial de trucades</h2></div></div><div class="empty"><div class="empty-icon">🚫</div><div class="empty-text">${resp.msg}</div></div>`;return;}
  const rows=Array.isArray(resp)?resp:[];
  const myName=document.getElementById('uname').textContent;
  document.getElementById('content').innerHTML=`
    <div class="page-header">
      <div class="page-title-wrap"><div class="breadcrumb">InnovateTech · Comunicació</div><h2 class="page-title">Historial de trucades</h2></div>
    </div>
    <div class="table-card">
      <div class="table-wrap">
      ${!rows.length?'<div class="empty"><div class="empty-icon">📞</div><div class="empty-text" style="font-family:var(--mono)">Sense trucades registrades</div></div>':`
      <table><thead><tr>
        <th>Data</th><th>Origen</th><th>Destí</th><th>Durada</th><th>Qualitat</th><th>Valoració</th><th>Accions</th>
      </tr></thead><tbody>
        ${rows.map(r=>`<tr>
          <td>${fmtDate(r.data_inici)}</td>
          <td>${r.nom_orig}</td>
          <td>${r.nom_dest}</td>
          <td>${r.durada_total?fmtDur(r.durada_total):'—'}</td>
          <td><span class="badge badge-muted">${r.qualitat}</span></td>
          <td>${r.puntuacio?starsHTML(r.puntuacio):'<span style="color:var(--muted);font-size:12px">Sense valorar</span>'}</td>
          <td><div class="actions-cell">
            ${!r.puntuacio?`<button class="btn btn-sm btn-success" onclick="openRatingModal(${r.id_trucada})">Valorar</button>`:''}
          </div></td>
        </tr>`).join('')}
      </tbody></table>`}
      </div>
    </div>`;
}

// ── VIDEO CATALOG ─────────────────────────────────────────────
function catEmoji(cat){
  if(!cat) return '🎬';
  const m={
    'formació':'📚','formacion':'📚','training':'📚','curs':'📚','curso':'📚',
    'producte':'📦','product':'📦','producció':'🎬','produccion':'🎬',
    'marketing':'📣','publicitat':'📣',
    'vendes':'💼','sales':'💼','comercial':'💼',
    'tecnologia':'💻','tech':'💻',' it ':'💻',
    'rrhh':'👥','recursos humans':'👥','hr':'👥','personal':'👥',
    'qualitat':'✅','quality':'✅',
    'seguretat':'🔒','security':'🔒',
    'notícies':'📰','noticies':'📰','news':'📰','comunicat':'📰',
    'presentació':'🎯','presentacion':'🎯','pitch':'🎯',
    'tutorial':'🎓','manual':'🎓','guia':'🎓',
    'reunió':'🤝','reunion':'🤝','meeting':'🤝',
    'esport':'⚽','sport':'⚽',
    'música':'🎵','musica':'🎵','music':'🎵',
  };
  const k=' '+cat.toLowerCase().trim()+' ';
  for(const [key,emoji] of Object.entries(m)) if(k.includes(key)) return emoji;
  return '🎬';
}
async function showVideoCatalog(q=''){
  setActive('videos');
  if(jitsiApi){jitsiApi.dispose();jitsiApi=null;}
  document.getElementById('content').innerHTML='<div class="loading"><div class="spinner"></div> Carregant...</div>';
  const videos=await post({action:'videos',search:q});
  document.getElementById('content').innerHTML=`
    <div class="page-header">
      <div class="page-title-wrap"><div class="breadcrumb">InnovateTech · Streaming</div><h2 class="page-title">Catàleg de vídeo</h2></div>
      <div class="toolbar">
        <div class="search-wrap"><input type="text" placeholder="Cercar per títol, categoria..." value="${q}" oninput="showVideoCatalog(this.value)" id="vsearch"></div>
      </div>
    </div>
    ${!videos.length
      ?'<div class="empty"><div class="empty-icon">📺</div><div class="empty-text" style="font-family:var(--mono)">Sense vídeos</div></div>'
      :`<div class="video-grid">${videos.map(v=>`
        <div class="video-card" onclick="openVideoPlayer('${v.enllac_streaming}','${v.titol.replace(/'/g,"\\'")}','${(v.descripcio||'').replace(/'/g,"\\'")}')">
          <div class="video-thumb">
            ${catEmoji(v.categoria)}
            <div class="video-thumb-overlay"><div class="video-play">▶</div></div>
          </div>
          <div class="video-info">
            <div class="video-title">${v.titol}</div>
            <div class="video-meta">
              ${v.categoria?`<span class="badge badge-accent">${catEmoji(v.categoria)} ${v.categoria}</span>`:''}
              ${v.durada?`<span class="badge badge-muted">${fmtDur(v.durada)}</span>`:''}
            </div>
          </div>
        </div>`).join('')}</div>`}`;
}

function openVideoPlayer(url,title,desc){
  document.getElementById('vp-title').textContent=title||'Vídeo';
  document.getElementById('vp-desc').textContent=desc||'';
  const wrap=document.getElementById('vp-wrap');
  const isHLS=/\.m3u8(\?.*)?$/i.test(url);
  const isMedia=/\.(mp4|webm|ogg)(\?.*)?$/i.test(url);
  if(isHLS){
    const vid=document.createElement('video');
    vid.controls=true;vid.autoplay=true;vid.style.cssText='width:100%;height:100%';
    wrap.innerHTML='';wrap.appendChild(vid);
    if(Hls.isSupported()){
      const hls=new Hls();
      hls.loadSource(url);hls.attachMedia(vid);
    }else if(vid.canPlayType('application/vnd.apple.mpegurl')){
      vid.src=url;
    }else{
      wrap.innerHTML='<p style="color:#fff;padding:16px">El navegador no suporta HLS.</p>';
    }
  }else if(isMedia){
    wrap.innerHTML=`<video controls autoplay style="width:100%;height:100%"><source src="${url}"><p style="color:#fff;padding:16px">No es pot reproduir el vídeo.</p></video>`;
  }else{
    wrap.innerHTML=`<iframe src="${url}" allowfullscreen allow="camera;microphone;autoplay"></iframe>`;
  }
  document.getElementById('video-modal').classList.add('open');
}
function closeVideoPlayer(){
  document.getElementById('video-modal').classList.remove('open');
  document.getElementById('vp-wrap').innerHTML='';
}


// ── ADMIN: BANDWIDTH ──────────────────────────────────────────
let bandaRefreshTimer=null;
async function executarMesura(){
  const btn=document.getElementById('btn-mesura');
  if(btn){btn.disabled=true;btn.textContent='Mesurant...';}
  const r=await post({action:'executar_mesura_banda'});
  toast(r.ok?r.msg:r.msg||'Error','error'in r||!r.ok?'error':'ok');
  if(r.ok){
    let t=60;
    bandaRefreshTimer=setInterval(()=>{
      t--;
      if(btn)btn.textContent=`Mesurant... (${t}s)`;
      if(t<=0){clearInterval(bandaRefreshTimer);bandaRefreshTimer=null;showBandwidth();}
    },1000);
  }else{if(btn){btn.disabled=false;btn.textContent='Executar mesura';}}
}
async function showBandwidth(){
  if(bandaRefreshTimer){clearInterval(bandaRefreshTimer);bandaRefreshTimer=null;}
  setActive('banda');
  if(jitsiApi){jitsiApi.dispose();jitsiApi=null;}
  document.getElementById('content').innerHTML='<div class="loading"><div class="spinner"></div> Carregant...</div>';
  const rows=await post({action:'mesures_banda'});
  if(rows.error){toast(rows.error,'error');return;}
  document.getElementById('content').innerHTML=`
    <div class="page-header">
      <div class="page-title-wrap"><div class="breadcrumb">InnovateTech · Administració</div><h2 class="page-title">Amplada de banda</h2></div>
      <div class="toolbar"><button id="btn-mesura" class="btn btn-accent btn-sm" onclick="executarMesura()">Executar mesura</button></div>
    </div>
    <div class="cards-grid" style="max-width:640px">
      <div class="card"><div class="card-label">Total mesures</div><div class="card-value">${rows.length}</div></div>
      <div class="card card-alert"><div class="card-label">No acceptables</div><div class="card-value">${rows.filter(r=>r.resultat==='no acceptable').length}</div></div>
    </div>
    <div class="table-card"><div class="table-wrap">
      ${!rows.length?'<div class="empty"><div class="empty-text" style="font-family:var(--mono)">Sense dades</div></div>':`
      <table><thead><tr>
        <th>Data</th><th>Equip</th><th>Baixada (Mbps)</th><th>Pujada (Mbps)</th><th>Latència (ms)</th><th>Resultat</th><th>Operari</th>
      </tr></thead><tbody>
        ${rows.map(r=>`<tr>
          <td>${fmtDate(r.data_hora)}</td><td>${r.usuari_equip_mesurat}</td>
          <td>${r.velocitat_baixada}</td><td>${r.velocitat_pujada}</td><td>${r.latencia}</td>
          <td><span class="badge ${r.resultat==='acceptable'?'badge-ok':'badge-err'}">${r.resultat}</span></td>
          <td>${r.nom_complet}</td>
        </tr>`).join('')}
      </tbody></table>`}
    </div></div>`;
}

// ── ADMIN: AVISOS ─────────────────────────────────────────────
async function showAvisos(){
  setActive('avisos');
  if(jitsiApi){jitsiApi.dispose();jitsiApi=null;}
  document.getElementById('content').innerHTML='<div class="loading"><div class="spinner"></div> Carregant...</div>';
  const rows=await post({action:'avisos_log'});
  if(rows.error){toast(rows.error,'error');return;}
  document.getElementById('content').innerHTML=`
    <div class="page-header">
      <div class="page-title-wrap"><div class="breadcrumb">InnovateTech · Administració</div><h2 class="page-title">Avisos / Auditoria</h2></div>
    </div>
    <div class="table-card"><div class="table-wrap">
      ${!rows.length?'<div class="empty"><div class="empty-text" style="font-family:var(--mono)">Sense avisos</div></div>':`
      <table><thead><tr><th>Data</th><th>Usuari</th><th>Taula</th><th>Operació</th><th>Detall</th></tr></thead><tbody>
        ${rows.map(r=>`<tr>
          <td>${fmtDate(r.data_hora)}</td><td>${r.nom_complet}</td>
          <td><span class="badge badge-accent">${r.taula_afectada}</span></td>
          <td><span class="badge badge-err">${r.operacio_intentada}</span></td>
          <td title="${r.detall||''}">${(r.detall||'—').substring(0,60)}${(r.detall||'').length>60?'…':''}</td>
        </tr>`).join('')}
      </tbody></table>`}
    </div></div>`;
}

// ── BLOQUEIG D'USUARIS ────────────────────────────────────────
async function showUserBlocking(){
  setActive('bloqueig');
  if(jitsiApi){jitsiApi.dispose();jitsiApi=null;}
  document.getElementById('content').innerHTML='<div class="loading"><div class="spinner"></div> Carregant...</div>';
  const rows=await post({action:'usuaris_gestio'});
  if(rows.error){toast(rows.error,'error');return;}
  const myId=parseInt(document.getElementById('uname').dataset.uid||0);
  document.getElementById('content').innerHTML=`
    <div class="page-header">
      <div class="page-title-wrap"><div class="breadcrumb">InnovateTech · Administració</div><h2 class="page-title">Bloqueig d'usuaris</h2></div>
    </div>
    <div class="table-card"><div class="table-wrap">
      <table><thead><tr><th>Nom</th><th>Email</th><th>Rol</th><th>Tipus</th><th>Estat</th><th>Acció</th></tr></thead><tbody>
        ${rows.map(r=>`<tr>
          <td>${r.nom_complet}</td>
          <td style="font-family:var(--mono);font-size:12px">${r.email}</td>
          <td><span class="badge badge-accent">${r.nom_rol||'—'}</span></td>
          <td><span class="badge badge-muted">${r.tipus}</span></td>
          <td><span class="badge ${r.estat==='actiu'?'badge-ok':'badge-err'}">${r.estat}</span></td>
          <td>${r.id_usuari==myId?'<span style="color:var(--muted);font-size:12px">—</span>':
            r.estat==='actiu'
              ?`<button class="btn btn-sm btn-danger" onclick="toggleBlock(${r.id_usuari},'bloquejar')">Bloquejar</button>`
              :`<button class="btn btn-sm btn-success" onclick="toggleBlock(${r.id_usuari},'desbloquejar')">Desbloquejar</button>`
          }</td>
        </tr>`).join('')}
      </tbody></table>
    </div></div>`;
}
async function toggleBlock(id,accio){
  const r=await post({action:accio==='bloquejar'?'bloquejar_usuari':'desbloquejar_usuari',id});
  if(r.ok){toast(accio==='bloquejar'?'Usuari bloquejat.':'Usuari desbloquejat.');showUserBlocking();}
  else toast(r.error||'Error','error');
}

// ── ADMIN: BACKUPS ────────────────────────────────────────────
async function showBackups(){
  setActive('backups');
  if(jitsiApi){jitsiApi.dispose();jitsiApi=null;}
  document.getElementById('content').innerHTML='<div class="loading"><div class="spinner"></div> Carregant...</div>';
  const rows=await post({action:'backups_log'});
  if(rows.error){toast(rows.error,'error');return;}
  document.getElementById('content').innerHTML=`
    <div class="page-header">
      <div class="page-title-wrap"><div class="breadcrumb">InnovateTech · Administració</div><h2 class="page-title">Historial de backups</h2></div>
    </div>
    <div class="table-card"><div class="table-wrap">
      ${!rows.length?'<div class="empty"><div class="empty-text" style="font-family:var(--mono)">Sense registres</div></div>':`
      <table><thead><tr><th>Data</th><th>Taules incloses</th><th>Resultat</th></tr></thead><tbody>
        ${rows.map(r=>`<tr>
          <td>${fmtDate(r.data_hora)}</td>
          <td style="max-width:320px">${r.taules_incloses}</td>
          <td><span class="badge ${r.resultat==='èxit'?'badge-ok':'badge-err'}">${r.resultat}</span></td>
        </tr>`).join('')}
      </tbody></table>`}
    </div></div>`;
}

// ── CRUD TABLE BROWSER ────────────────────────────────────────
async function showCrudTable(table,btn){
  if(jitsiApi){jitsiApi.dispose();jitsiApi=null;}
  document.querySelectorAll('.nav-item').forEach(b=>b.classList.remove('active'));
  if(btn)btn.classList.add('active');
  currentTable=table;
  document.getElementById('content').innerHTML='<div class="loading"><div class="spinner"></div> Carregant...</div>';
  const data=await post({action:'read',table});
  currentCols=data.cols||[];currentRows=data.rows||[];currentReadonly=data.readonly||false;
  renderTable();
}

function renderTable(rows){
  rows=rows||currentRows;
  const pk=currentCols[0];
  document.getElementById('content').innerHTML=`
    <div class="page-header">
      <div class="page-title-wrap"><div class="breadcrumb">InnovateTech · Base de dades</div><h2 class="page-title">${currentTable}</h2></div>
      <div class="toolbar">
        <div class="search-wrap"><input type="text" placeholder="Cercar..." oninput="doSearch(this.value)" id="search-input"></div>
        ${currentReadonly?'':`<button class="btn btn-primary btn-sm" onclick="openInsert()">+ Nou registre</button>`}
      </div>
    </div>
    <div class="cards-grid" style="max-width:640px">
      <div class="card"><div class="card-label">Total registres</div><div class="card-value">${rows.length}</div></div>
      <div class="card"><div class="card-label">Columnes</div><div class="card-value">${currentCols.length}</div></div>
    </div>
    <div class="table-card"><div class="table-wrap">
      ${rows.length===0
        ?'<div class="empty"><div class="empty-icon">◎</div><div class="empty-text" style="font-family:var(--mono)">Sense registres</div></div>'
        :`<table><thead><tr>${currentCols.map(c=>`<th>${c}</th>`).join('')}<th>Accions</th></tr></thead>
          <tbody>${rows.map(r=>`<tr>
            ${currentCols.map(c=>`<td title="${r[c]??''}">${renderCell(r[c])}</td>`).join('')}
            <td><div class="actions-cell">
              <button class="btn btn-sm btn-info" onclick='openView(${JSON.stringify(r)})'>Veure</button>
              ${!currentReadonly?`<button class="btn btn-sm btn-success" onclick='openEdit(${JSON.stringify(r)})'>Editar</button><button class="btn btn-sm btn-danger" onclick="deleteRow(${r[pk]},'${pk}')">Eliminar</button>`:''}
            </div></td>
          </tr>`).join('')}</tbody></table>`}
    </div></div>`;
}

async function doSearch(q){
  const data=await post({action:'read',table:currentTable,search:q});
  currentRows=data.rows||[];renderTable(currentRows);
  const i=document.getElementById('search-input');if(i){i.value=q;i.focus();}
}

// ── CRUD MODALS ───────────────────────────────────────────────
function openInsert(){editingId=null;editingPk=null;document.getElementById('modal-title').innerHTML=`Nou registre <span>${currentTable}</span>`;buildFields({});document.getElementById('modal').classList.add('open');}
function openEdit(row){editingPk=currentCols[0];editingId=row[editingPk];document.getElementById('modal-title').innerHTML=`Editar <span>#${editingId}</span>`;buildFields(row);document.getElementById('modal').classList.add('open');}
function buildFields(row){
  const fields=editingId?currentCols:currentCols.slice(1);
  document.getElementById('modal-fields').innerHTML=fields.map(c=>`
    <div class="field ${fields.length===1?'full':''}">
      <label>${c}</label>
      <input type="text" id="field-${c}" value="${(row[c]??'').toString().replace(/"/g,'&quot;')}" placeholder="${c}">
    </div>`).join('');
}
function closeModal(){document.getElementById('modal').classList.remove('open');}
async function saveModal(){
  const fields=editingId?currentCols:currentCols.slice(1);
  const data={};fields.forEach(c=>{data[c]=document.getElementById('field-'+c)?.value??'';});
  const r=editingId
    ?await post({action:'update',table:currentTable,id:editingId,pk:editingPk,data:JSON.stringify(data)})
    :await post({action:'insert',table:currentTable,data:JSON.stringify(data)});
  if(r.ok){closeModal();toast('Desat correctament','success');showCrudTable(currentTable);}
  else toast('Error: '+(r.error||''),'error');
}
async function deleteRow(id,pk){
  if(!confirm(`Eliminar el registre #${id}?`))return;
  const r=await post({action:'delete',table:currentTable,id,pk});
  if(r.ok){toast('Registre eliminat','success');showCrudTable(currentTable);}
  else toast('Error en eliminar','error');
}

// ── VIEW MODAL ────────────────────────────────────────────────
function openView(row){
  const pk=currentCols[0];
  document.getElementById('view-title').innerHTML=`Registre <span>${currentTable} #${row[pk]}</span>`;
  document.getElementById('view-fields').innerHTML=currentCols.map(c=>{
    const v=row[c];
    let html;
    if(v===null||v===undefined||v==='')html='<span style="color:var(--muted);font-style:italic">— buit —</span>';
    else if(isURL(String(v)))html=`<a class="view-link" href="${v}" target="_blank" rel="noopener">↗ ${v}</a>`;
    else html=String(v).replace(/</g,'&lt;').replace(/>/g,'&gt;');
    return `<div class="view-row"><div class="view-key">${c}</div><div class="view-val">${html}</div></div>`;
  }).join('');
  document.getElementById('view-modal').classList.add('open');
}
function closeView(){document.getElementById('view-modal').classList.remove('open');}

// ── RATING ────────────────────────────────────────────────────
function openRatingModal(callId){
  ratingCallId=callId;currentStars=0;
  document.getElementById('rating-id').textContent='#'+callId;
  document.getElementById('rating-comment').value='';
  setStar(0);
  document.getElementById('rating-modal').classList.add('open');
}
function setStar(n){
  currentStars=n;
  document.querySelectorAll('#stars span').forEach((s,i)=>{s.classList.toggle('on',i<n);});
}
async function submitRating(){
  if(!currentStars){toast("Selecciona una puntuació",'error');return;}
  const r=await post({action:'valorar_trucada',id:ratingCallId,puntuacio:currentStars,comentari:document.getElementById('rating-comment').value});
  if(r.ok){closeRating();toast('Valoració enviada','success');}
  else toast('Error en valorar','error');
}
function closeRating(){document.getElementById('rating-modal').classList.remove('open');showVideoSection();}

// ── NEW CALL MODAL ────────────────────────────────────────────
function closeNewCall(){document.getElementById('newcall-modal').classList.remove('open');}

// ── UTILS ─────────────────────────────────────────────────────
function isURL(v){return typeof v==='string'&&/^https?:\/\/.+/i.test(v.trim());}
function renderCell(v){
  if(v===null||v===undefined||v==='')return '<span style="color:var(--muted)">—</span>';
  const s=String(v);
  if(isURL(s))return `<a class="cell-link" href="${s}" target="_blank" rel="noopener" title="${s}">${s}</a>`;
  return s.replace(/</g,'&lt;').replace(/>/g,'&gt;');
}
function fmtDur(s){if(!s)return '—';const m=Math.floor(s/60),sec=s%60;return m>0?`${m}m ${sec}s`:`${sec}s`;}
function fmtDate(d){if(!d)return '—';return new Date(d).toLocaleString('ca-ES',{day:'2-digit',month:'2-digit',year:'numeric',hour:'2-digit',minute:'2-digit'});}
function starsHTML(n){return '<span class="stars">'+('★'.repeat(n))+'</span><span class="stars muted">'+('★'.repeat(5-n))+'</span>';}
async function post(data){
  const fd=new FormData();
  for(const[k,v]of Object.entries(data))fd.append(k,v);
  return (await fetch('',{method:'POST',body:fd})).json();
}
function toast(msg,type='success'){
  const el=document.getElementById('toast');
  el.textContent=msg;el.className=`toast ${type} show`;
  setTimeout(()=>el.classList.remove('show'),3000);
}

// close modals on backdrop click
['modal','view-modal','video-modal','rating-modal','newcall-modal'].forEach(id=>{
  document.getElementById(id).addEventListener('click',e=>{
    if(e.target===document.getElementById(id)){
      document.getElementById(id).classList.remove('open');
      if(id==='video-modal')document.getElementById('vp-wrap').innerHTML='';
    }
  });
});
</script>
</body>
</html>
