<?php
/**
 * ══════════════════════════════════════════════════════════════════
 *  ARREGLA EL SMS DOBLE — instalador de un solo archivo
 * ══════════════════════════════════════════════════════════════════
 *
 *  QUÉ HACE
 *  Busca tu chat.php, hace copia de seguridad, mira por dónde se está
 *  duplicando el envío y le pone el blindaje que impide que salga dos
 *  veces. Todo desde el navegador, sin entrar por SSH.
 *
 *  CÓMO SE USA
 *  1. Cambia la CLAVE de aquí abajo por una tuya.
 *  2. Sube este archivo a la misma carpeta donde está chat.php,
 *     con el gestor de archivos de tu hosting.
 *  3. Ábrelo en el navegador:
 *        https://videntesads.com/arreglar_sms.php?clave=LO_QUE_HAYAS_PUESTO
 *  4. Te enseña lo que ha encontrado y un botón para arreglarlo.
 *  5. CUANDO TERMINES, BÓRRALO del servidor.
 *
 *  Todo lo que toca se puede deshacer con un botón: antes de cambiar
 *  nada guarda una copia del archivo original.
 * ══════════════════════════════════════════════════════════════════
 */

// ── CAMBIA ESTO ───────────────────────────────────────────────────
$CLAVE = 'CAMBIA_ESTA_CLAVE';
// ──────────────────────────────────────────────────────────────────

$MARCA_INICIO = '<!-- anti-doble-sms:inicio -->';
$MARCA_FIN    = '<!-- anti-doble-sms:fin -->';

session_start();
header('Content-Type: text/html; charset=utf-8');

/* ---------- Puerta ---------- */
$claveDada = isset($_REQUEST['clave']) ? (string) $_REQUEST['clave'] : '';

if ($CLAVE === 'CAMBIA_ESTA_CLAVE') {
    salir('Antes de nada, abre este archivo y cambia la CLAVE de la línea 26 por una tuya. '
        . 'Mientras esté sin cambiar no funciona, para que nadie que dé con la dirección pueda tocarte la web.');
}
if (!hash_equals($CLAVE, $claveDada)) {
    salir('Clave incorrecta. Abre la dirección poniendo <code>?clave=TU_CLAVE</code> al final.');
}

/* ---------- Buscar el chat.php ---------- */

