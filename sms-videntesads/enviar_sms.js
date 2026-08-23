/**
 * Envío de SMS por Twilio desde tu propio ordenador.
 *
 * No necesita servidor, ni la web, ni instalar nada: solo Node, que ya lo
 * tienes porque es lo que hace funcionar a Claude.
 *
 * ---------------------------------------------------------------------
 * CÓMO SE USA
 *
 *   1. Deja estos tres archivos en la misma carpeta:
 *        enviar_sms.js   (éste)
 *        numeros.txt     (los teléfonos, uno por línea)
 *        mensaje.txt     (el texto del SMS, tal cual quieres que llegue)
 *
 *   2. Rellena los TRES datos de aquí abajo con los de tu cuenta Twilio.
 *
 *   3. Abre la carpeta en el terminal y escribe:
 *        node enviar_sms.js --prueba      (no envía: solo comprueba)
 *        node enviar_sms.js               (envía de verdad)
 *
 * Va enseñando en pantalla cómo salen, uno a uno.
 *
 * SI SE CORTA (se cierra, se va la luz, lo paras con Ctrl+C):
 * vuelves a lanzarlo igual y sigue por donde iba. Los que ya salieron NO
 * se repiten: quedan apuntados en enviados.csv y los salta.
 * ---------------------------------------------------------------------
 */

/* ==================== TUS DATOS DE TWILIO ==================== */

// Los tres están en https://console.twilio.com, en la página de inicio.
const ACCOUNT_SID = 'CAMBIAR_ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx';
const AUTH_TOKEN  = 'CAMBIAR_tu_auth_token';

// Desde qué sales. Puede ser:
//   un número tuyo:        '+34600111222'
//   un Messaging Service:  'MGxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx'
//   un nombre (donde Twilio lo permita): 'Videntes'
const REMITENTE = 'CAMBIAR_+34600111222';

// Mensajes por segundo. Con un número normal de Twilio, deja 1.
const POR_SEGUNDO = 1;

// Prefijo de país para los números que vengan sin él. 34 = España, 1 = EEUU.
const PREFIJO_PAIS = '34';

/* ============================================================= */


const fs    = require('fs');
const path  = require('path');
const https = require('https');

const CARPETA   = __dirname;
const F_NUMEROS = path.join(CARPETA, 'numeros.txt');
const F_MENSAJE = path.join(CARPETA, 'mensaje.txt');
const F_LOG     = path.join(CARPETA, 'enviados.csv');

const SOLO_PRUEBA = process.argv.includes('--prueba');


/* ---------- 1. Leer y comprobar ---------- */

function abortar(msg) {
  console.error('\n  ✗ ' + msg + '\n');
  process.exit(1);
}

if (ACCOUNT_SID.startsWith('CAMBIAR') || AUTH_TOKEN.startsWith('CAMBIAR') || REMITENTE.startsWith('CAMBIAR')) {
  abortar('Faltan tus datos de Twilio. Ábreme con un editor y rellena\n    ACCOUNT_SID, AUTH_TOKEN y REMITENTE (están arriba del todo).');
}
if (!fs.existsSync(F_MENSAJE)) abortar('No encuentro mensaje.txt en esta carpeta.');
if (!fs.existsSync(F_NUMEROS)) abortar('No encuentro numeros.txt en esta carpeta.');

const MENSAJE = fs.readFileSync(F_MENSAJE, 'utf8').trim();
if (!MENSAJE) abortar('mensaje.txt está vacío.');


/** Deja el teléfono en formato +34600111222. Devuelve null si no vale. */
function normalizar(bruto) {
  const traiaMas = bruto.trim().startsWith('+');
  let d = bruto.replace(/\D+/g, '');
  if (!d) return null;

  if (d.startsWith('00')) return '+' + d.slice(2);
  if (traiaMas)           return '+' + d;

  // Sin prefijo internacional: le ponemos el del país.
  if (!d.startsWith(PREFIJO_PAIS)) d = PREFIJO_PAIS + d.replace(/^0+/, '');

  return (d.length >= 8 && d.length <= 15) ? '+' + d : null;
}

