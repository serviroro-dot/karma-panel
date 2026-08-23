<?php
/**
 * Banco de pruebas de la cola de SMS, contra una base de datos de verdad.
 *
 * Comprueba las dos cosas que importan:
 *   1. Que a cada número le llega UN solo SMS, pase lo que pase.
 *   2. Que no hay tope: entran tantos como se peguen.
 *
 * La pasarela es falsa: en vez de mandar nada a Twilio, apunta a quién se
 * le habría mandado. Así se puede comprobar el recuento sin gastar un euro.
 *
 * Se lanza:  php probar_cola.php
 */

if (PHP_SAPI !== 'cli') { exit("Solo por consola.\n"); }

/* ---------- Enchufamos una configuración de prueba ---------- */

$CFG_PRUEBA = [
    'db' => [
        'host'   => '127.0.0.1',
        'nombre' => 'CAMBIAR_base_de_pruebas',
        'user'   => 'CAMBIAR_usuario_de_pruebas',
        'pass'   => 'CAMBIAR_contrasena',
    ],
    'pasarela'         => 'falsa',
    'por_segundo'      => 1000,      // en la prueba no queremos esperas
    'max_intentos'     => 3,
    'prefijo_pais'     => '34',
    'clave_panel'      => '',
    'no_repetir_horas' => 24,
    'tope_por_envio'   => 0,
];

// Escribimos la configuración de prueba en un archivo y la enchufamos por
// variable de entorno, para que el trabajador (que es otro proceso) use la
// misma base de datos y la misma pasarela falsa.
$rutaCfg = sys_get_temp_dir() . '/sms_config_prueba.php';
file_put_contents($rutaCfg, '<?php return ' . var_export($CFG_PRUEBA, true) . ';');
putenv('SMS_CONFIG=' . $rutaCfg);
$_ENV['SMS_CONFIG'] = $rutaCfg;

$LOG_FALSOS = sys_get_temp_dir() . '/sms_falsos.log';
@unlink($LOG_FALSOS);

require __DIR__ . '/sms_lib.php';

/* ---------- Utilidades de la prueba ---------- */

$fallos = 0;

function comprobar($nombre, $obtenido, $esperado) {
    global $fallos;
    $ok = ($obtenido === $esperado);
    if (!$ok) { $fallos++; }
    printf("  %s %-56s %s  (esperado %s)\n",
        $ok ? '✓' : '✗', $nombre, var_export($obtenido, true), var_export($esperado, true));
}

function db() { return sms_db(); }

/** Cuántas órdenes ha recibido la pasarela falsa. */
function contarFalsos() {
    $f = sys_get_temp_dir() . '/sms_falsos.log';
    if (!file_exists($f)) { return 0; }
    return count(array_filter(explode("\n", file_get_contents($f))));
}

function limpiar() {
    db()->exec("DROP TABLE IF EXISTS sms_cola");
    db()->exec("DROP TABLE IF EXISTS sms_envios");
    db()->exec("DROP TABLE IF EXISTS sms_bajas");
    foreach (explode(';', file_get_contents(__DIR__ . '/sms_tablas.sql')) as $sql) {
        if (trim($sql) !== '') { db()->exec($sql); }
    }
}

/**
 * Mete una lista en la cola. Es la misma lógica que sms_api.php, extraída
 * aquí para poder probarla sin navegador.
 */
