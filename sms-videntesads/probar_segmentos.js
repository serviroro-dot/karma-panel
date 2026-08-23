/**
 * Cuántos SMS cobra la operadora por un texto.
 *
 * Un SMS "normal" caben 160 caracteres, pero solo si TODAS las letras están
 * en el alfabeto reducido que usan los SMS (GSM-03.38). Ese alfabeto lleva
 * é, è, à, ò, ù, ñ, ü, ç... pero NO lleva á, í, ó, ú.
 *
 * En cuanto aparece una sola de ésas (o un emoji, o unas comillas curvas de
 * las que pone Word), el mensaje entero pasa a Unicode y el límite se
 * desploma de 160 a 70 caracteres.
 */

const GSM_BASE =
  "@£$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?" +
  "¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";

// Estos caben, pero ocupan el doble.
const GSM_EXT = "^{}\\[~]|€";

function calcular(texto) {
  let esGsm = true;
  let largoGsm = 0;

  for (const c of texto) {
    if (GSM_BASE.includes(c))      { largoGsm += 1; }
    else if (GSM_EXT.includes(c))  { largoGsm += 2; }
    else                           { esGsm = false; break; }
  }

  if (esGsm) {
    return {
      alfabeto: 'normal',
      largo: largoGsm,
      partes: largoGsm === 0 ? 0 : (largoGsm <= 160 ? 1 : Math.ceil(largoGsm / 153)),
    };
  }

  // Unicode: se cuenta en unidades UTF-16 (un emoji vale 2).
  const largo = [...texto].reduce((n, c) => n + (c.codePointAt(0) > 0xffff ? 2 : 1), 0);

  return {
    alfabeto: 'unicode',
    largo,
    partes: largo === 0 ? 0 : (largo <= 70 ? 1 : Math.ceil(largo / 67)),
  };
}

/* ---------------- comprobaciones ---------------- */

const casos = [
  ['Consulta de tarot 20 min por 6 euros. Responde BAJA para no recibir mas.', 'normal', 1],
  ['Consulta de tarot 20 min por 6 euros. Responde BAJA para no recibir más.', 'unicode', 2],
  ['Hola', 'normal', 1],
  ['Tu número de consulta', 'unicode', 1],
  ['Tu numero de consulta', 'normal', 1],
  ['a'.repeat(160), 'normal', 1],
  ['a'.repeat(161), 'normal', 2],
  ['a'.repeat(70) + 'á', 'unicode', 2],
  ['Café', 'normal', 1],              // é sí está en el alfabeto reducido
  ['Sí', 'unicode', 1],               // í no está
  ['Oferta 🔮', 'unicode', 1],
];

let fallos = 0;
console.log('');
for (const [texto, alfabetoEsp, partesEsp] of casos) {
  const r = calcular(texto);
  const ok = r.alfabeto === alfabetoEsp && r.partes === partesEsp;
  if (!ok) fallos++;
  const muestra = texto.length > 34 ? texto.slice(0, 31) + '...' : texto;
  console.log(`  ${ok ? '✓' : '✗'} ${muestra.padEnd(38)} ${r.alfabeto.padEnd(8)} ${r.largo} car · ${r.partes} SMS   (esperado ${alfabetoEsp}, ${partesEsp})`);
}

console.log('\n  ' + (fallos === 0 ? 'TODO CORRECTO' : fallos + ' FALLOS') + '\n');

console.log('  Lo que costaría una tanda de 700:\n');
for (const [texto] of casos.slice(0, 2)) {
  const r = calcular(texto);
  console.log(`   "${texto.slice(0, 44)}..."`);
  console.log(`     → ${r.alfabeto}, ${r.partes} SMS por persona = ${r.partes * 700} cobrados de 700 envíos\n`);
}

process.exit(fallos === 0 ? 0 : 1);
