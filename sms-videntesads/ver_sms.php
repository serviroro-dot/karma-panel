<?php
/**
 * ══════════════════════════════════════════════════════════════════
 *  VER LOS SMS EN VIVO — pantalla de seguimiento
 * ══════════════════════════════════════════════════════════════════
 *
 *  QUÉ HACE
 *  Se conecta a tu cuenta de Twilio y te enseña los mensajes según van
 *  saliendo: cuántos van, cuáles han llegado, cuáles han fallado y por
 *  qué. Se actualiza sola cada 5 segundos. También avisa si detecta
 *  DUPLICADOS (el mismo texto al mismo número con segundos de
 *  diferencia), que es justo el fallo que estamos persiguiendo.
 *
 *  CÓMO SE USA
 *  1. Rellena aquí abajo la CLAVE (una tuya) y los datos de Twilio.
 *  2. Súbelo a tu web con el gestor de archivos del hosting.
 *  3. Ábrelo en Chrome:
 *       https://videntesads.com/ver_sms.php?clave=TU_CLAVE
 *  4. Déjalo abierto mientras se manda la tanda: se refresca solo.
 *
 *  Solo LEE. No envía nada, no cambia nada, no puede romper nada.
 * ══════════════════════════════════════════════════════════════════
 */

// ── RELLENA ESTO ──────────────────────────────────────────────────
$CLAVE       = 'CAMBIA_ESTA_CLAVE';

// Los dos primeros datos de https://console.twilio.com (página de inicio):
$ACCOUNT_SID = 'CAMBIAR_ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx';
$AUTH_TOKEN  = 'CAMBIAR_tu_auth_token';
// ──────────────────────────────────────────────────────────────────

// Solo para las pruebas automáticas; en tu servidor no existe y se usa Twilio.
$API_BASE = getenv('TWILIO_API_BASE') ?: 'https://api.twilio.com';

header('Content-Type: ' . (isset($_GET['json']) ? 'application/json' : 'text/html') . '; charset=utf-8');

/* ---------- Puerta ---------- */
$claveDada = isset($_REQUEST['clave']) ? (string) $_REQUEST['clave'] : '';

if ($CLAVE === 'CAMBIA_ESTA_CLAVE') {
    exit(pantallaError('Abre este archivo y rellena la CLAVE y los datos de Twilio (líneas 27-31).'));
}
if (!hash_equals($CLAVE, $claveDada)) {
    exit(pantallaError('Clave incorrecta. Abre la dirección con <code>?clave=TU_CLAVE</code> al final.'));
}
if (strpos($ACCOUNT_SID, 'CAMBIAR') === 0 || strpos($AUTH_TOKEN, 'CAMBIAR') === 0) {
    exit(pantallaError('Faltan los datos de Twilio. Están en la página de inicio de console.twilio.com: '
        . 'el Account SID (empieza por AC) y el Auth Token.'));
}

/* ---------- Hablar con Twilio ---------- */

function pedirATwilio($ruta)
{
    global $API_BASE, $ACCOUNT_SID, $AUTH_TOKEN;

    $ch = curl_init($API_BASE . $ruta);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => $ACCOUNT_SID . ':' . $AUTH_TOKEN,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    $resp   = curl_exec($ch);
    $codigo = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err    = curl_error($ch);
    curl_close($ch);

    if ($err !== '')      { return ['error' => 'Sin conexión con Twilio: ' . $err]; }
    $j = json_decode($resp, true);
    if ($codigo === 401)  { return ['error' => 'Twilio dice que el SID o el Token no son correctos.']; }
    if ($codigo >= 400)   { return ['error' => 'Twilio responde error ' . $codigo . ': ' . (isset($j['message']) ? $j['message'] : '')]; }
    if (!is_array($j))    { return ['error' => 'Respuesta rara de Twilio.']; }

    return $j;
}

