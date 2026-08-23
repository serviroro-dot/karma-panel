<?php
/**
 * La pantalla de SMS: una sola caja, un solo envío, y el avance en vivo.
 */

require __DIR__ . '/sms_lib.php';

session_start();
$cfg   = sms_config();
$error = '';

/* ---- Puerta ---- */
if ($cfg['clave_panel'] !== '') {
    if (isset($_POST['clave'])) {
        if (hash_equals($cfg['clave_panel'], (string) $_POST['clave'])) {
            $_SESSION['sms_ok'] = true;
        } else {
            $error = 'Contraseña incorrecta.';
        }
    }
    if (empty($_SESSION['sms_ok'])) {
        ?>
        <!doctype html><html lang="es"><head><meta charset="utf-8">
        <meta name="viewport" content="width=device-width,initial-scale=1">
        <title>Entrar · SMS</title>
        <style>
          body{font:16px system-ui,sans-serif;background:#f4f5f7;display:grid;place-items:center;height:100vh;margin:0}
          form{background:#fff;padding:28px;border-radius:12px;box-shadow:0 2px 12px rgba(0,0,0,.08);width:min(320px,90vw)}
          h1{font-size:1.1rem;margin:0 0 16px}
          input{width:100%;padding:11px;font-size:1rem;border:1px solid #ccd;border-radius:8px;box-sizing:border-box}
          button{width:100%;margin-top:12px;padding:11px;font-size:1rem;border:0;border-radius:8px;background:#8b2f52;color:#fff;cursor:pointer}
          .e{color:#b3261e;font-size:.9rem;margin-top:10px}
        </style></head><body>
        <form method="post">
          <h1>Panel de SMS</h1>
          <input type="password" name="clave" placeholder="Contraseña" autofocus>
          <button type="submit">Entrar</button>
          <?php if ($error): ?><p class="e"><?= htmlspecialchars($error) ?></p><?php endif; ?>
        </form></body></html>
        <?php
        exit;
    }
}

// Ficha única de este formulario. Si se envía dos veces, el servidor
// reconoce el token y NO crea un segundo envío.
$token = bin2hex(random_bytes(20));
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Envío de SMS</title>
<style>
  :root{
    --papel:#f4f5f7; --caja:#fff; --tinta:#16181d; --suave:#5b6270;
    --linea:#e0e3e9; --acento:#8b2f52; --ok:#1f7a3d; --mal:#b3261e; --aviso:#8a6100;
  }
  @media (prefers-color-scheme:dark){:root{
    --papel:#12141a; --caja:#1b1e26; --tinta:#eceef2; --suave:#9aa2b1;
    --linea:#2b303b; --acento:#e08bab; --ok:#6cc98c; --mal:#f08a80; --aviso:#e0b45c;
  }}
  *{box-sizing:border-box}
  body{margin:0;background:var(--papel);color:var(--tinta);
       font:16px/1.55 system-ui,-apple-system,"Segoe UI",sans-serif}
  .env{max-width:760px;margin:0 auto;padding:24px 16px 64px}
  h1{font-size:1.35rem;margin:0 0 4px}
  .sub{color:var(--suave);font-size:.92rem;margin:0 0 22px}
  .caja{background:var(--caja);border:1px solid var(--linea);border-radius:12px;
        padding:18px;margin-bottom:16px}
  label{display:block;font-weight:600;font-size:.92rem;margin-bottom:6px}
  .pista{color:var(--suave);font-size:.84rem;font-weight:400}
  textarea,input[type=text]{width:100%;padding:11px;font:inherit;border:1px solid var(--linea);
        border-radius:8px;background:var(--papel);color:var(--tinta);resize:vertical}
  textarea#texto{min-height:110px}
  textarea#numeros{min-height:130px;font-family:ui-monospace,monospace;font-size:.9rem}
  .contador{text-align:right;color:var(--suave);font-size:.82rem;margin-top:5px}
  button{font:inherit;border:0;border-radius:8px;padding:12px 20px;cursor:pointer}
  #enviar{background:var(--acento);color:#fff;font-weight:600;width:100%;margin-top:4px}
  #enviar:disabled{opacity:.55;cursor:not-allowed}
  .secundario{background:transparent;border:1px solid var(--linea);color:var(--tinta);padding:8px 14px;font-size:.9rem}
  .aviso{background:var(--caja);border-left:3px solid var(--aviso);padding:11px 14px;
         border-radius:6px;font-size:.9rem;color:var(--suave);margin-top:12px}
  .cifras{display:grid;grid-template-columns:repeat(auto-fit,minmax(94px,1fr));gap:10px;margin-bottom:14px}
  .cifra{background:var(--papel);border:1px solid var(--linea);border-radius:9px;padding:11px;text-align:center}
  .cifra b{display:block;font-size:1.5rem;line-height:1.2}
  .cifra span{font-size:.78rem;color:var(--suave)}
  .barra{height:9px;background:var(--linea);border-radius:99px;overflow:hidden;margin-bottom:14px}
  .barra i{display:block;height:100%;background:var(--ok);width:0;transition:width .4s}
  table{width:100%;border-collapse:collapse;font-size:.88rem}
  td{padding:7px 4px;border-bottom:1px solid var(--linea)}
  td.tel{font-family:ui-monospace,monospace}
  td.est{text-align:right;white-space:nowrap}
  .v-ok{color:var(--ok)} .v-mal{color:var(--mal)} .v-can{color:var(--suave)}
  .oculto{display:none}
  .fila-btn{display:flex;gap:10px;margin-bottom:14px;flex-wrap:wrap}
  .err{color:var(--mal);font-size:.9rem;margin-top:10px}
</style>
</head>
<body>
<div class="env">

  <h1>Envío de SMS</h1>
  <p class="sub">Escribe el mensaje una vez, pega la lista de números y dale a enviar.</p>

  <!-- ============ FORMULARIO ============ -->
  <div id="zonaForm">
    <div class="caja">
      <label for="nombre">Nombre del envío <span class="pista">(para reconocerlo luego)</span></label>
      <input type="text" id="nombre" placeholder="Promoción de agosto">
    </div>

    <div class="caja">
      <!-- UNA sola caja para el mensaje. No hay ninguna otra en la página. -->
      <label for="texto">Mensaje</label>
      <textarea id="texto" maxlength="800" placeholder="Escribe aquí el SMS..."></textarea>
      <div class="contador"><span id="cChars">0</span> caracteres · <span id="cPartes">0</span> SMS por persona</div>
      <div class="aviso oculto" id="avisoTildes"></div>
    </div>

    <div class="caja">
      <label for="numeros">Números <span class="pista">(uno por línea, o separados por comas)</span></label>
      <textarea id="numeros" placeholder="600111222&#10;600333444&#10;+34600555666"></textarea>
      <div class="contador"><span id="cNums">0</span> números válidos, sin repetidos</div>
    </div>

    <button id="enviar" type="button">Enviar</button>
    <p class="err oculto" id="errForm"></p>

    <div class="aviso" id="avisoRitmo">
      Se mandan seguidos, a <?= (int) $cfg['por_segundo'] ?> por segundo. No hace falta que
      dejes esto abierto: una vez lanzado sigue solo aunque cierres el ordenador.
    </div>
  </div>

  <!-- ============ EN VIVO ============ -->
  <div id="zonaVivo" class="oculto">
    <div class="caja">
      <div class="cifras">
        <div class="cifra"><b id="nTotal">0</b><span>total</span></div>
        <div class="cifra"><b id="nEnviados" class="v-ok">0</b><span>enviados</span></div>
        <div class="cifra"><b id="nPendientes">0</b><span>pendientes</span></div>
        <div class="cifra"><b id="nFallidos" class="v-mal">0</b><span>fallidos</span></div>
      </div>

      <div class="barra"><i id="barra"></i></div>

      <div class="fila-btn">
        <button class="secundario" id="btnPausa" type="button">Pausar</button>
        <button class="secundario" id="btnNuevo" type="button">Nuevo envío</button>
      </div>

      <table><tbody id="tabla"></tbody></table>
    </div>
  </div>

</div>

<script>
(function () {
  'use strict';

  // Un único punto de entrada. El botón NO lleva onclick en el HTML:
  // si lo llevara y además lo enganchásemos aquí, un clic dispararía
  // dos envíos. Ese era justo el fallo de antes.
  var btn      = document.getElementById('enviar'),
      texto    = document.getElementById('texto'),
      numeros  = document.getElementById('numeros'),
      nombre   = document.getElementById('nombre'),
      errForm  = document.getElementById('errForm'),
      zonaForm = document.getElementById('zonaForm'),
      zonaVivo = document.getElementById('zonaVivo'),
      btnPausa = document.getElementById('btnPausa'),
      btnNuevo = document.getElementById('btnNuevo');

  var TOKEN    = <?= json_encode($token) ?>;
  var enviando = false;      // cerrojo: mientras esté a true no se manda nada más
  var envioId  = null;
  var reloj    = null;
  var pausado  = false;

  /* ---------- contadores ---------- */
  function limpiarNumeros(txt) {
    var vistos = {}, n = 0;
    txt.split(/[\s,;|]+/).forEach(function (t) {
      var d = t.replace(/\D+/g, '');
      if (d.length >= 8 && !vistos[d]) { vistos[d] = 1; n++; }
    });
    return n;
  }

  /*
   * Cuántos SMS te cobran por este texto.
   *
   * Un SMS son 160 caracteres, pero SOLO si todas las letras están en el
   * alfabeto reducido de los SMS. Ese alfabeto lleva é, è, à, ò, ù, ñ, ü,
   * ç... pero NO lleva á, í, ó, ú. En cuanto aparece una de ésas, un emoji
   * o unas comillas curvas de las que pone Word, el mensaje entero pasa a
   * Unicode y el límite baja de 160 a 70. Ahí es donde la gente paga el
   * doble sin enterarse.
   */
  var GSM_BASE = "@£$¥èéùìòÇ\nØø\rÅåΔ_"
               + "ΦΓΛΩΠΨΣΘΞÆæßÉ"
               + " !\"#¤%&'()*+,-./0123456789:;<=>?¡"
               + "ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿"
               + "abcdefghijklmnopqrstuvwxyzäöñüà";
  var GSM_EXT  = "^{}\\[~]|€";

  function calcularSms(txt) {
    var esGsm = true, largo = 0, i, c;

    for (i = 0; i < txt.length; i++) {
      c = txt.charAt(i);
      if      (GSM_BASE.indexOf(c) !== -1) { largo += 1; }
      else if (GSM_EXT.indexOf(c)  !== -1) { largo += 2; }
      else { esGsm = false; break; }
    }

    if (esGsm) {
      return { unicode: false, largo: largo,
               partes: largo === 0 ? 0 : (largo <= 160 ? 1 : Math.ceil(largo / 153)) };
    }

    return { unicode: true, largo: txt.length,
             partes: txt.length === 0 ? 0 : (txt.length <= 70 ? 1 : Math.ceil(txt.length / 67)) };
  }

  function refrescarContadores() {
    var r     = calcularSms(texto.value);
    var nums  = limpiarNumeros(numeros.value);
    var aviso = document.getElementById('avisoTildes');

    document.getElementById('cChars').textContent  = r.largo;
    document.getElementById('cPartes').textContent = r.partes;
    document.getElementById('cNums').textContent   = nums;

    if (r.unicode && r.largo > 0) {
      aviso.innerHTML = 'Tu mensaje lleva alguna <b>tilde de las caras</b> (á, í, ó, ú), '
        + 'un emoji o una comilla rara. Eso baja el límite de 160 a 70 caracteres, '
        + 'así que se cobra como <b>' + r.partes + ' SMS por persona</b>'
        + (nums ? ' — ' + (r.partes * nums) + ' cobrados para ' + nums + ' números' : '')
        + '.<br>Quitando esas tildes se queda en '
        + calcularSms(texto.value.replace(/[áíóú]/g, function (l) {
            return { 'á': 'a', 'í': 'i', 'ó': 'o', 'ú': 'u' }[l];
          })).partes + '.';
      aviso.classList.remove('oculto');
    } else if (r.partes > 1) {
      aviso.innerHTML = 'El mensaje pasa de 160 caracteres, así que se cobra como <b>'
        + r.partes + ' SMS por persona</b>'
        + (nums ? ' — ' + (r.partes * nums) + ' cobrados para ' + nums + ' números' : '') + '.';
      aviso.classList.remove('oculto');
    } else {
      aviso.classList.add('oculto');
    }
  }

  texto.addEventListener('input', refrescarContadores);
  numeros.addEventListener('input', refrescarContadores);
  refrescarContadores();

  /* ---------- enviar ---------- */
  btn.addEventListener('click', function () {

    if (enviando) { return; }              // doble clic: se ignora
    errForm.classList.add('oculto');

    if (!texto.value.trim())   { return fallo('Escribe el mensaje.'); }
    if (!numeros.value.trim()) { return fallo('Pega la lista de números.'); }

    enviando    = true;                    // cerramos el cerrojo ANTES de pedir nada
    btn.disabled = true;
    btn.textContent = 'Preparando...';

    var datos = new FormData();
    datos.append('accion',  'encolar');
    datos.append('texto',   texto.value.trim());
    datos.append('numeros', numeros.value);
    datos.append('nombre',  nombre.value.trim());
    datos.append('token',   TOKEN);        // misma ficha = mismo envío, nunca dos

    fetch('sms_api.php', { method: 'POST', body: datos })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
      .then(function (res) {
        if (!res.ok) { throw new Error(res.j.error || 'Error al preparar el envío.'); }
        envioId = res.j.envio_id;
        zonaForm.classList.add('oculto');
        zonaVivo.classList.remove('oculto');
        mirar();
        reloj = setInterval(mirar, 3000);
      })
      .catch(function (e) {
        enviando = false;                  // solo aquí se vuelve a abrir
        btn.disabled = false;
        btn.textContent = 'Enviar';
        fallo(e.message);
      });
  });

  function fallo(msg) {
    errForm.textContent = msg;
    errForm.classList.remove('oculto');
  }

  /* ---------- en vivo ---------- */
  function mirar() {
    fetch('sms_api.php?accion=estado&envio_id=' + envioId)
      .then(function (r) { return r.json(); })
      .then(pintar)
      .catch(function () { /* un fallo de red suelto no rompe nada */ });
  }

  function pintar(d) {
    if (!d || !d.cuenta) { return; }

    var c        = d.cuenta,
        total    = parseInt(d.envio.total, 10) || 0,
        hechos   = c.enviado + c.fallido + c.cancelado,
        pendient = c.pendiente + c.enviando;

    document.getElementById('nTotal').textContent      = total;
    document.getElementById('nEnviados').textContent   = c.enviado;
    document.getElementById('nPendientes').textContent = pendient;
    document.getElementById('nFallidos').textContent   = c.fallido;
    document.getElementById('barra').style.width       = (total ? (hechos / total * 100) : 0) + '%';

    var filas = d.ultimos.map(function (u) {
      var clase = u.estado === 'enviado' ? 'v-ok' : (u.estado === 'fallido' ? 'v-mal' : 'v-can');
      var marca = u.estado === 'enviado' ? '✓ enviado'
                : (u.estado === 'fallido' ? '✗ ' + (u.error || 'error') : '— ' + (u.error || 'cancelado'));
      return '<tr><td class="tel">' + u.telefono + '</td>'
           + '<td class="est ' + clase + '">' + marca + '</td></tr>';
    }).join('');

    document.getElementById('tabla').innerHTML = filas;

    pausado = (d.envio.estado === 'pausado');
    btnPausa.textContent = pausado ? 'Reanudar' : 'Pausar';

    if (d.envio.estado === 'terminado') {
      btnPausa.disabled = true;
      btnPausa.textContent = 'Terminado';
      clearInterval(reloj);
    }
  }

  btnPausa.addEventListener('click', function () {
    var datos = new FormData();
    datos.append('accion', pausado ? 'reanudar' : 'pausar');
    datos.append('envio_id', envioId);
    btnPausa.disabled = true;
    fetch('sms_api.php', { method: 'POST', body: datos })
      .then(function () { btnPausa.disabled = false; mirar(); })
      .catch(function () { btnPausa.disabled = false; });
  });

  // Recarga limpia: ficha nueva, cerrojo nuevo.
  btnNuevo.addEventListener('click', function () { location.reload(); });

})();
</script>
</body>
</html>