function buscarChat()
{
    $candidatos = [];

    // Primero al lado de este archivo, que es lo normal.
    foreach (['chat.php', 'sms.php', 'panel.php'] as $n) {
        if (is_file(__DIR__ . '/' . $n)) { $candidatos[] = __DIR__ . '/' . $n; }
    }

    // Y si no, una vuelta por las carpetas de alrededor.
    if (!$candidatos) {
        $raiz = isset($_SERVER['DOCUMENT_ROOT']) ? $_SERVER['DOCUMENT_ROOT'] : __DIR__;
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($raiz, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        $vistos = 0;
        foreach ($it as $f) {
            if (++$vistos > 20000) { break; }          // no nos eternizamos
            if ($f->isFile() && $f->getFilename() === 'chat.php') {
                $candidatos[] = $f->getPathname();
            }
        }
    }

    return $candidatos;
}

$archivos = buscarChat();

if (!$archivos) {
    salir('No encuentro ningún <code>chat.php</code>. Sube este archivo a la misma carpeta donde esté y vuelve a abrirlo.');
}

$ARCHIVO = isset($_REQUEST['archivo']) && in_array($_REQUEST['archivo'], $archivos, true)
         ? $_REQUEST['archivo']
         : $archivos[0];

$contenido = file_get_contents($ARCHIVO);

/* ---------- Diagnóstico ---------- */

function diagnosticar($txt)
{
    $hallazgos = [];

    // (a) El botón con la orden puesta dos veces
    $onclick = preg_match_all('/on(click|submit)\s*=/i', $txt);
    $listen  = preg_match_all('/addEventListener\s*\(\s*[\'"](click|submit)[\'"]/i', $txt);

    if ($onclick > 0 && $listen > 0) {
        $hallazgos[] = [
            'clave' => 'a',
            'titulo' => 'El botón puede tener la orden puesta dos veces',
            'detalle' => "He encontrado {$onclick} <code>onclick/onsubmit</code> en el HTML y {$listen} "
                       . "<code>addEventListener</code> en el JavaScript. Si los dos apuntan al botón de "
                       . "enviar, un solo clic dispara DOS envíos. Es la causa más habitual.",
            'grave' => true,
        ];
    }

    // (b) El mismo .js incluido dos veces
    if (preg_match_all('/<script[^>]+src\s*=\s*[\'"]([^\'"]+)[\'"]/i', $txt, $m)) {
        $cuenta = array_count_values(array_map(function ($s) {
            return strtolower(trim(preg_replace('/\?.*$/', '', $s)));
        }, $m[1]));
        foreach ($cuenta as $src => $n) {
            if ($n > 1) {
                $hallazgos[] = [
                    'clave' => 'b',
                    'titulo' => 'Un archivo de JavaScript está incluido ' . $n . ' veces',
                    'detalle' => "<code>" . htmlspecialchars($src) . "</code> aparece {$n} veces. "
                               . "Todo lo que haga ese archivo se registra por duplicado, incluido el envío.",
                    'grave' => true,
                ];
            }
        }
    }

    // (c) El PHP llamando dos veces a la pasarela
    $llamadas = preg_match_all('/curl_exec|->messages->create|->create\s*\(|enviarSms|sendMessage/i', $txt);
    if ($llamadas > 1) {
        $hallazgos[] = [
            'clave' => 'c',
            'titulo' => 'El PHP llama ' . $llamadas . ' veces a la pasarela',
            'detalle' => "Puede ser normal (una para SMS y otra para otra cosa), pero si las dos mandan el "
                       . "mismo mensaje, el duplicado lo hace el servidor y el blindaje del navegador NO lo "
                       . "arregla. Habría que mirar el archivo por dentro.",
            'grave' => false,
        ];
    }

    return $hallazgos;
}

$hallazgos = diagnosticar($contenido);
$yaPuesto  = (strpos($contenido, $MARCA_INICIO) !== false);
$copias    = glob(dirname($ARCHIVO) . '/' . basename($ARCHIVO) . '.bak-*');
rsort($copias);

/* ---------- Acciones ---------- */

$aviso = '';

if (isset($_POST['accion']) && $_POST['accion'] === 'arreglar' && !$yaPuesto) {

    if (stripos($contenido, '</body>') === false) {
        $aviso = ['mal', 'Este archivo no tiene la etiqueta <code>&lt;/body&gt;</code>, así que no sé dónde poner el blindaje. No he tocado nada.'];
    } else {
        $copia = $ARCHIVO . '.bak-' . date('Y-m-d-His');

        if (!copy($ARCHIVO, $copia)) {
            $aviso = ['mal', 'No he podido hacer la copia de seguridad, así que no he tocado nada. Comprueba los permisos de la carpeta.'];
        } else {
            $bloque = $MARCA_INICIO . "\n" . blindaje() . "\n" . $MARCA_FIN . "\n";

            // Se pone justo antes del ÚLTIMO </body>.
            $pos   = strripos($contenido, '</body>');
            $nuevo = substr($contenido, 0, $pos) . $bloque . substr($contenido, $pos);

            if (file_put_contents($ARCHIVO, $nuevo) === false) {
                $aviso = ['mal', 'No he podido escribir en el archivo. Comprueba que tenga permiso de escritura.'];
            } else {
                $contenido = $nuevo;
                $yaPuesto  = true;
                $copias    = glob(dirname($ARCHIVO) . '/' . basename($ARCHIVO) . '.bak-*');
                rsort($copias);
                $aviso = ['bien', 'Puesto. Copia de seguridad guardada en <code>' . htmlspecialchars(basename($copia)) . '</code>.<br>'
                        . '<b>Ahora mándate un SMS de prueba a tu móvil y mira si te llega una vez o dos.</b>'];
            }
        }
    }
}

if (isset($_POST['accion']) && $_POST['accion'] === 'quitar' && $yaPuesto) {
    $limpio = preg_replace('/' . preg_quote($MARCA_INICIO, '/') . '.*?' . preg_quote($MARCA_FIN, '/') . '\s*/s', '', $contenido);
    if (file_put_contents($ARCHIVO, $limpio) !== false) {
        $contenido = $limpio;
        $yaPuesto  = false;
        $aviso = ['bien', 'Quitado. El archivo se queda como estaba antes.'];
    } else {
        $aviso = ['mal', 'No he podido escribir en el archivo.'];
    }
}

/* ---------- El blindaje que se inyecta ---------- */

function blindaje()
{
    return <<<'HTML'
<script>
/* Blindaje anti SMS doble. Si la misma petición sale dos veces en menos de
   1,2 segundos, deja pasar la primera y tira la segunda. */
(function () {
  'use strict';
  if (window.__antiDobleActivo) { return; }
  window.__antiDobleActivo = true;

  var VENTANA = 1200, recientes = {};

  function limpiar(ahora) {
    for (var k in recientes) {
      if (Object.prototype.hasOwnProperty.call(recientes, k) && ahora - recientes[k] > VENTANA) {
        delete recientes[k];
      }
    }
  }

  function esRepetida(metodo, url, cuerpo) {
    if (!cuerpo) { return false; }
    var clave = 'k|' + metodo + '|' + url + '|' + cuerpo, ahora = Date.now();
    limpiar(ahora);
    if (Object.prototype.hasOwnProperty.call(recientes, clave)) { return true; }
    recientes[clave] = ahora;
    return false;
  }

  function aTexto(c) {
    try {
      if (!c) { return ''; }
      if (typeof c === 'string') { return c; }
      if (typeof URLSearchParams !== 'undefined' && c instanceof URLSearchParams) { return c.toString(); }
      if (typeof FormData !== 'undefined' && c instanceof FormData) {
        var p = [];
        c.forEach(function (v, k) { p.push(k + '=' + (typeof v === 'string' ? v : '[archivo]')); });
        return p.sort().join('&');
      }
      return String(c);
    } catch (e) { return ''; }
  }

  if (window.fetch) {
    var fetchOriginal = window.fetch;
    window.fetch = function (recurso, opciones) {
      try {
        var url = (typeof recurso === 'string') ? recurso : (recurso && recurso.url) || '';
        var metodo = ((opciones && opciones.method) || 'GET').toUpperCase();
        if (metodo !== 'GET' && metodo !== 'HEAD'
            && esRepetida(metodo, url, aTexto(opciones && opciones.body))) {
          console.warn('[anti-doble] Envio repetido bloqueado:', url);
          return Promise.resolve(new Response('{"ok":true,"duplicado":true}',
            { status: 200, headers: { 'Content-Type': 'application/json' } }));
        }
      } catch (e) {}
      return fetchOriginal.apply(this, arguments);
    };
  }

  if (window.XMLHttpRequest) {
    var abrirOriginal = XMLHttpRequest.prototype.open;
    var enviarOriginal = XMLHttpRequest.prototype.send;

    XMLHttpRequest.prototype.open = function (metodo, url) {
      this.__ad_metodo = String(metodo || 'GET').toUpperCase();
      this.__ad_url = String(url || '');
      return abrirOriginal.apply(this, arguments);
    };

    XMLHttpRequest.prototype.send = function (cuerpo) {
      try {
        if (this.__ad_metodo && this.__ad_metodo !== 'GET' && this.__ad_metodo !== 'HEAD'
            && esRepetida(this.__ad_metodo, this.__ad_url, aTexto(cuerpo))) {
          console.warn('[anti-doble] Envio repetido bloqueado:', this.__ad_url);
          var xhr = this, falsa = '{"ok":true,"duplicado":true}';
          setTimeout(function () {
            try {
              Object.defineProperty(xhr, 'readyState',   { value: 4, configurable: true });
              Object.defineProperty(xhr, 'status',       { value: 200, configurable: true });
              Object.defineProperty(xhr, 'statusText',   { value: 'OK', configurable: true });
              Object.defineProperty(xhr, 'responseText', { value: falsa, configurable: true });
              Object.defineProperty(xhr, 'response',     { value: falsa, configurable: true });
              if (typeof xhr.onreadystatechange === 'function') { xhr.onreadystatechange(); }
              xhr.dispatchEvent(new Event('readystatechange'));
              xhr.dispatchEvent(new Event('load'));
              xhr.dispatchEvent(new Event('loadend'));
            } catch (e) { console.warn('[anti-doble]', e.message); }
          }, 10);
          return;
        }
      } catch (e) {}
      return enviarOriginal.apply(this, arguments);
    };
  }

  console.log('[anti-doble] Activo: un clic = un SMS.');
})();
</script>
HTML;
}

/* ---------- Pantalla ---------- */

function salir($mensaje)
{
    echo pagina('<div class="caja mal">' . $mensaje . '</div>');
    exit;
}

function pagina($cuerpo)
{
    return '<!doctype html><html lang="es"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Arreglar SMS doble</title><style>
:root{--papel:#f4f5f7;--caja:#fff;--tinta:#16181d;--suave:#5b6270;--linea:#e0e3e9;
      --acento:#8b2f52;--ok:#1f7a3d;--mal:#b3261e;--aviso:#8a6100}
@media(prefers-color-scheme:dark){:root{--papel:#12141a;--caja:#1b1e26;--tinta:#eceef2;
      --suave:#9aa2b1;--linea:#2b303b;--acento:#e08bab;--ok:#6cc98c;--mal:#f08a80;--aviso:#e0b45c}}
*{box-sizing:border-box}
body{margin:0;background:var(--papel);color:var(--tinta);font:16px/1.55 system-ui,-apple-system,sans-serif}
.env{max-width:680px;margin:0 auto;padding:26px 16px 70px}
h1{font-size:1.3rem;margin:0 0 4px}.sub{color:var(--suave);font-size:.92rem;margin:0 0 22px}
.caja{background:var(--caja);border:1px solid var(--linea);border-radius:12px;padding:16px 18px;margin-bottom:14px}
.caja.mal{border-left:4px solid var(--mal)}
.caja.bien{border-left:4px solid var(--ok)}
.caja.aviso{border-left:4px solid var(--aviso)}
h2{font-size:1rem;margin:0 0 6px}
p{margin:0 0 8px}
code{background:var(--papel);border:1px solid var(--linea);border-radius:4px;padding:1px 5px;font-size:.88em;word-break:break-all}
button{font:inherit;border:0;border-radius:8px;padding:13px 20px;cursor:pointer;width:100%;font-weight:600}
.principal{background:var(--acento);color:#fff}
.otro{background:transparent;border:1px solid var(--linea);color:var(--tinta);font-weight:400;padding:9px 16px;margin-top:10px}
.ruta{color:var(--suave);font-size:.85rem;word-break:break-all}
ul{margin:8px 0 0;padding-left:20px}li{margin-bottom:5px}
</style></head><body><div class="env">
<h1>Arreglar el SMS doble</h1>
<p class="sub">Que a cada número le llegue uno, no dos.</p>' . $cuerpo . '</div></body></html>';
}

/* ---------- Montamos la pantalla ---------- */

$html = '';

if ($aviso) {
    $html .= '<div class="caja ' . $aviso[0] . '">' . $aviso[1] . '</div>';
}

$html .= '<div class="caja"><h2>Archivo</h2><p class="ruta">' . htmlspecialchars($ARCHIVO) . '</p>';
if (count($archivos) > 1) {
    $html .= '<p class="ruta">Hay ' . count($archivos) . ' archivos con ese nombre. Si no es éste, añade '
           . '<code>&amp;archivo=RUTA</code> a la dirección.</p>';
}
$html .= '<p class="ruta">' . (is_writable($ARCHIVO) ? 'Se puede escribir en él. ✓' : '<b>OJO: no tengo permiso para escribir en él.</b>') . '</p></div>';

$html .= '<div class="caja"><h2>Qué he encontrado</h2>';
if (!$hallazgos) {
    $html .= '<p>Ninguna de las tres causas típicas salta a la vista. El blindaje sirve igual, '
           . 'porque corta el duplicado venga de donde venga, siempre que salga del navegador.</p>';
} else {
    $html .= '<ul>';
    foreach ($hallazgos as $h) {
        $html .= '<li><b>' . $h['titulo'] . '</b><br>' . $h['detalle'] . '</li>';
    }
    $html .= '</ul>';
}
$html .= '</div>';

$html .= '<div class="caja ' . ($yaPuesto ? 'bien' : '') . '"><h2>Blindaje</h2>';
if ($yaPuesto) {
    $html .= '<p><b>Está puesto.</b> Un clic manda un solo SMS.</p>'
           . '<p>Mándate un mensaje de prueba a tu móvil y comprueba que llega una sola vez.</p>'
           . '<form method="post"><input type="hidden" name="clave" value="' . htmlspecialchars($claveDada) . '">'
           . '<input type="hidden" name="archivo" value="' . htmlspecialchars($ARCHIVO) . '">'
           . '<button class="otro" name="accion" value="quitar">Quitarlo y dejarlo como estaba</button></form>';
} else {
    $html .= '<p>Aún no está puesto. Al pulsar el botón hago primero una copia de seguridad del archivo '
           . 'y después le añado el blindaje al final. Se puede deshacer con un botón.</p>'
           . '<form method="post"><input type="hidden" name="clave" value="' . htmlspecialchars($claveDada) . '">'
           . '<input type="hidden" name="archivo" value="' . htmlspecialchars($ARCHIVO) . '">'
           . '<button class="principal" name="accion" value="arreglar">Arreglarlo ahora</button></form>';
}
$html .= '</div>';

if ($copias) {
    $html .= '<div class="caja"><h2>Copias de seguridad</h2><p class="ruta">'
           . implode('<br>', array_map(function ($c) { return htmlspecialchars(basename($c)); }, array_slice($copias, 0, 5)))
           . '</p></div>';
}

$html .= '<div class="caja aviso"><h2>Cuando termines</h2>'
       . '<p><b>Borra este archivo del servidor.</b> Mientras esté ahí, cualquiera que dé con la dirección '
       . 'y la clave podría tocar tu web.</p></div>';

echo pagina($html);
