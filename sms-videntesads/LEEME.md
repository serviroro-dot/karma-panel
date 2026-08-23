# Envío de SMS — hoja nueva, sin envíos dobles

Sustituye la hoja de SMS del panel. Arregla el envío doble y añade la cola,
la pantalla en vivo y la lista de bajas.

---

## Un solo SMS por número, y sin tope de cuántos mandas

**A cada número le llega uno y solo uno.** Hay tres cierres, uno detrás de otro:

1. Al pegar la lista se quitan los repetidos, y da igual cómo estén escritos:
   `600111222`, `+34600111222` y `0034600111222` son el mismo teléfono y
   cuentan como uno.
2. La tabla tiene un candado (índice único) que impide físicamente meter el
   mismo número dos veces en el mismo envío, aunque algo fallara antes.
3. Si el mismo teléfono aparece en **otro envío distinto** y ya recibió algo
   en las últimas horas, se salta. Lo controla `no_repetir_horas` en la
   configuración: 24 significa un mensaje por número y día. Ponlo a 0 si
   alguna vez quieres poder mandarle dos cosas distintas el mismo día.

**No hay tope de cuántos cargas.** Puedes pegar 700, 5.000 o 50.000: entran
todos de una vez. Lo comprobé con 5.000 y tardan una décima de segundo en
quedar encolados.

Lo único que no depende de este programa es la **velocidad de salida**, que la
marca Twilio: con un número normal, un mensaje por segundo. Eso no es un tope
de cuántos puedes mandar, sino del rato que tardan en salir. 700 son unos 12
minutos; 5.000, hora y media. Mientras tanto no tienes que hacer nada.

---

## Por qué ya no se puede mandar un SMS dos veces

El fallo que tienes ahora se puede colar por cuatro sitios distintos. Aquí
están tapados los cuatro, uno detrás de otro, para que si uno fallara los
demás sigan aguantando:

**1. En el botón.** El botón no lleva `onclick` en el HTML *y además* un
escuchador en el JavaScript — que es la causa más habitual: un solo clic
disparaba dos envíos. Aquí el aviso se engancha en un único sitio. Además,
en cuanto pulsas se echa un cerrojo (`enviando = true`) y el botón se
deshabilita, así que un doble toque en el móvil no hace nada.

**2. En la petición.** Cada vez que se abre la pantalla se genera una ficha
única (un *token*). Si por lo que fuera llegaran dos peticiones iguales, el
servidor ve que la ficha ya está usada y devuelve el envío que ya existía en
vez de crear otro. Esto es lo que salva el caso de "se me fue el dedo" o de
una recarga con el POST reenviado.

**3. En la lista.** La tabla tiene un índice único por envío y teléfono. Aunque
pegues la lista con números repetidos, cada uno entra una sola vez. Los
duplicados se limpian antes, y el índice es la red por debajo.

**4. Al enviar.** El trabajador *reserva* las filas con un `UPDATE` antes de
mandarlas. Esa operación es atómica: si dos procesos coincidieran, solo uno
se queda con cada fila. Es lo que impide que dos pasadas del cron manden lo
mismo.

---

## Instalación (una vez)

**1. Sube los archivos** a la misma carpeta donde está `chat.php`:

```
sms_config.php     ← el único que hay que rellenar
sms_lib.php
sms_api.php
sms_panel.php
sms_worker.php
sms_tablas.sql
```

**2. Crea las tablas.** Desde phpMyAdmin, pestaña *Importar*, sube
`sms_tablas.sql`. O por consola:

```bash
mysql -u USUARIO -p BASE_DE_DATOS < sms_tablas.sql
```

**3. Rellena `sms_config.php`**: los datos de la base de datos (los mismos que
ya usa la web), el proveedor de SMS con sus claves, y una contraseña para
entrar al panel.

**4. Pon el cron.** Es lo que va mandando los mensajes. `crontab -e` y añade:

```
* * * * * /usr/bin/php /ruta/completa/al/sitio/sms_worker.php >> /var/log/sms_worker.log 2>&1
```

Cambia `/ruta/completa/al/sitio/` por la carpeta de verdad. Comprueba que
funciona lanzándolo a mano una vez:

```bash
php /ruta/completa/al/sitio/sms_worker.php
```

**5. Entra** en `https://videntesads.com/sms_panel.php`.

---

## Cómo se usa

Escribes el mensaje, pegas los números (uno por línea o separados por comas,
da igual el formato: `600111222`, `+34600111222` o `0034600111222` valen), y
le das a enviar.

A partir de ahí puedes cerrar el ordenador. Los mensajes salen solos.

La pantalla en vivo te enseña cuántos llevas, cuántos quedan, cuántos han
fallado y por qué, y los últimos con su ✓ o su ✗. Se refresca sola cada tres
segundos. Puedes pausar y reanudar cuando quieras.

**Cuánto tarda.** A un mensaje por segundo: 700 mensajes son unos 12 minutos.
5.000 serían hora y media. Si tu proveedor te permite más velocidad, sube
`por_segundo` en la configuración.

**Si se corta la luz** o se reinicia el servidor, al volver sigue por donde
iba. Ni repite ni se salta ninguno.

---

## Las bajas

Quien pida la baja se guarda en la tabla `sms_bajas` y la cola lo salta
siempre, en éste y en todos los envíos futuros. Al preparar un envío te dice
cuántos ha descartado por esto.

Aparte de que en España es obligatorio ofrecer la baja, es lo que evita que a
la primera queja el proveedor te corte la cuenta y te quedes sin poder mandar
nada. Conviene terminar los mensajes con algo tipo `Responde BAJA para no
recibir más`.

Para dar de baja a mano, desde la propia web:

```
POST sms_api.php   accion=baja   telefono=600111222
```

---

## Arreglar el `chat.php` que ya tienes

Si prefieres reparar el actual en vez de cambiar de hoja, el envío doble está
casi seguro en uno de estos tres sitios. Desde la carpeta del sitio:

```bash
# 1. ¿El botón tiene el aviso puesto dos veces?
grep -n "onclick\|onsubmit\|addEventListener" chat.php

# 2. ¿El mismo .js está incluido dos veces?
grep -n "<script" chat.php | sort

# 3. ¿El PHP llama dos veces a la pasarela?
grep -n "curl_exec\|file_get_contents\|->messages->create\|enviarSms" chat.php
```

Lo típico es lo primero: el botón lleva `onclick="enviar()"` en el HTML y
además un `addEventListener('click', enviar)` en el JavaScript. Se quita uno
de los dos y se acabó el problema.

Antes de tocar nada, copia de seguridad:

```bash
cp chat.php chat.php.bak-$(date +%F)
```

---

## Comprobarlo tú mismo

No hace falta creerse nada. Hay dos bancos de pruebas incluidos:

```bash
# La cola: un SMS por número, 5.000 de una vez, varios procesos a la vez.
# Necesita una base de datos de pruebas; ajusta los datos de arriba del archivo.
php probar_cola.php

# El blindaje del navegador: reproduce el envío doble y comprueba que se corta.
# Necesita Node y Chromium.
node probar_anti_doble.js
```

Al escribir esto, las dos baterías pasan enteras: trece comprobaciones cada
una. La de la cola incluye una prueba con **5.000 números** y otra lanzando
**tres trabajadores a la vez**, para confirmar que ni así se duplica ninguno.
