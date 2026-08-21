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

    // Cuántos coge el trabajador en cada pasada. No lo toques salvo motivo.
    'lote' => 60,

    // Reintentos cuando el fallo es temporal (la pasarela caída, sin red...).
    'max_intentos' => 3,

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
