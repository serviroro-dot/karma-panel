/**
 * Banco de pruebas del blindaje anti doble envío.
 *
 * Levanta un servidor que cuenta los POST que le llegan, monta páginas que
 * reproducen cada causa del fallo, y comprueba con Chromium de verdad si el
 * blindaje corta los duplicados sin cargarse los envíos legítimos.
 */

const http = require('http');
const fs   = require('fs');
const { chromium } = require('playwright');

// Quitamos el comentario HTML de cabecera (que menciona la palabra script
// en su texto, así que hay que borrar el comentario entero, no cortar por
// la primera aparición).
const SNIPPET = fs.readFileSync(
  '/home/user/karma-panel/sms-videntesads/anti_doble.html', 'utf8'
).replace(/<!--[\s\S]*?-->/g, '').trim();

if (!/^<script>/.test(SNIPPET) || !/<\/script>\s*$/.test(SNIPPET)) {
  console.error('El snippet extraído no es un <script> completo. Reviso antes de seguir.');
  console.error(JSON.stringify(SNIPPET.slice(0, 120)));
  process.exit(2);
}

let recibidos = [];

const server = http.createServer((req, res) => {
  if (req.method === 'POST') {
    let cuerpo = '';
    req.on('data', t => cuerpo += t);
    req.on('end', () => {
      recibidos.push({ url: req.url, cuerpo });
      res.writeHead(200, { 'Content-Type': 'application/json' });
      res.end('{"ok":true}');
    });
    return;
  }
  res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
  res.end(paginas[req.url] || '<h1>no</h1>');
});

/* ------------------------------------------------------------------ */
/*  Las páginas de prueba                                              */
/* ------------------------------------------------------------------ */

const marco = (cuerpo, conBlindaje) => `<!doctype html><html><head><meta charset="utf-8"></head>
<body>${cuerpo}${conBlindaje ? SNIPPET : ''}</body></html>`;

// CASO A — el botón tiene onclick en el HTML Y ADEMÁS addEventListener.
// Un solo clic dispara las dos, y salen dos SMS.
const casoA = `
<button id="b" onclick="enviar()">Enviar</button>
<script>
function enviar() {
  fetch('/enviar', { method: 'POST', body: 'to=600111222&texto=hola' });
}
document.getElementById('b').addEventListener('click', enviar);
</script>`;

// CASO B — el mismo bloque de script incluido dos veces: el
// addEventListener se registra por duplicado.
const casoB = `
<button id="b">Enviar</button>
<script>
function enviar() {
  fetch('/enviar', { method: 'POST', body: 'to=600111222&texto=hola' });
}
document.getElementById('b').addEventListener('click', enviar);
</script>
<script>
function enviar2() {
  fetch('/enviar', { method: 'POST', body: 'to=600111222&texto=hola' });
}
document.getElementById('b').addEventListener('click', enviar2);
</script>`;

// CASO C — vía XMLHttpRequest (que es lo que usa jQuery por debajo),
// con el manejador enganchado dos veces.
const casoC = `
<button id="b" onclick="enviar()">Enviar</button>
<div id="r"></div>
<script>
function enviar() {
  var x = new XMLHttpRequest();
  x.open('POST', '/enviar');
  x.addEventListener('load', function () {
    document.getElementById('r').textContent += '[respondio:' + x.status + ']';
  });
  x.send('to=600111222&texto=hola');
}
document.getElementById('b').addEventListener('click', enviar);
</script>`;

// LEGÍTIMO 1 — dos SMS distintos seguidos. Deben salir LOS DOS.
const legitimoDistintos = `
<button id="b">Enviar dos</button>
<script>
document.getElementById('b').addEventListener('click', function () {
  fetch('/enviar', { method: 'POST', body: 'to=600111222&texto=hola' });
  fetch('/enviar', { method: 'POST', body: 'to=600999888&texto=hola' });
});
</script>`;

// LEGÍTIMO 2 — el panel preguntando "¿cómo va?" cada poco, siempre igual.
// Con cuerpo vacío. NO se debe bloquear ninguna.
const legitimoSondeo = `
<script>
var n = 0;
var t = setInterval(function () {
  fetch('/estado', { method: 'POST' });
  if (++n === 4) clearInterval(t);
}, 300);
</script>`;

// LEGÍTIMO 3 — el mismo mensaje al mismo número, pero pasado el tiempo
// de la ventana. Es un reenvío a propósito y debe salir.
const legitimoReenvio = `
<button id="b">Enviar</button>
<script>
document.getElementById('b').addEventListener('click', function () {
  fetch('/enviar', { method: 'POST', body: 'to=600111222&texto=hola' });
});
</script>`;

// LEGÍTIMO 4 — dos mensajes DISTINTOS seguidos por el mismo formulario
// nativo. Éste es el caso que se tragaba el bloque 3 que hemos quitado.
// Deben salir LOS DOS.
const legitimoFormDistinto = `
<form id="f" action="/enviar" method="post" onsubmit="return mandar(event)">
  <input name="texto" id="t" value="ya te llamo">
  <button id="b" type="submit">Enviar</button>
</form>
<script>
function mandar(e) {
  e.preventDefault();
  fetch('/enviar', { method: 'POST', body: 'texto=' + document.getElementById('t').value });
  return false;
}
</script>`;