// Números del archivo, limpios y sin repetidos.
const crudos    = fs.readFileSync(F_NUMEROS, 'utf8').split(/[\s,;|]+/).filter(Boolean);
const invalidos = [];
const vistos    = new Set();
const numeros   = [];

for (const c of crudos) {
  const n = normalizar(c);
  if (!n)            { invalidos.push(c); continue; }
  if (vistos.has(n)) { continue; }          // repetido en tu lista: se envía una vez
  vistos.add(n);
  numeros.push(n);
}

if (!numeros.length) abortar('No he encontrado ningún teléfono válido en numeros.txt.');


/* ---------- 2. Los que ya salieron (para no repetir) ---------- */

const yaEnviados = new Set();

if (fs.existsSync(F_LOG)) {
  for (const linea of fs.readFileSync(F_LOG, 'utf8').split('\n')) {
    const [tel, estado] = linea.split(',');
    if (tel && estado === 'enviado') yaEnviados.add(tel.trim());
  }
} else {
  fs.writeFileSync(F_LOG, 'telefono,estado,referencia,fecha\n');
}

const pendientes = numeros.filter(n => !yaEnviados.has(n));


/* ---------- 3. Resumen antes de empezar ---------- */

/*
 * Cuántos SMS te cobran por este texto.
 *
 * Caben 160 caracteres, pero SOLO si todas las letras están en el alfabeto
 * reducido de los SMS. Ese alfabeto lleva é, è, à, ò, ù, ñ, ü, ç... pero NO
 * lleva á, í, ó, ú. Con una sola de ésas (o un emoji, o una comilla curva de
 * las de Word) el mensaje entero pasa a Unicode y el límite baja a 70.
 * Escribir "más" en vez de "mas" puede doblarte la factura.
 */
const GSM_BASE = "@£$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?"
               + "¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";
const GSM_EXT  = "^{}\\[~]|€";

function calcularSms(txt) {
  let esGsm = true, largo = 0;
  for (const c of txt) {
    if      (GSM_BASE.includes(c)) { largo += 1; }
    else if (GSM_EXT.includes(c))  { largo += 2; }
    else { esGsm = false; break; }
  }
  if (esGsm) {
    return { unicode: false, largo, partes: largo <= 160 ? 1 : Math.ceil(largo / 153) };
  }
  const l = [...txt].reduce((n, c) => n + (c.codePointAt(0) > 0xffff ? 2 : 1), 0);
  return { unicode: true, largo: l, partes: l <= 70 ? 1 : Math.ceil(l / 67) };
}

const calc   = calcularSms(MENSAJE);
const partes = calc.partes;
const segs   = Math.round(pendientes.length / POR_SEGUNDO);
const tiempo = segs < 90 ? segs + ' segundos' : Math.round(segs / 60) + ' minutos';

console.log('');
console.log('  ────────────────────────────────────────────');
console.log('   Mensaje    : ' + MENSAJE.replace(/\n/g, ' ⏎ ').slice(0, 60) + (MENSAJE.length > 60 ? '…' : ''));
console.log('   Longitud   : ' + calc.largo + ' caracteres · ' + partes + ' SMS por persona');
if (calc.unicode) {
  const sinTildes = calcularSms(MENSAJE.replace(/[áíóú]/g, l => ({ á: 'a', í: 'i', ó: 'o', ú: 'u' }[l])));
  console.log('   ⚠ AVISO    : lleva á/í/ó/ú, un emoji o una comilla rara.');
  console.log('                Eso baja el límite de 160 a 70 caracteres.');
  console.log('                Te cobrarán ' + (partes * pendientes.length) + ' SMS en vez de ' + pendientes.length + '.');
  if (sinTildes.partes < partes) {
    console.log('                Quitando esas tildes bajaría a ' + (sinTildes.partes * pendientes.length) + '.');
  }
}
console.log('   En la lista: ' + numeros.length + ' números válidos');
if (invalidos.length)  console.log('   Descartados: ' + invalidos.length + ' (no son teléfonos)');
if (yaEnviados.size)   console.log('   Ya enviados: ' + yaEnviados.size + ' (de una vez anterior, se saltan)');
console.log('   POR ENVIAR : ' + pendientes.length);
console.log('   Tardará    : unos ' + tiempo);
console.log('  ────────────────────────────────────────────');
console.log('');