function encolar($lista, $texto = 'hola') {
    $cfg = sms_config();
    $db  = db();

    $telefonos = sms_extraer_telefonos($lista);
    if (!$telefonos) { return ['error' => 'sin numeros']; }

    $enLista = count($telefonos);

    $bajas = sms_buscar_en_columna($db, 'SELECT telefono FROM sms_bajas WHERE telefono IN', $telefonos);

    $repetidos = [];
    $horas = (int) $cfg['no_repetir_horas'];
    if ($horas > 0) {
        $repetidos = sms_buscar_en_columna($db,
            "SELECT DISTINCT telefono FROM sms_cola
              WHERE estado IN ('enviado','enviando','pendiente')
                AND actualizado > DATE_SUB(NOW(), INTERVAL {$horas} HOUR)
                AND telefono IN", $telefonos);
    }

    $telefonos = array_values(array_filter($telefonos, function ($t) use ($bajas, $repetidos) {
        return !isset($bajas[$t]) && !isset($repetidos[$t]);
    }));

    if (!$telefonos) { return ['error' => 'nada que mandar', 'en_la_lista' => $enLista]; }

    $db->prepare("INSERT INTO sms_envios (nombre, texto, total, estado, token, creado)
                  VALUES ('prueba', ?, 0, 'activo', ?, NOW())")
       ->execute([$texto, bin2hex(random_bytes(20))]);
    $envioId = (int) $db->lastInsertId();

    foreach (array_chunk($telefonos, 500) as $tanda) {
        $valores = implode(',', array_fill(0, count($tanda), '(?, ?, NOW())'));
        $datos = [];
        foreach ($tanda as $t) { $datos[] = $envioId; $datos[] = $t; }
        $db->prepare("INSERT IGNORE INTO sms_cola (envio_id, telefono, actualizado) VALUES {$valores}")
           ->execute($datos);
    }

    $st = $db->prepare("SELECT COUNT(*) FROM sms_cola WHERE envio_id = ?");
    $st->execute([$envioId]);
    $total = (int) $st->fetchColumn();
    $db->prepare("UPDATE sms_envios SET total = ? WHERE id = ?")->execute([$total, $envioId]);

    return ['envio_id' => $envioId, 'total' => $total, 'en_la_lista' => $enLista,
            'bajas' => count($bajas), 'repetidos' => count($repetidos)];
}

/** Vacía la cola llamando al trabajador tantas veces como haga falta. */
function trabajar($pasadasMax = 200) {
    $pasadas = 0;
    while ($pasadas < $pasadasMax) {
        $antes = (int) db()->query("SELECT COUNT(*) FROM sms_cola WHERE estado='pendiente'")->fetchColumn();
        if ($antes === 0) { break; }
        passthru('SMS_CONFIG=' . escapeshellarg(getenv('SMS_CONFIG'))
                 . ' php ' . escapeshellarg(__DIR__ . '/sms_worker.php') . ' > /dev/null 2>&1');
        $pasadas++;
    }
    return $pasadas;
}

/* ================================================================== */

echo "\n  PRUEBAS DE LA COLA (base de datos real)\n\n";

limpiar();

/* --- 1. Un SMS por número, aunque la lista venga repetida --- */
$lista = "600111222\n600111222\n+34600111222\n0034600111222\n600333444\n";
$r = encolar($lista);
comprobar('lista con el mismo número 4 veces → entra 1', $r['total'], 2);

/* --- 2. Se manda una sola vez a cada uno --- */
trabajar();
$enviados = (int) db()->query("SELECT COUNT(*) FROM sms_cola WHERE estado='enviado'")->fetchColumn();
comprobar('mensajes realmente enviados', $enviados, 2);

$porNumero = db()->query(
    "SELECT telefono, COUNT(*) n FROM sms_cola WHERE estado='enviado' GROUP BY telefono HAVING n > 1"
)->fetchAll();
comprobar('ningún número recibe más de uno', count($porNumero), 0);

/* --- 3. Segundo envío con los mismos números: se saltan --- */
$r2 = encolar("600111222\n600333444\n600555666\n");
comprobar('los que ya recibieron hoy se descartan', $r2['repetidos'], 2);
comprobar('solo entra el número nuevo', $r2['total'], 1);

/* --- 4. Las bajas se respetan --- */
sms_dar_de_baja('600777888');
$r3 = encolar("600777888\n600999000\n");
comprobar('el número de baja se descarta', $r3['bajas'], 1);
comprobar('solo entra el que no está de baja', $r3['total'], 1);

/* --- 5. SIN TOPE: una lista muy grande entra entera --- */
limpiar();
@unlink($LOG_FALSOS);      // el contador arranca de cero en este bloque
$grandes = [];
for ($i = 0; $i < 5000; $i++) { $grandes[] = '6' . str_pad((string) $i, 8, '0', STR_PAD_LEFT); }
$t0 = microtime(true);
$r4 = encolar(implode("\n", $grandes));
$tardo = round(microtime(true) - $t0, 2);
comprobar('5.000 números entran todos', $r4['total'], 5000);
echo "     (tardó {$tardo} s en encolarlos)\n";

/* --- 6. Y salen todos, una sola vez cada uno --- */
$pasadas = trabajar();
$enviados = (int) db()->query("SELECT COUNT(*) FROM sms_cola WHERE estado='enviado'")->fetchColumn();
comprobar('los 5.000 se envían', $enviados, 5000);

$dobles = db()->query(
    "SELECT telefono FROM sms_cola WHERE estado='enviado' GROUP BY telefono HAVING COUNT(*) > 1"
)->fetchAll();
comprobar('ninguno se manda dos veces', count($dobles), 0);

$reales = contarFalsos();
comprobar('la pasarela recibió exactamente 5.000 órdenes', $reales, 5000);

/* --- 7. Dos trabajadores a la vez no duplican --- */
limpiar();
@unlink($LOG_FALSOS);
encolar(implode("\n", array_slice($grandes, 0, 300)));

$cmd = 'SMS_CONFIG=' . escapeshellarg(getenv('SMS_CONFIG'))
     . ' php ' . escapeshellarg(__DIR__ . '/sms_worker.php') . ' > /dev/null 2>&1';
passthru($cmd . ' & ' . $cmd . ' & ' . $cmd . ' & wait');

$dobles = db()->query(
    "SELECT telefono FROM sms_cola WHERE estado='enviado' GROUP BY telefono HAVING COUNT(*) > 1"
)->fetchAll();
comprobar('tres trabajadores a la vez: ningún duplicado', count($dobles), 0);

$enviados = (int) db()->query("SELECT COUNT(*) FROM sms_cola WHERE estado='enviado'")->fetchColumn();
$reales   = contarFalsos();
comprobar('órdenes a la pasarela = mensajes marcados', $reales, $enviados);

echo "\n" . ($fallos === 0 ? "  TODO CORRECTO\n\n" : "  {$fallos} PRUEBAS FALLIDAS\n\n");
exit($fallos === 0 ? 0 : 1);