/** Los últimos mensajes de la cuenta, ya digeridos para la pantalla. */
function traerMensajes()
{
    global $ACCOUNT_SID;

    $r = pedirATwilio('/2010-04-01/Accounts/' . rawurlencode($ACCOUNT_SID) . '/Messages.json?PageSize=100');
    if (isset($r['error'])) { return $r; }

    $lista = [];
    foreach ((isset($r['messages']) ? $r['messages'] : []) as $m) {
        $lista[] = [
            'a'      => isset($m['to']) ? $m['to'] : '',
            'texto'  => isset($m['body']) ? $m['body'] : '',
            'estado' => isset($m['status']) ? $m['status'] : '',
            'error'  => isset($m['error_message']) && $m['error_message'] !== null ? $m['error_message'] : '',
            'precio' => isset($m['price']) && $m['price'] !== null ? $m['price'] : '',
            'partes' => isset($m['num_segments']) ? (int) $m['num_segments'] : 1,
            'fecha'  => isset($m['date_created']) ? $m['date_created'] : '',
        ];
    }

    /* Recuento por estado */
    $cuenta = ['entregado' => 0, 'enviado' => 0, 'en_cola' => 0, 'fallido' => 0];
    foreach ($lista as $m) {
        switch ($m['estado']) {
            case 'delivered':                          $cuenta['entregado']++; break;
            case 'sent': case 'sending':               $cuenta['enviado']++;   break;
            case 'queued': case 'accepted':
            case 'scheduled':                          $cuenta['en_cola']++;   break;
            case 'failed': case 'undelivered':         $cuenta['fallido']++;   break;
        }
    }

    /* Duplicados: mismo número y mismo texto con menos de 2 minutos entre sí */
    $vistos = [];
    $duplicados = 0;
    foreach ($lista as $m) {
        $k = $m['a'] . '|' . $m['texto'];
        $t = strtotime($m['fecha']);
        if (isset($vistos[$k]) && abs($vistos[$k] - $t) < 120) { $duplicados++; }
        $vistos[$k] = $t;
    }

    return ['mensajes' => $lista, 'cuenta' => $cuenta, 'duplicados' => $duplicados];
}

/* ---------- Modo JSON (lo usa la propia pantalla para refrescarse) ---------- */

if (isset($_GET['json'])) {
    echo json_encode(traerMensajes(), JSON_UNESCAPED_UNICODE);
    exit;
}

/* ---------- Pantalla ---------- */

function pantallaError($msg)
{
    return plantilla('<div class="caja mal">' . $msg . '</div>');
}

