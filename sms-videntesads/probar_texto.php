<?php
/**
 * Comprueba que el mensaje siempre quepa en UN solo SMS.
 *
 * Es lo que garantiza que 700 números sean 700 mensajes cobrados y no
 * 1.400. Se lanza:  php probar_texto.php
 */

if (PHP_SAPI !== 'cli') { exit("Solo por consola.\n"); }

putenv('SMS_CONFIG=' . __DIR__ . '/sms_config.php');
require __DIR__ . '/sms_lib.php';

$fallos = 0;

function prueba($titulo, $entrada, $salidaEsperada, $partesEsperadas)
{
    global $fallos;

    $limpio = sms_a_gsm($entrada);
    $medida = sms_partes($limpio);

    $ok = ($limpio === $salidaEsperada) && ($medida['partes'] === $partesEsperadas) && !$medida['unicode'];
    if (!$ok) { $fallos++; }

    printf("  %s %s\n", $ok ? '✓' : '✗', $titulo);
    if (!$ok) {
        printf("      esperaba : «%s» (%d SMS)\n", $salidaEsperada, $partesEsperadas);
        printf("      obtenido : «%s» (%d SMS, %s)\n", $limpio, $medida['partes'], $medida['unicode'] ? 'unicode' : 'gsm');
    }
}

echo "\n  EL MENSAJE SIEMPRE EN UN SOLO SMS\n\n";

/* --- Las tildes que doblan la factura se quitan --- */
prueba('«más» pasa a «mas»',
    'Responde BAJA para no recibir más.',
    'Responde BAJA para no recibir mas.', 1);

prueba('«número», «está», «llámame»',
    'Tu número está listo. Llámame ya',
    'Tu numero esta listo. Llamame ya', 1);

prueba('«sí» pasa a «si»', 'Sí', 'Si', 1);

/* --- Las que SÍ caben en el alfabeto de los SMS se conservan --- */
prueba('la é de «Café» se conserva',      'Café',   'Café',   1);
prueba('la ñ de «niño» se conserva',      'niño',   'niño',   1);
prueba('la ü de «pingüino» se conserva',  'pingüino', 'pingüino', 1);
prueba('la ç minúscula pasa a c, no se pierde', 'Provença', 'Provenca', 1);
prueba('la à de «està» se conserva',      'està',   'està',   1);

/* --- Lo que mete Word y el móvil --- */
prueba('comillas curvas',      '“Hola”',        '"Hola"',      1);
prueba('comilla de apóstrofo', '’',             "'",           1);
prueba('raya larga',           'a — b',         'a - b',       1);
prueba('puntos suspensivos',   'espera…',       'espera...',   1);
prueba('emoji fuera',          'Oferta 🔮 hoy', 'Oferta hoy',  1);

/* --- Los que sí caben tal cual --- */
prueba('el euro cabe',   'Precio 5€', 'Precio 5€', 1);
prueba('el dólar cabe',  '100$',      '100$',      1);

/* --- Los límites --- */
$m = sms_partes(str_repeat('a', 160));
printf("  %s 160 caracteres = 1 SMS\n", $m['partes'] === 1 ? '✓' : '✗');
if ($m['partes'] !== 1) { $fallos++; }

$m = sms_partes(str_repeat('a', 161));
printf("  %s 161 caracteres = 2 SMS (por eso se bloquea)\n", $m['partes'] === 2 ? '✓' : '✗');
if ($m['partes'] !== 2) { $fallos++; }

/* --- La cuenta que le importa a ella --- */
echo "\n  LO QUE SE COBRA POR 700 NÚMEROS\n\n";

$texto = 'Consulta de tarot 20 min por 6 euros. Responde BAJA para no recibir más.';

$sinLimpiar = sms_partes($texto);
$limpiado   = sms_partes(sms_a_gsm($texto));

printf("   tal cual lo escribes : %s, %d SMS por persona = %d cobrados\n",
    $sinLimpiar['unicode'] ? 'unicode' : 'normal', $sinLimpiar['partes'], $sinLimpiar['partes'] * 700);
printf("   como lo manda el panel: %s, %d SMS por persona = %d cobrados\n",
    $limpiado['unicode'] ? 'unicode' : 'normal', $limpiado['partes'], $limpiado['partes'] * 700);

if ($limpiado['partes'] !== 1) { $fallos++; }

echo "\n  " . ($fallos === 0 ? 'TODO CORRECTO' : $fallos . ' FALLOS') . "\n\n";
exit($fallos === 0 ? 0 : 1);
