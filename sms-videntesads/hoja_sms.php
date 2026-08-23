<?php
/**
 * ══════════════════════════════════════════════════════════════════
 *  HOJA DE SMS — una sola caja, sin fallos
 * ══════════════════════════════════════════════════════════════════
 *
 *  QUÉ ES
 *  La hoja nueva de SMS, completa, en un solo archivo:
 *    · UNA caja para escribir el texto (máximo 160, un SMS por persona).
 *    · Pegas los números, le das a Enviar y se van mandando uno a uno.
 *    · Ves en vivo los que van saliendo, los que LLEGARON (✓) y los
 *      que NO (✗ con el motivo).
 *    · A cada número le llega UNO. Los repetidos se quitan solos, y si
 *      recargas o se corta, sigue por donde iba sin repetir a nadie.
 *
 *  CÓMO SE PONE EN MARCHA
 *  1. Rellena la CLAVE y los tres datos de Twilio aquí abajo.
 *  2. Súbelo a tu web con el gestor de archivos del hosting.
 *  3. Ábrelo en Chrome:
 *       https://videntesads.com/hoja_sms.php?clave=TU_CLAVE
 *
 *  IMPORTANTE: la pestaña tiene que quedarse abierta mientras se envía
 *  (es quien va marcando el ritmo). Si la cierras, se pausa; al volver
 *  a abrirla te ofrece continuar donde iba.
 * ══════════════════════════════════════════════════════════════════
 */

// ── RELLENA ESTO ──────────────────────────────────────────────────
$CLAVE       = 'CAMBIA_ESTA_CLAVE';

// De la página de inicio de https://console.twilio.com :
$ACCOUNT_SID = 'CAMBIAR_ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx';
$AUTH_TOKEN  = 'CAMBIAR_tu_auth_token';

// Desde qué número sales: '+34600111222', o un Messaging Service 'MGxxxx...'
$REMITENTE   = 'CAMBIAR_+34600111222';

// País para los números que vengan sin prefijo: 34 España, 1 EEUU.
$PREFIJO     = '34';
// ──────────────────────────────────────────────────────────────────

$API_BASE = getenv('TWILIO_API_BASE') ?: 'https://api.twilio.com';   // solo pruebas
$CARPETA  = __DIR__ . '/sms_datos';
$ESTADO   = $CARPETA . '/estado.json';

/* ---------- Puerta ---------- */
session_start();
$claveDada = isset($_REQUEST['clave']) ? (string) $_REQUEST['clave'] : '';

if ($CLAVE === 'CAMBIA_ESTA_CLAVE') { exit(plantilla('<div class="caja mal">Abre este archivo y rellena la CLAVE y los datos de Twilio (arriba del todo).</div>')); }
if (!hash_equals($CLAVE, $claveDada)) { exit(plantilla('<div class="caja mal">Clave incorrecta. Abre la dirección con <code>?clave=TU_CLAVE</code> al final.</div>')); }

if (!is_dir($CARPETA)) {
    mkdir($CARPETA, 0755, true);
    // Que el navegador no pueda leer los datos directamente.
    @file_put_contents($CARPETA . '/.htaccess', "Require all denied\n");
    @file_put_contents($CARPETA . '/index.html', '');
}

/* ---------- El texto: siempre UN solo SMS ---------- */

function alfabetoGsm()
{
    return '@£$¥èéùìòÇ' . "\n" . 'Øø' . "\r"
         . 'ÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !"#¤%&\'()*+,-./0123456789:;<=>?'
         . '¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà';
}

function limpiarTexto($texto)
{
    $cambios = [
        'á'=>'a','í'=>'i','ó'=>'o','ú'=>'u','Á'=>'A','Í'=>'I','Ó'=>'O','Ú'=>'U',
        'â'=>'a','ê'=>'e','î'=>'i','ô'=>'o','û'=>'u','ã'=>'a','õ'=>'o','Ã'=>'A','Õ'=>'O',
        'ï'=>'i','Ï'=>'I','ë'=>'e','Ë'=>'E','ÿ'=>'y','ý'=>'y','ç'=>'c',
        '“'=>'"','”'=>'"','„'=>'"','«'=>'"','»'=>'"',
        '‘'=>"'",'’'=>"'",'‚'=>"'",'´'=>"'",'`'=>"'",
        '–'=>'-','—'=>'-','−'=>'-','…'=>'...','•'=>'-','·'=>'.','°'=>'o','º'=>'o','ª'=>'a',
        "\xC2\xA0"=>' ',
    ];
    $texto = strtr($texto, $cambios);

    $permitido = alfabetoGsm() . '^{}\\[~]|€';
    $salida = '';
    foreach (preg_split('//u', $texto, -1, PREG_SPLIT_NO_EMPTY) as $c) {
        if (mb_strpos($permitido, $c) !== false)  { $salida .= $c; }
        elseif (trim($c) === '')                  { $salida .= ' '; }
    }
    return trim(preg_replace('/ {2,}/', ' ', $salida));
}