// LEGÍTIMO 5 — sondeo cada 2 segundos CON cuerpo siempre idéntico.
// Es el caso que denunció la revisión. No se debe bloquear ninguno.
const legitimoSondeoConCuerpo = `
<script>
var n = 0;
var t = setInterval(function () {
  fetch('/estado', { method: 'POST', body: 'action=check_new&chat=123' });
  if (++n === 3) clearInterval(t);
}, 2000);
</script>`;

const paginas = {
  '/formdist': marco(legitimoFormDistinto, true),
  '/sondeo2':  marco(legitimoSondeoConCuerpo, true),
  '/a':        marco(casoA, true),
  '/a-sin':    marco(casoA, false),
  '/b':        marco(casoB, true),
  '/b-sin':    marco(casoB, false),
  '/c':        marco(casoC, true),
  '/c-sin':    marco(casoC, false),
  '/dist':     marco(legitimoDistintos, true),
  '/sondeo':   marco(legitimoSondeo, true),
  '/reenvio':  marco(legitimoReenvio, true),
};

/* ------------------------------------------------------------------ */

const dormir = ms => new Promise(r => setTimeout(r, ms));
let fallos = 0;

function comprobar(nombre, obtenido, esperado) {
  const ok = obtenido === esperado;
  if (!ok) fallos++;
  console.log(`  ${ok ? '✓' : '✗'} ${nombre.padEnd(52)} salieron ${obtenido}, esperado ${esperado}`);
}

(async () => {
  await new Promise(r => server.listen(8099, r));

  const nav = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const ctx = await nav.newContext();

  async function correr(ruta, acciones) {
    recibidos = [];
    const pag = await ctx.newPage();
    await pag.goto('http://localhost:8099' + ruta);
    await acciones(pag);
    await pag.close();
    return recibidos.length;
  }

  const unClic = async p => { await p.click('#b'); await dormir(400); };

  console.log('\n  SIN BLINDAJE — así está su página ahora (debe salir 2)\n');
  comprobar('caso A · onclick + addEventListener', await correr('/a-sin', unClic), 2);
  comprobar('caso B · script incluido dos veces',  await correr('/b-sin', unClic), 2);
  comprobar('caso C · por XMLHttpRequest',          await correr('/c-sin', unClic), 2);

  console.log('\n  CON BLINDAJE — el duplicado se corta (debe salir 1)\n');
  comprobar('caso A · onclick + addEventListener', await correr('/a', unClic), 1);
  comprobar('caso B · script incluido dos veces',  await correr('/b', unClic), 1);
  comprobar('caso C · por XMLHttpRequest',          await correr('/c', unClic), 1);

  console.log('\n  DOBLE TOQUE humano (debe salir 1)\n');
  comprobar('doble clic rápido', await correr('/reenvio', async p => {
    await p.click('#b'); await dormir(120); await p.click('#b'); await dormir(400);
  }), 1);

  console.log('\n  NO DEBE ESTORBAR a los envíos legítimos\n');
  comprobar('dos SMS a números distintos', await correr('/dist', unClic), 2);
  comprobar('sondeos de estado, cuerpo vacío', await correr('/sondeo', async p => { await dormir(1800); }), 4);
  comprobar('reenvío a propósito pasada la ventana', await correr('/reenvio', async p => {
    await p.click('#b'); await dormir(1600); await p.click('#b'); await dormir(400);
  }), 2);

  // Los dos casos que denunció la revisión y que antes fallaban.
  comprobar('dos mensajes DISTINTOS por el mismo formulario', await correr('/formdist', async p => {
    await p.click('#b');
    await dormir(200);
    await p.fill('#t', 'en 5 minutos');
    await p.click('#b');
    await dormir(400);
  }), 2);

  // Los sondeos salen a los 2, 4 y 6 segundos: hay que esperar más de 6.
  comprobar('sondeo cada 2 s con cuerpo siempre igual', await correr('/sondeo2', async p => {
    await dormir(6800);
  }), 3);

  console.log('\n  LA PÁGINA NO SE QUEDA COLGADA\n');
  recibidos = [];
  const pag = await ctx.newPage();
  await pag.goto('http://localhost:8099/c');
  await pag.click('#b');
  await dormir(500);
  const texto = await pag.textContent('#r');
  const avisos = (texto.match(/respondio:200/g) || []).length;
  const ok = avisos === 2;
  if (!ok) fallos++;
  console.log(`  ${ok ? '✓' : '✗'} ambas peticiones reciben respuesta (la real y la falsa)   ${avisos} de 2`);
  await pag.close();

  await nav.close();
  server.close();

  console.log(fallos === 0 ? '\n  TODO CORRECTO\n' : `\n  ${fallos} PRUEBAS FALLIDAS\n`);
  process.exit(fallos === 0 ? 0 : 1);
})();