function plantilla($cuerpo)
{
    return '<!doctype html><html lang="es"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>SMS en vivo</title><style>
:root{--papel:#f4f5f7;--caja:#fff;--tinta:#16181d;--suave:#5b6270;--linea:#e0e3e9;
      --acento:#8b2f52;--ok:#1f7a3d;--mal:#b3261e;--aviso:#8a6100}
@media(prefers-color-scheme:dark){:root{--papel:#12141a;--caja:#1b1e26;--tinta:#eceef2;
      --suave:#9aa2b1;--linea:#2b303b;--acento:#e08bab;--ok:#6cc98c;--mal:#f08a80;--aviso:#e0b45c}}
*{box-sizing:border-box}
body{margin:0;background:var(--papel);color:var(--tinta);font:16px/1.5 system-ui,-apple-system,sans-serif}
.env{max-width:760px;margin:0 auto;padding:24px 14px 64px}
h1{font-size:1.3rem;margin:0 0 2px}.sub{color:var(--suave);font-size:.9rem;margin:0 0 18px}
.caja{background:var(--caja);border:1px solid var(--linea);border-radius:12px;padding:15px 16px;margin-bottom:13px}
.caja.mal{border-left:4px solid var(--mal)}
.caja.dup{border-left:4px solid var(--mal);display:none}
.cifras{display:grid;grid-template-columns:repeat(auto-fit,minmax(88px,1fr));gap:9px}
.cifra{background:var(--papel);border:1px solid var(--linea);border-radius:9px;padding:10px;text-align:center}
.cifra b{display:block;font-size:1.45rem;line-height:1.2}
.cifra span{font-size:.76rem;color:var(--suave)}
.v-ok{color:var(--ok)}.v-mal{color:var(--mal)}.v-med{color:var(--aviso)}
table{width:100%;border-collapse:collapse;font-size:.86rem}
td{padding:7px 4px;border-bottom:1px solid var(--linea);vertical-align:top}
td.tel{font-family:ui-monospace,monospace;white-space:nowrap}
td.est{text-align:right;white-space:nowrap}
.txt{color:var(--suave);font-size:.8rem;max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.pie{color:var(--suave);font-size:.8rem;text-align:center;margin-top:10px}
code{background:var(--papel);border:1px solid var(--linea);border-radius:4px;padding:1px 5px;font-size:.88em}
</style></head><body><div class="env">
<h1>SMS en vivo</h1>
<p class="sub">Se actualiza sola cada 5 segundos. Déjala abierta y mira cómo van saliendo.</p>
' . $cuerpo . '</div></body></html>';
}

$claveJs = json_encode($claveDada);

echo plantilla(<<<HTML
<div class="caja dup" id="cajaDup">
  <b>⚠ Hay <span id="nDup">0</span> mensajes DUPLICADOS</b> — el mismo texto al mismo
  número con segundos de diferencia. El fallo del envío doble sigue vivo: cada uno
  de ésos se ha cobrado dos veces.
</div>

<div class="caja">
  <div class="cifras">
    <div class="cifra"><b id="nEntregados" class="v-ok">–</b><span>entregados</span></div>
    <div class="cifra"><b id="nEnviados" class="v-med">–</b><span>enviados</span></div>
    <div class="cifra"><b id="nCola">–</b><span>en cola</span></div>
    <div class="cifra"><b id="nFallidos" class="v-mal">–</b><span>fallidos</span></div>
  </div>
</div>

<div class="caja">
  <table><tbody id="tabla"><tr><td>Cargando…</td></tr></tbody></table>
  <p class="pie" id="pie"></p>
</div>

<script>
(function () {
  'use strict';

  var CLAVE = {$claveJs};
  var ticks = 0;

  function esc(s) {
    return String(s).replace(/[&<>"]/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
    });
  }

  function estadoBonito(e, err) {
    if (e === 'delivered')                  { return '<span class="v-ok">✓ entregado</span>'; }
    if (e === 'sent' || e === 'sending')    { return '<span class="v-med">→ enviado</span>'; }
    if (e === 'queued' || e === 'accepted') { return 'en cola'; }
    if (e === 'failed' || e === 'undelivered') {
      return '<span class="v-mal">✗ ' + esc(err || 'fallido') + '</span>';
    }
    return esc(e);
  }

  function pinta(d) {
    if (d.error) {
      document.getElementById('tabla').innerHTML =
        '<tr><td class="v-mal">' + esc(d.error) + '</td></tr>';
      return;
    }

    document.getElementById('nEntregados').textContent = d.cuenta.entregado;
    document.getElementById('nEnviados').textContent   = d.cuenta.enviado;
    document.getElementById('nCola').textContent       = d.cuenta.en_cola;
    document.getElementById('nFallidos').textContent   = d.cuenta.fallido;

    var dup = document.getElementById('cajaDup');
    if (d.duplicados > 0) {
      document.getElementById('nDup').textContent = d.duplicados;
      dup.style.display = 'block';
    } else {
      dup.style.display = 'none';
    }

    var filas = '';
    for (var i = 0; i < Math.min(d.mensajes.length, 40); i++) {
      var m = d.mensajes[i];
      filas += '<tr><td class="tel">' + esc(m.a) + '<div class="txt">' + esc(m.texto) + '</div></td>'
             + '<td class="est">' + estadoBonito(m.estado, m.error)
             + (m.partes > 1 ? '<div class="txt">' + m.partes + ' partes (se cobra x' + m.partes + ')</div>' : '')
             + '</td></tr>';
    }
    document.getElementById('tabla').innerHTML = filas || '<tr><td>No hay mensajes todavía.</td></tr>';
    document.getElementById('pie').textContent =
      'Últimos ' + Math.min(d.mensajes.length, 40) + ' mensajes · actualizado hace 0 s';
  }

  function mirar() {
    fetch('?json=1&clave=' + encodeURIComponent(CLAVE))
      .then(function (r) { return r.json(); })
      .then(pinta)
      .catch(function () { /* un fallo de red puntual: se reintenta en el siguiente tick */ });
  }

  setInterval(function () {
    ticks++;
    var pie = document.getElementById('pie');
    if (pie.textContent) {
      pie.textContent = pie.textContent.replace(/hace \d+ s/, 'hace ' + (ticks % 5) + ' s');
    }
    if (ticks % 5 === 0) { mirar(); }
  }, 1000);

  mirar();
})();
</script>
HTML);