function largoGsm($texto)
{
    $base = alfabetoGsm(); $ext = '^{}\\[~]|€'; $n = 0;
    foreach (preg_split('//u', $texto, -1, PREG_SPLIT_NO_EMPTY) as $c) {
        $n += (mb_strpos($ext, $c) !== false) ? 2 : 1;
    }
    return $n;
}

/* ---------- Los números: limpios y sin repetir ---------- */

function limpiarNumeros($bruto)
{
    global $PREFIJO;
    $out = [];
    foreach (preg_split('/[\s,;|]+/', (string) $bruto, -1, PREG_SPLIT_NO_EMPTY) as $t) {
        $mas = (strpos(trim($t), '+') === 0);
        $d = preg_replace('/\D+/', '', $t);
        if ($d === '') { continue; }
        if (strpos($d, '00') === 0) { $d = substr($d, 2); $mas = true; }
        if (!$mas && strpos($d, $PREFIJO) !== 0) { $d = $PREFIJO . ltrim($d, '0'); }
        if (strlen($d) < 8 || strlen($d) > 15) { continue; }
        $out['+' . $d] = true;                    // la clave elimina repetidos
    }
    return array_keys($out);
}

/* ---------- El estado en disco (para poder continuar) ---------- */

function leerEstado()
{
    global $ESTADO;
    if (!is_file($ESTADO)) { return null; }
    $e = json_decode(file_get_contents($ESTADO), true);
    return is_array($e) ? $e : null;
}

function guardarEstado($e)
{
    global $ESTADO;
    file_put_contents($ESTADO, json_encode($e, JSON_UNESCAPED_UNICODE), LOCK_EX);
}

/* ---------- Twilio ---------- */

function twilio($metodo, $ruta, $campos = null)
{
    global $API_BASE, $ACCOUNT_SID, $AUTH_TOKEN;

    $ch = curl_init($API_BASE . $ruta);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => $ACCOUNT_SID . ':' . $AUTH_TOKEN,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    if ($metodo === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($campos ?: []));
    }
    $resp   = curl_exec($ch);
    $codigo = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err    = curl_error($ch);
    curl_close($ch);

    return ['codigo' => $codigo, 'red' => $err, 'json' => json_decode((string) $resp, true)];
}

/* ---------- Acciones que llama la pantalla ---------- */

$accion = isset($_REQUEST['accion']) ? $_REQUEST['accion'] : '';

