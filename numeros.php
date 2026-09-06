<?php
session_start();
$HASHFILE = '/var/lib/numeros_clientes/clave.hash';
$PASS_HASH = is_readable($HASHFILE) ? trim(file_get_contents($HASHFILE)) : '';
$FILE = '/var/lib/numeros_clientes/numeros.csv';
if (isset($_GET['salir'])) { unset($_SESSION['num_ok']); header('Location: numeros.php'); exit; }
if (empty($_SESSION['num_ok'])) {
  $err='';
  if (isset($_POST['clave'])) {
    if ($PASS_HASH !== '' && password_verify($_POST['clave'], $PASS_HASH)) { $_SESSION['num_ok']=true; header('Location: numeros.php'); exit; }
    else { sleep(2); $err='Contrasena incorrecta'; }
  }
  echo '<!doctype html><meta charset="utf-8"><title>Numeros</title><style>body{font-family:system-ui;background:#f4f6f8;display:flex;align-items:center;justify-content:center;height:100vh;margin:0}form{background:#fff;padding:32px;border-radius:10px;box-shadow:0 2px 12px #0002;min-width:280px}h2{margin:0 0 18px;color:#2c3e50}input{width:100%;padding:11px;margin-bottom:14px;border:1px solid #ccc;border-radius:6px;box-sizing:border-box}button{width:100%;padding:11px;background:#3498db;color:#fff;border:0;border-radius:6px;font-size:15px;cursor:pointer}.e{color:#c0392b;margin-bottom:10px}</style>';
  echo '<form method="post"><h2>Numeros de clientas</h2>';
  if ($err) echo '<div class="e">'.$err.'</div>';
  echo '<input type="password" name="clave" placeholder="Contrasena" autofocus><button>Entrar</button></form>';
  exit;
}
if (isset($_GET['descargar'])) {
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="numeros_clientas_'.date('Y-m-d').'.csv"');
  echo "numero;fecha\n";
  if (is_readable($FILE)) readfile($FILE);
  exit;
}
if (isset($_POST['borrar']) && $_POST['borrar']==='SI') {
  $DEL = '/var/lib/numeros_clientes/borrados.csv';
  $cur = is_readable($FILE) ? file_get_contents($FILE) : '';
  if ($cur !== '' && is_writable($DEL)) @file_put_contents($DEL, $cur, FILE_APPEND | LOCK_EX);
  @file_put_contents($FILE, '');
  header('Location: numeros.php?ok=1'); exit;
}
$l = is_readable($FILE) ? array_values(array_filter(file($FILE, FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES))) : [];
usort($l, function($a,$b){ $x=explode(';',$a); $y=explode(';',$b); $c=strcmp($y[1]??'',$x[1]??''); return $c!==0?$c:strcmp($y[0],$x[0]); });
echo '<!doctype html><meta charset="utf-8"><title>Numeros de clientas</title><style>body{font-family:system-ui;background:#f4f6f8;margin:0;padding:24px}h1{color:#2c3e50;margin:0 0 4px}.sub{color:#7f8c8d;margin-bottom:18px}.box{background:#fff;border-radius:10px;padding:20px;box-shadow:0 2px 10px #0001;max-width:820px;margin:auto}a.btn,button{display:inline-block;padding:10px 18px;border-radius:6px;border:0;text-decoration:none;font-size:14px;cursor:pointer;margin-right:8px}.g{background:#27ae60;color:#fff}.r{background:#c0392b;color:#fff}.s{background:#95a5a6;color:#fff}table{width:100%;border-collapse:collapse;margin-top:18px}th,td{padding:9px 10px;border-bottom:1px solid #eee;text-align:left;font-variant-numeric:tabular-nums}th{background:#ecf0f1}.n{font-size:28px;font-weight:600;color:#2980b9}</style>';
echo '<div class="box"><h1>Numeros de clientas</h1><div class="sub">Se actualiza solo cada hora</div>';
if (isset($_GET['ok'])) echo '<p style="color:#27ae60">Lista borrada correctamente.</p>';
echo '<div class="n">'.count($l).' numeros</div><p>';
echo '<a class="btn g" href="?descargar=1">Descargar Excel</a>';
echo '<form method="post" style="display:inline" onsubmit="return confirm(\'Se borraran todos los numeros del servidor. Esta accion no se puede deshacer. Continuar?\')"><input type="hidden" name="borrar" value="SI"><button class="r">Borrar todo</button></form>';
echo '<a class="btn s" href="?salir=1">Salir</a></p>';
echo '<table><tr><th>Numero</th><th>Fecha</th></tr>';
foreach ($l as $r) { $p = explode(';', $r); echo '<tr><td>'.htmlspecialchars($p[0]).'</td><td>'.htmlspecialchars($p[1] ?? '').'</td></tr>'; }
echo '</table></div>';
