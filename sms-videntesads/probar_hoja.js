/**
 * Prueba de punta a punta de hoja_sms.php con un Twilio simulado.
 *
 * Comprueba lo que ella ha pedido:
 *   - una sola caja de texto, tope de 160
 *   - a cada número le llega UNO (repetidos fuera, sin dobles)
 *   - se ve en vivo cuáles llegaron (✓) y cuáles no (✗ con motivo)
 *   - si se recarga a mitad, continúa sin repetir a nadie
 */

const fs   = require('fs');
const path = require('path');
const http = require('http');
const { spawn } = require('child_process');
const { chromium } = require('playwright');

const SITIO  = '/tmp/sitio_hoja';
const CLAVE  = 'clave-prueba';
const PUERTO = 8133;
const TWILIO = 8134;

fs.rmSync(SITIO, { recursive: true, force: true });
fs.mkdirSync(SITIO, { recursive: true });

let hoja = fs.readFileSync('/home/user/karma-panel/sms-videntesads/hoja_sms.php', 'utf8');
hoja = hoja
  .replace("$CLAVE       = 'CAMBIA_ESTA_CLAVE';", `$CLAVE       = '${CLAVE}';`)
  .replace("$ACCOUNT_SID = 'CAMBIAR_ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx';", "$ACCOUNT_SID = 'ACprueba';")
  .replace("$AUTH_TOKEN  = 'CAMBIAR_tu_auth_token';", "$AUTH_TOKEN  = 'token';")
  .replace("$REMITENTE   = 'CAMBIAR_+34600111222';", "$REMITENTE   = '+34611111111';");
fs.writeFileSync(path.join(SITIO, 'hoja_sms.php'), hoja);

/* ---------- Twilio falso ---------- */

let ordenes = [];                       // cada POST de envío que llega
let siguienteSid = 1;
const estados = {};                     // sid -> estado que devolverá la lista

const twilio = http.createServer((req, res) => {
  res.setHeader('Content-Type', 'application/json');

  if (req.method === 'POST') {
    let c = '';
    req.on('data', t => c += t);
    req.on('end', () => {
      const datos = new URLSearchParams(c);
      const sid = 'SM' + String(siguienteSid++).padStart(6, '0');
      ordenes.push({ to: datos.get('To'), body: datos.get('Body'), sid });

      // El 600999999 no llega nunca (número apagado); el resto sí.
      estados[sid] = datos.get('To') === '+34600999999'
        ? { status: 'undelivered', error_message: 'movil apagado o inexistente' }
        : { status: 'delivered', error_message: null };

      res.end(JSON.stringify({ sid, status: 'queued' }));
    });
    return;
  }

  // La lista de mensajes, con el estado de entrega de cada sid.
  const mensajes = ordenes.map(o => ({
    sid: o.sid, to: o.to, body: o.body,
    status: estados[o.sid].status,
    error_message: estados[o.sid].error_message,
  }));
  res.end(JSON.stringify({ messages: mensajes }));
});

/* ---------- utilidades ---------- */

const dormir = ms => new Promise(r => setTimeout(r, ms));
let fallos = 0;

function comprobar(nombre, obtenido, esperado) {
  const ok = JSON.stringify(obtenido) === JSON.stringify(esperado);
  if (!ok) fallos++;
  console.log(`  ${ok ? '✓' : '✗'} ${nombre.padEnd(56)} ${JSON.stringify(obtenido)}  (esperado ${JSON.stringify(esperado)})`);
}

(async () => {
  await new Promise(r => twilio.listen(TWILIO, r));

  const php = spawn('php', ['-S', `localhost:${PUERTO}`, '-t', SITIO],
    { stdio: 'ignore', env: { ...process.env, TWILIO_API_BASE: `http://localhost:${TWILIO}` } });
  await dormir(1500);

  const nav = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const ctx = await nav.newContext();
  const p = await ctx.newPage();

  const URL = `http://localhost:${PUERTO}/hoja_sms.php?clave=${CLAVE}`;

  console.log('\n  LA PANTALLA\n');

  await p.goto(URL);
  const cajas = await p.locator('#zonaForm textarea').count();
  comprobar('cajas de texto en el formulario (mensaje + números)', cajas, 2);
  const cajasMensaje = await p.locator('#texto').count();
  comprobar('cajas para el MENSAJE: una sola', cajasMensaje, 1);

  console.log('\n  EL TOPE DE 160\n');

  await p.fill('#texto', 'a'.repeat(170));
  await p.fill('#numeros', '600111222');
  await dormir(200);
  comprobar('con 170 caracteres el botón se bloquea', await p.locator('#btnEnviar').isDisabled(), true);

  await p.fill('#texto', 'Consulta de tarot 20 min por 6 euros. Responde BAJA para no recibir más.');
  await dormir(200);
  comprobar('con un texto normal se desbloquea', await p.locator('#btnEnviar').isDisabled(), false);
  const aviso = await p.locator('#avisito').textContent();
  comprobar('avisa de que quitará la tilde de «más»', aviso.includes('más') || aviso.includes('Se enviará'), true);

  console.log('\n  EL ENVÍO: UNO POR NÚMERO\n');

  // Lista con trampas: el mismo número escrito de tres formas y uno que fallará.
  await p.fill('#numeros', '600111222\n+34600111222\n0034600111222\n600333444\n600999999\n');
  await dormir(200);

  await p.click('#btnEnviar');
  // Doble clic inmediato a propósito: no debe crear otro envío.
  await p.click('#btnEnviar', { force: true }).catch(() => {});

  // 3 números reales a ~1,1 s cada uno + confirmaciones: esperamos con margen.
  await dormir(8000);

  comprobar('órdenes que recibió Twilio (3 números únicos)', ordenes.length, 3);
  comprobar('ningún número repetido en las órdenes',
    new Set(ordenes.map(o => o.to)).size, ordenes.length);
  comprobar('la tilde de «más» no viajó (texto limpio)',
    ordenes.every(o => o.body.includes('recibir mas.')), true);

  console.log('\n  LO QUE SE VE EN PANTALLA\n');

  await dormir(4000);          // un ciclo más de confirmación (cada 10 latidos)

  const llegaron  = await p.locator('#nLleg').textContent();
  const fallidos  = await p.locator('#nFall').textContent();
  comprobar('marcados como llegados ✓', llegaron, '2');
  comprobar('marcados como no llegados ✗', fallidos, '1');

  const tabla = await p.locator('#tabla').textContent();
  comprobar('la tabla enseña el motivo del fallo', tabla.includes('movil apagado'), true);

  console.log('\n  RECARGAR NO REPITE A NADIE\n');

  const antes = ordenes.length;
  await p.goto(URL);           // recarga en frío
  await dormir(3000);
  comprobar('tras recargar no se reenvió ninguno', ordenes.length, antes);

  console.log('\n  SEGURIDAD\n');

  const r1 = await p.goto(`http://localhost:${PUERTO}/hoja_sms.php`);
  comprobar('sin clave no entra', (await r1.text()).includes('Clave incorrecta'), true);

  await nav.close(); php.kill(); twilio.close();
  console.log('\n  ' + (fallos === 0 ? 'TODO CORRECTO' : fallos + ' PRUEBAS FALLIDAS') + '\n');
  process.exit(fallos === 0 ? 0 : 1);
})();