if ($accion !== '') {
    header('Content-Type: application/json; charset=utf-8');

    /* Crear un envío nuevo */
    if ($accion === 'crear') {
        $texto = limpiarTexto(trim((string) ($_POST['texto'] ?? '')));

        if ($texto === '')            { exit(json_encode(['error' => 'Escribe el mensaje.'])); }
        if (largoGsm($texto) > 160)   { exit(json_encode(['error' => 'El mensaje tiene ' . largoGsm($texto) . ' caracteres y el tope es 160. Recórtalo en ' . (largoGsm($texto) - 160) . '.'])); }

        $numeros = limpiarNumeros((string) ($_POST['numeros'] ?? ''));
        if (!$numeros)                { exit(json_encode(['error' => 'No hay ningún teléfono válido en la lista.'])); }

        $previo = leerEstado();

        // Si hay un envío igual sin terminar, se continúa en vez de crear otro.
        if ($previo && $previo['texto'] === $texto && $previo['pendientes']) {
            exit(json_encode(['ok' => true, 'continuar' => true]));
        }

        // No repetir: a quien ya se le envió ESTE MISMO texto hace menos de
        // 24 h, se le salta aunque vuelva a estar en la lista.
        $yaRecibieron = [];
        if ($previo && $previo['texto'] === $texto) {
            foreach ($previo['hechos'] as $tel => $h) {
                if ($h['estado'] !== 'fallido' && (time() - $h['cuando']) < 86400) {
                    $yaRecibieron[$tel] = true;
                }
            }
        }
        $pendientes = array_values(array_filter($numeros, function ($t) use ($yaRecibieron) {
            return !isset($yaRecibieron[$t]);
        }));

        guardarEstado([
            'texto'      => $texto,
            'pendientes' => $pendientes,
            'hechos'     => $previo && $previo['texto'] === $texto ? $previo['hechos'] : [],
            'creado'     => time(),
        ]);

        exit(json_encode(['ok' => true, 'total' => count($pendientes), 'saltados' => count($yaRecibieron)]));
    }

    /* Enviar el siguiente de la cola (la pantalla llama a esto cada segundo) */
    if ($accion === 'paso') {
        global $REMITENTE;

        // Candado: si dos pestañas llamaran a la vez, solo una envía.
        $lock = fopen(sys_get_temp_dir() . '/hoja_sms.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { exit(json_encode(['espera' => true])); }

        $e = leerEstado();
        if (!$e || !$e['pendientes']) { exit(json_encode(['fin' => true])); }

        $tel = array_shift($e['pendientes']);

        // Doble seguro: si por lo que sea ya consta como hecho, no se repite.
        if (isset($e['hechos'][$tel]) && $e['hechos'][$tel]['estado'] !== 'fallido') {
            guardarEstado($e);
            exit(json_encode(['saltado' => $tel]));
        }

        $campos = ['To' => $tel, 'Body' => $e['texto']];
        if (strpos($REMITENTE, 'MG') === 0) { $campos['MessagingServiceSid'] = $REMITENTE; }
        else                                { $campos['From'] = $REMITENTE; }

        global $ACCOUNT_SID;
        $r = twilio('POST', '/2010-04-01/Accounts/' . rawurlencode($ACCOUNT_SID) . '/Messages.json', $campos);

        if ($r['red'] !== '') {
            // Sin conexión: vuelve a la cola y se reintenta en el siguiente paso.
            array_unshift($e['pendientes'], $tel);
            guardarEstado($e);
            exit(json_encode(['error_paso' => 'Sin conexión con Twilio: ' . $r['red']]));
        }

        if ($r['codigo'] >= 200 && $r['codigo'] < 300 && !empty($r['json']['sid'])) {
            $e['hechos'][$tel] = ['estado' => 'enviado', 'sid' => $r['json']['sid'], 'motivo' => '', 'cuando' => time()];
        } else {
            $motivo = isset($r['json']['message']) ? $r['json']['message'] : ('error ' . $r['codigo']);
            $e['hechos'][$tel] = ['estado' => 'fallido', 'sid' => '', 'motivo' => mb_substr($motivo, 0, 120), 'cuando' => time()];
        }

        guardarEstado($e);
        exit(json_encode(['enviado' => $tel, 'quedan' => count($e['pendientes'])]));
    }

    /* Estado completo + confirmaciones de entrega desde Twilio */
    if ($accion === 'estado') {
        $e = leerEstado();
        if (!$e) { exit(json_encode(['vacio' => true])); }

        // Preguntamos a Twilio por los últimos mensajes y cruzamos por sid,
        // para saber cuáles LLEGARON de verdad al móvil.
        if (!empty($_GET['confirmar'])) {
            global $ACCOUNT_SID;
            $r = twilio('GET', '/2010-04-01/Accounts/' . rawurlencode($ACCOUNT_SID) . '/Messages.json?PageSize=200');
            if (isset($r['json']['messages'])) {
                $porSid = [];
                foreach ($r['json']['messages'] as $m) {
                    if (!empty($m['sid'])) { $porSid[$m['sid']] = $m; }
                }
                foreach ($e['hechos'] as $tel => &$h) {
                    if ($h['sid'] !== '' && isset($porSid[$h['sid']])) {
                        $m = $porSid[$h['sid']];
                        if ($m['status'] === 'delivered')                                   { $h['estado'] = 'entregado'; }
                        elseif ($m['status'] === 'failed' || $m['status'] === 'undelivered') {
                            $h['estado'] = 'fallido';
                            $h['motivo'] = isset($m['error_message']) && $m['error_message'] ? mb_substr($m['error_message'], 0, 120) : 'no entregado';
                        }
                    }
                }
                unset($h);
                guardarEstado($e);
            }
        }

        $cuenta = ['pendientes' => count($e['pendientes']), 'enviados' => 0, 'entregados' => 0, 'fallidos' => 0];
        $lista  = [];
        foreach ($e['hechos'] as $tel => $h) {
            if ($h['estado'] === 'enviado')   { $cuenta['enviados']++; }
            if ($h['estado'] === 'entregado') { $cuenta['entregados']++; }
            if ($h['estado'] === 'fallido')   { $cuenta['fallidos']++; }
            $lista[] = ['tel' => $tel, 'estado' => $h['estado'], 'motivo' => $h['motivo'], 'cuando' => $h['cuando']];
        }
        usort($lista, function ($a, $b) { return $b['cuando'] - $a['cuando']; });

        exit(json_encode([
            'texto'  => $e['texto'],
            'cuenta' => $cuenta,
            'lista'  => array_slice($lista, 0, 40),
        ], JSON_UNESCAPED_UNICODE));
    }

    exit(json_encode(['error' => 'Orden desconocida.']));
}

/* ---------- Pantalla ---------- */

function plantilla($cuerpo)
{
    return '<!doctype html><html lang="es"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Hoja de SMS</title><style>
:root{--papel:#f4f5f7;--caja:#fff;--tinta:#16181d;--suave:#5b6270;--linea:#e0e3e9;
      --acento:#8b2f52;--ok:#1f7a3d;--mal:#b3261e;--aviso:#8a6100}
@media(prefers-color-scheme:dark){:root{--papel:#12141a;--caja:#1b1e26;--tinta:#eceef2;
      --suave:#9aa2b1;--linea:#2b303b;--acento:#e08bab;--ok:#6cc98c;--mal:#f08a80;--aviso:#e0b45c}}
*{box-sizing:border-box}
body{margin:0;background:var(--papel);color:var(--tinta);font:16px/1.55 system-ui,-apple-system,sans-serif}
.env{max-width:720px;margin:0 auto;padding:24px 14px 70px}
h1{font-size:1.32rem;margin:0 0 3px}.sub{color:var(--suave);font-size:.9rem;margin:0 0 20px}
.caja{background:var(--caja);border:1px solid var(--linea);border-radius:12px;padding:16px 17px;margin-bottom:14px}
.caja.mal{border-left:4px solid var(--mal)}
label{display:block;font-weight:600;font-size:.92rem;margin-bottom:6px}
textarea{width:100%;padding:11px;font:inherit;border:1px solid var(--linea);border-radius:8px;
         background:var(--papel);color:var(--tinta);resize:vertical}
#texto{min-height:100px}#numeros{min-height:120px;font-family:ui-monospace,monospace;font-size:.9rem}
.contador{text-align:right;color:var(--suave);font-size:.82rem;margin-top:5px}
.avisito{background:var(--papel);border-left:3px solid var(--aviso);border-radius:6px;
         padding:9px 12px;font-size:.86rem;color:var(--suave);margin-top:9px;display:none}
button{font:inherit;border:0;border-radius:8px;padding:13px 20px;cursor:pointer;width:100%;font-weight:600}
.principal{background:var(--acento);color:#fff}
.principal:disabled{opacity:.5;cursor:not-allowed}
.otro{background:transparent;border:1px solid var(--linea);color:var(--tinta);font-weight:400;margin-top:9px}
.err{color:var(--mal);font-size:.9rem;margin-top:9px;display:none}
.cifras{display:grid;grid-template-columns:repeat(auto-fit,minmax(86px,1fr));gap:9px;margin-bottom:12px}
.cifra{background:var(--papel);border:1px solid var(--linea);border-radius:9px;padding:10px;text-align:center}
.cifra b{display:block;font-size:1.45rem;line-height:1.2}
.cifra span{font-size:.75rem;color:var(--suave)}
.v-ok{color:var(--ok)}.v-mal{color:var(--mal)}.v-med{color:var(--aviso)}
.barra{height:9px;background:var(--linea);border-radius:99px;overflow:hidden;margin-bottom:12px}
.barra i{display:block;height:100%;background:var(--ok);width:0;transition:width .5s}
table{width:100%;border-collapse:collapse;font-size:.86rem}
td{padding:6px 4px;border-bottom:1px solid var(--linea)}
td.tel{font-family:ui-monospace,monospace}td.est{text-align:right}
.oculto{display:none}
code{background:var(--papel);border:1px solid var(--linea);border-radius:4px;padding:1px 5px}
.pie{color:var(--suave);font-size:.8rem;text-align:center;margin-top:9px}
</style></head><body><div class="env">
<h1>Hoja de SMS</h1>
<p class="sub">Un texto, tu lista, y a cada número le llega uno.</p>
' . $cuerpo . '</div></body></html>';
}

$claveJs = json_encode($claveDada);

echo plantilla(<<<HTML
<!-- ══ ESCRIBIR ══ -->
<div id="zonaForm">
  <div class="caja">
    <!-- UNA sola caja de texto. -->
    <label for="texto">El mensaje</label>
    <textarea id="texto" maxlength="300" placeholder="Escribe aquí el SMS (máximo 160)..."></textarea>
    <div class="contador"><span id="cChars">0</span> de 160</div>
    <div class="avisito" id="avisito"></div>
  </div>

  <div class="caja">
    <label for="numeros">Los números <span style="font-weight:400;color:var(--suave)">(uno por línea o con comas)</span></label>
    <textarea id="numeros" placeholder="600111222&#10;600333444"></textarea>
    <div class="contador"><span id="cNums">0</span> números, sin repetidos</div>
  </div>

  <button class="principal" id="btnEnviar" type="button">Enviar</button>
  <p class="err" id="err"></p>
</div>

<!-- ══ EN VIVO ══ -->
<div id="zonaVivo" class="oculto">
  <div class="caja">
    <div class="cifras">
      <div class="cifra"><b id="nPend">0</b><span>por enviar</span></div>
      <div class="cifra"><b id="nEnv" class="v-med">0</b><span>enviados</span></div>
      <div class="cifra"><b id="nLleg" class="v-ok">0</b><span>llegaron ✓</span></div>
      <div class="cifra"><b id="nFall" class="v-mal">0</b><span>no llegaron ✗</span></div>
    </div>
    <div class="barra"><i id="barra"></i></div>
    <button class="otro" id="btnPausa" type="button">Pausar</button>
    <table><tbody id="tabla"></tbody></table>
    <p class="pie" id="pie"></p>
  </div>
</div>

<script>
(function () {
  'use strict';

  var CLAVE = {$claveJs};

  var btn = document.getElementById('btnEnviar'),
      texto = document.getElementById('texto'),
      numeros = document.getElementById('numeros'),
      err = document.getElementById('err'),
      motor = null, mandando = false, pausado = false, tics = 0;

  /* --- contador de 160 con la misma limpieza que hace el servidor --- */
  var GSM = "@£$¥èéùìòÇ\\nØø\\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\\"#¤%&'()*+,-./0123456789:;<=>?"
          + "¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà^{}\\\\[~]|€";
  var CAMBIA = {'á':'a','í':'i','ó':'o','ú':'u','Á':'A','Í':'I','Ó':'O','Ú':'U','ç':'c',
    '“':'"','”':'"','«':'"','»':'"','‘':"'",'’':"'",'–':'-','—':'-','…':'...'};

  function limpiar(t) {
    var s = '', i, c;
    for (i = 0; i < t.length; i++) {
      c = CAMBIA[t.charAt(i)] !== undefined ? CAMBIA[t.charAt(i)] : t.charAt(i);
      if (GSM.indexOf(c) !== -1) { s += c; }
      else if (c.trim() === '')  { s += ' '; }
    }
    return s.replace(/ {2,}/g, ' ');
  }

  function contar() {
    var limpio = limpiar(texto.value), n = limpio.length;
    document.getElementById('cChars').textContent = n;

    var lista = {}, v = 0;
    numeros.value.split(/[\\s,;|]+/).forEach(function (t) {
      var d = t.replace(/\\D+/g, '');
      if (d.length >= 8 && !lista[d]) { lista[d] = 1; v++; }
    });
    document.getElementById('cNums').textContent = v;

    var a = document.getElementById('avisito');
    if (n > 160) {
      a.textContent = 'Te has pasado ' + (n - 160) + '. Recorta y podrás enviar.';
      a.style.display = 'block'; btn.disabled = true;
    } else if (limpio !== texto.value && texto.value.length) {
      a.textContent = 'Se enviará así (sin tildes de á/í/ó/ú, que cobran doble): «' + limpio + '»';
      a.style.display = 'block'; btn.disabled = mandando;
    } else {
      a.style.display = 'none'; btn.disabled = mandando;
    }
  }
  texto.addEventListener('input', contar);
  numeros.addEventListener('input', contar);

  function fallo(m) { err.textContent = m; err.style.display = 'block'; }

  function pedir(url, datos) {
    var opciones = datos ? { method: 'POST', body: datos } : {};
    return fetch(url + (url.indexOf('?') === -1 ? '?' : '&') + 'clave=' + encodeURIComponent(CLAVE), opciones)
      .then(function (r) { return r.json(); });
  }

  /* --- enviar --- */
  btn.addEventListener('click', function () {
    if (mandando) { return; }         // cerrojo: un clic, un envío
    mandando = true; btn.disabled = true; err.style.display = 'none';
    btn.textContent = 'Preparando...';

    var d = new FormData();
    d.append('texto', texto.value);
    d.append('numeros', numeros.value);

    pedir('?accion=crear', d).then(function (r) {
      if (r.error) { mandando = false; btn.disabled = false; btn.textContent = 'Enviar'; return fallo(r.error); }
      arrancar();
    }).catch(function () {
      mandando = false; btn.disabled = false; btn.textContent = 'Enviar';
      fallo('No hay conexión con la página. Prueba otra vez.');
    });
  });

  function arrancar() {
    document.getElementById('zonaForm').classList.add('oculto');
    document.getElementById('zonaVivo').classList.remove('oculto');
    refrescar(true);
    motor = setInterval(latido, 1100);
  }

  /* Cada 1,1 s: un envío. Cada 10: confirmar entregas con Twilio. */
  function latido() {
    if (pausado) { return; }
    tics++;
    pedir('?accion=paso').then(function (r) {
      if (r.fin) { refrescar(true); clearInterval(motor); motor = null;
                   document.getElementById('btnPausa').textContent = 'Terminado';
                   document.getElementById('btnPausa').disabled = true; return; }
      if (r.error_paso) { document.getElementById('pie').textContent = r.error_paso + ' — reintentando'; return; }
      refrescar(tics % 10 === 0);
    }).catch(function () { /* fallo puntual de red: siguiente latido */ });
  }

  function refrescar(confirmar) {
    pedir('?accion=estado' + (confirmar ? '&confirmar=1' : '')).then(function (d) {
      if (d.vacio) { return; }
      var c = d.cuenta,
          total = c.pendientes + c.enviados + c.entregados + c.fallidos,
          hechos = total - c.pendientes;

      document.getElementById('nPend').textContent = c.pendientes;
      document.getElementById('nEnv').textContent  = c.enviados;
      document.getElementById('nLleg').textContent = c.entregados;
      document.getElementById('nFall').textContent = c.fallidos;
      document.getElementById('barra').style.width = (total ? hechos / total * 100 : 0) + '%';

      var filas = '';
      d.lista.forEach(function (m) {
        var marca = m.estado === 'entregado' ? '<span class="v-ok">✓ llegó</span>'
                  : m.estado === 'enviado'   ? '<span class="v-med">→ enviado</span>'
                  : '<span class="v-mal">✗ ' + (m.motivo || 'no llegó') + '</span>';
        filas += '<tr><td class="tel">' + m.tel + '</td><td class="est">' + marca + '</td></tr>';
      });
      document.getElementById('tabla').innerHTML = filas;
      document.getElementById('pie').textContent =
        'Los ✓ y ✗ los confirma Twilio; tardan unos segundos en actualizarse.';
    }).catch(function () {});
  }

  document.getElementById('btnPausa').addEventListener('click', function () {
    pausado = !pausado;
    this.textContent = pausado ? 'Continuar' : 'Pausar';
  });

  /* Si había un envío a medias, ofrecemos continuar. */
  pedir('?accion=estado').then(function (d) {
    if (!d.vacio && d.cuenta && d.cuenta.pendientes > 0) {
      texto.value = d.texto;
      contar();
      var b = document.createElement('button');
      b.className = 'otro';
      b.textContent = 'Continuar el envío anterior (' + d.cuenta.pendientes + ' pendientes)';
      b.addEventListener('click', function () { mandando = true; arrancar(); });
      document.getElementById('zonaForm').appendChild(b);
    }
  }).catch(function () {});

  contar();
})();
</script>
HTML);
