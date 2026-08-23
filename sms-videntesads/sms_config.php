<?php
/**
 * Configuración del envío de SMS.
 *
 * Esto es LO ÚNICO que hay que tocar. Rellena tus datos y guarda.
 */

return [

    // ---------------------------------------------------------------
    // 1) Base de datos (la misma que ya usa la web)
    // ---------------------------------------------------------------
    'db' => [
        'host'   => 'localhost',
        'nombre' => 'CAMBIAR_nombre_base_datos',
        'user'   => 'CAMBIAR_usuario',
        'pass'   => 'CAMBIAR_contrasena',
    ],

    // ---------------------------------------------------------------
    // 2) Proveedor de SMS
    //    Opciones: 'twilio' | 'labsmobile' | 'generico'
    //    Si tu proveedor no está, usa 'generico' y rellena el bloque
    //    de abajo copiando los datos de la documentación que te dieron.
    // ---------------------------------------------------------------
    'pasarela' => 'twilio',

    'twilio' => [
        'account_sid' => 'CAMBIAR_ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
        'auth_token'  => 'CAMBIAR_token',
        // El número o Messaging Service desde el que sales.
        // Número: '+34600000000'   ·   Messaging Service: 'MGxxxxxxxx'
        'remitente'   => 'CAMBIAR_+34600000000',
    ],

    'labsmobile' => [
        'usuario'   => 'CAMBIAR_email_de_la_cuenta',
        'token'     => 'CAMBIAR_api_token',
        // Nombre que verá el cliente. Máximo 11 caracteres, sin acentos.
        'remitente' => 'Videntes',
    ],

    // Para cualquier otro proveedor: copia de su documentación la URL y
    // los campos que pide. Usa {telefono} y {texto} donde correspondan.
    'generico' => [
        'url'       => 'https://api.tuproveedor.com/enviar',
        'metodo'    => 'POST',                       // POST o GET
        'formato'   => 'form',                       // 'form' o 'json'
        'cabeceras' => [
            // 'Authorization' => 'Bearer CAMBIAR_token',
        ],
        'campos'    => [
            // 'api_key'     => 'CAMBIAR_clave',
            // 'destino'     => '{telefono}',
            // 'mensaje'     => '{texto}',
            // 'remitente'   => 'Videntes',
        ],
        // Texto que aparece en la respuesta cuando el envío ha ido bien.
        // Si lo dejas vacío, se da por bueno cualquier HTTP 200.
        'exito_si_contiene' => '',
    ],

    // ---------------------------------------------------------------
    // 3) Ritmo de envío
    //    Ninguna pasarela admite 5.000 de golpe: te bloquean la cuenta.
    //    Esto los va soltando seguidos, a un ritmo que sí aceptan.
    // ---------------------------------------------------------------

    // Mensajes por segundo. Twilio con un número normal admite 1.
    // Si tu proveedor te permite más, súbelo.
    'por_segundo' => 1,

    // Reintentos cuando el fallo es temporal (la pasarela caída, sin red...).
    'max_intentos' => 3,

    // ---------------------------------------------------------------
    // UN SOLO SMS POR PERSONA: 700 números = 700 cobrados.
    //
    // Twilio no cobra por persona, cobra por trozo de mensaje. Un SMS
    // son 160 caracteres, pero SOLO si no llevas á, í, ó, ú, emojis ni
    // las comillas curvas que pone Word: con una sola de ésas el límite
    // baja a 70 y el mismo mensaje pasa a costar el doble.
    //
    // quitar_tildes: cambia á→a, í→i, ó→o, ú→u y las comillas raras, para
    //   que el mensaje quepa de verdad en 160. Deja ñ, ü, ç y é, que ésas
    //   sí caben.
    // forzar_un_sms: si aun así pasa de 160, no deja enviarlo y avisa de
    //   cuánto hay que recortar.
    //
    // Con las dos en true, 700 envíos son 700 mensajes cobrados, siempre.
    // ---------------------------------------------------------------
    'quitar_tildes' => true,
    'forzar_un_sms' => true,

    // ---------------------------------------------------------------
    // NO REPETIR: un mismo número no recibe dos veces.
    //
    // Dentro de un mismo envío nunca se repite, pase lo que pase. Esto
    // es para el caso de que el mismo teléfono aparezca en DOS envíos
    // distintos: si ya le mandaste algo en las últimas X horas, se salta.
    //
    // 24 = un mensaje por número y día.
    //  0 = desactivado (podría recibir de dos envíos distintos).
    // ---------------------------------------------------------------
    'no_repetir_horas' => 24,

    // ---------------------------------------------------------------
    // SIN LÍMITE de cuántos se cargan de una vez.
    //
    // Puedes pegar 700, 5.000 o 50.000: entran todos. Lo único que
    // cambia es el rato que tardan en salir, porque el ritmo lo marca
    // la pasarela ('por_segundo' de arriba), no este programa.
    //
    // Deja 0 para no poner tope. Si algún día quieres una red de
    // seguridad contra un pegado accidental, pon aquí un número.
    // ---------------------------------------------------------------
    'tope_por_envio' => 0,

    // ---------------------------------------------------------------
    // 4) País por defecto para los números que vengan sin prefijo
    //    34 = España · 1 = Estados Unidos
    // ---------------------------------------------------------------
    'prefijo_pais' => '34',

    // ---------------------------------------------------------------
    // 5) Contraseña para entrar al panel de SMS.
    //    Cámbiala. Si la dejas vacía, el panel queda abierto a cualquiera.
    // ---------------------------------------------------------------
    'clave_panel' => 'CAMBIAR_una_clave_tuya',
];