if (!pendientes.length) {
  console.log('  ✓ No queda nada por enviar. Todos salieron ya.\n');
  process.exit(0);
}

if (SOLO_PRUEBA) {
  console.log('  MODO PRUEBA: no se ha enviado nada.');
  console.log('  Los cinco primeros serían:');
  pendientes.slice(0, 5).forEach(n => console.log('    · ' + n));
  console.log('\n  Si está bien, lanza otra vez SIN --prueba.\n');
  process.exit(0);
}


/* ---------- 4. Enviar ---------- */

function enviarUno(telefono) {
  return new Promise(resolve => {

    const campos = { To: telefono, Body: MENSAJE };
    if (REMITENTE.startsWith('MG')) campos.MessagingServiceSid = REMITENTE;
    else                            campos.From = REMITENTE;

    const cuerpo = new URLSearchParams(campos).toString();

    const req = https.request({
      hostname: 'api.twilio.com',
      path: '/2010-04-01/Accounts/' + encodeURIComponent(ACCOUNT_SID) + '/Messages.json',
      method: 'POST',
      auth: ACCOUNT_SID + ':' + AUTH_TOKEN,
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
        'Content-Length': Buffer.byteLength(cuerpo),
      },
      timeout: 30000,
    }, res => {
      let datos = '';
      res.on('data', t => datos += t);
      res.on('end', () => {
        let j = {};
        try { j = JSON.parse(datos); } catch (e) { /* respuesta rara */ }

        if (res.statusCode >= 200 && res.statusCode < 300 && j.sid) {
          resolve({ ok: true, ref: j.sid });
        } else {
          resolve({ ok: false, ref: (j.message || 'HTTP ' + res.statusCode) });
        }
      });
    });

    req.on('error',   e => resolve({ ok: false, ref: 'sin conexión: ' + e.message }));
    req.on('timeout', () => { req.destroy(); resolve({ ok: false, ref: 'tardó demasiado' }); });

    req.write(cuerpo);
    req.end();
  });
}

const dormir = ms => new Promise(r => setTimeout(r, ms));

(async function () {

  let enviados = 0, fallidos = 0, i = 0;

  for (const tel of pendientes) {
    i++;

    const r = await enviarUno(tel);

    // Se apunta ANTES de seguir. Si se corta justo ahora, este ya consta
    // y en la siguiente pasada no se repite.
    fs.appendFileSync(F_LOG, [tel, r.ok ? 'enviado' : 'fallido', String(r.ref).replace(/,/g, ' '), new Date().toISOString()].join(',') + '\n');

    if (r.ok) { enviados++; console.log('  ✓ ' + String(i).padStart(4) + '/' + pendientes.length + '  ' + tel); }
    else      { fallidos++; console.log('  ✗ ' + String(i).padStart(4) + '/' + pendientes.length + '  ' + tel + '   → ' + r.ref); }

    if (i < pendientes.length) await dormir(1000 / POR_SEGUNDO);
  }

  console.log('');
  console.log('  ────────────────────────────────────────────');
  console.log('   TERMINADO · enviados: ' + enviados + ' · fallidos: ' + fallidos);
  console.log('   El detalle está en enviados.csv');
  if (fallidos) console.log('   Para reintentar solo los fallidos, vuelve a lanzarlo: los buenos se saltan.');
  console.log('  ────────────────────────────────────────────');
  console.log('');

})();
