/**
 * Prueba de punta a punta del instalador arreglar_sms.php.
 *
 * Monta un chat.php falso con el mismo fallo que tiene el suyo, levanta un
 * servidor PHP, pasa el instalador desde el navegador y comprueba con
 * Chromium si el SMS deja de salir doble.
 */

const fs   = require('fs');
const path = require('path');
const http = require('http');
const { execSync, spawn } = require('child_process');
const { chromium } = require('playwright');

const SITIO  = '/tmp/sitio_falso';
const CLAVE  = 'clave-de-prueba';
const PUERTO = 8123;
const CONTADOR = 8124;

/* ---------- 1. Montamos el sitio falso ---------- */

fs.rmSync(SITIO, { recursive: true, force: true });
fs.mkdirSync(SITIO, { recursive: true });

// Un chat.php con el fallo (a): onclick en el HTML Y addEventListener en el JS.
fs.writeFileSync(path.join(SITIO, 'chat.php'), `<?php /* panel de Olivia */ ?>
<!doctype html>
<html lang="es"><head><meta charset="utf-8"><title>Chat Olivia</title></head>
<body>
  <h1>Hoja de SMS</h1>
  <textarea id="texto">Consulta de tarot</textarea>
  <input id="tel" value="600111222">
  <button id="enviar" onclick="mandarSms()">Enviar SMS</button>
  <div id="resultado"></div>

  <script>
  function mandarSms() {
    fetch('http://localhost:${CONTADOR}/enviar', {
      method: 'POST',
      body: 'to=' + document.getElementById('tel').value + '&texto=' + document.getElementById('texto').value
    }).then(function (r) {
      document.getElementById('resultado').textContent += '[ok]';
    });
  }
  document.getElementById('enviar').addEventListener('click', mandarSms);
  </script>
</body></html>
`);

// El instalador, con la clave ya puesta.
let instalador = fs.readFileSync('/home/user/karma-panel/sms-videntesads/arreglar_sms.php', 'utf8');
instalador = instalador.replace("$CLAVE = 'CAMBIA_ESTA_CLAVE';", `$CLAVE = '${CLAVE}';`);
fs.writeFileSync(path.join(SITIO, 'arreglar_sms.php'), instalador);

/* ---------- 2. Servidor que cuenta los SMS ---------- */

let recibidos = [];
const contador = http.createServer((req, res) => {
  res.setHeader('Access-Control-Allow-Origin', '*');
  if (req.method === 'POST') {
    let c = '';
    req.on('data', t => c += t);
    req.on('end', () => { recibidos.push(c); res.end('{"ok":true}'); });
    return;
  }
  res.end('ok');
});

const dormir = ms => new Promise(r => setTimeout(r, ms));
let fallos = 0;

function comprobar(nombre, obtenido, esperado) {
  const ok = obtenido === esperado;
  if (!ok) fallos++;
  console.log(`  ${ok ? '✓' : '✗'} ${nombre.padEnd(54)} ${obtenido}  (esperado ${esperado})`);
}

(async () => {
  await new Promise(r => contador.listen(CONTADOR, r));

  const php = spawn('php', ['-S', `localhost:${PUERTO}`, '-t', SITIO], { stdio: 'ignore' });
  await dormir(1500);

  const nav = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const ctx = await nav.newContext();

  let visita = 0;
  async function clic() {
    recibidos = [];
    visita++;
    const p = await ctx.newPage();
    const consola = [];
    p.on('console', m => consola.push(m.text()));
    p.on('pageerror', e => consola.push('ERROR: ' + e.message));
    await p.goto(`http://localhost:${PUERTO}/chat.php?v=${visita}`);
    await p.click('#enviar');
    await dormir(500);
    const html = await p.content();
    console.log('    [pagina] blindaje presente:', html.includes('anti-doble'), '| bytes:', html.length);
    await p.close();
    if (consola.length) console.log('    [consola]', JSON.stringify(consola));
    return recibidos.length;
  }

  console.log('\n  ANTES DE ARREGLAR\n');
  comprobar('un clic manda el SMS dos veces', await clic(), 2);

  /* ---------- 3. Pasamos el instalador ---------- */
  console.log('\n  PASANDO EL INSTALADOR\n');

  const p = await ctx.newPage();
  await p.goto(`http://localhost:${PUERTO}/arreglar_sms.php?clave=${CLAVE}`);

  const diagnostico = await p.textContent('body');
  const detecta = diagnostico.includes('la orden puesta dos veces');
  if (!detecta) fallos++;
  console.log(`  ${detecta ? '✓' : '✗'} detecta la causa correcta (botón con la orden dos veces)`);

  await p.click('button[value="arreglar"]');
  // El opcache de PHP revalida cada 2 s: sin esta espera, el servidor puede
  // servir la versión compilada ANTES del arreglo y la prueba falla en falso.
  await dormir(2600);

  const tras = await p.textContent('body');
  const puesto = tras.includes('Está puesto');
  if (!puesto) fallos++;
  console.log(`  ${puesto ? '✓' : '✗'} dice que ha quedado puesto`);

  const trasArreglo = fs.readFileSync(path.join(SITIO, 'chat.php'), 'utf8');
  console.log('    [archivo] contiene blindaje:', trasArreglo.includes('anti-doble-sms:inicio'),
              '| </body> en', trasArreglo.lastIndexOf('</body>'), '| blindaje en', trasArreglo.indexOf('anti-doble-sms:inicio'));
  const hayCopia = fs.readdirSync(SITIO).some(f => f.startsWith('chat.php.bak-'));
  if (!hayCopia) fallos++;
  console.log(`  ${hayCopia ? '✓' : '✗'} ha dejado copia de seguridad`);

  console.log('\n  DESPUÉS DE ARREGLAR\n');
  comprobar('un clic manda el SMS UNA vez', await clic(), 1);
  comprobar('vuelve a mandar bien al recargar', await clic(), 1);

  /* ---------- 4. Deshacer ---------- */
  console.log('\n  EL BOTÓN DE DESHACER\n');

  await p.goto(`http://localhost:${PUERTO}/arreglar_sms.php?clave=${CLAVE}`);
  await p.click('button[value="quitar"]');
  await dormir(2600);

  comprobar('al quitarlo vuelve a salir doble (deshace bien)', await clic(), 2);

  const original = fs.readFileSync(path.join(SITIO, 'chat.php'), 'utf8');
  const limpio = !original.includes('anti-doble-sms');
  if (!limpio) fallos++;
  console.log(`  ${limpio ? '✓' : '✗'} el archivo queda sin rastro del blindaje`);

  /* ---------- 5. La puerta ---------- */
  console.log('\n  SEGURIDAD\n');

  await p.goto(`http://localhost:${PUERTO}/arreglar_sms.php`);
  const sinClave = await p.textContent('body');
  const bloquea = sinClave.includes('Clave incorrecta');
  if (!bloquea) fallos++;
  console.log(`  ${bloquea ? '✓' : '✗'} sin clave no deja entrar`);

  await p.goto(`http://localhost:${PUERTO}/arreglar_sms.php?clave=otra`);
  const malClave = await p.textContent('body');
  const bloquea2 = malClave.includes('Clave incorrecta');
  if (!bloquea2) fallos++;
  console.log(`  ${bloquea2 ? '✓' : '✗'} con clave equivocada tampoco`);

  await nav.close();
  php.kill();
  contador.close();

  console.log('\n  ' + (fallos === 0 ? 'TODO CORRECTO' : fallos + ' PRUEBAS FALLIDAS') + '\n');
  process.exit(fallos === 0 ? 0 : 1);
})();
