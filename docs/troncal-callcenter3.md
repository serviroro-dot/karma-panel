# Troncal SIP `callcenter3` → 51.178.142.43

> **Estado: propuesta. NO aplicada.** Ningún cambio se ha ejecutado en la
> centralita de producción. Todo lo de aquí está pendiente de tu OK.
>
> **La contraseña no está en este repositorio.** Donde pone `<SECRETO>` va la
> clave que me pasaste por el chat.

---

## 1. Datos recibidos

| Campo | Valor |
|---|---|
| Servidor (host) | `51.178.142.43` |
| Puerto | `5060` |
| Transporte | UDP |
| Usuario / Auth | `callcenter3` |
| Contraseña | `<SECRETO>` |
| Registro | SÍ (1 teléfono) |
| Códecs | ulaw, alaw |

---

## 2. Esto no es un troncal de operador

Evidencia, no suposición:

1. **DNS inverso de la IP:**

   ```
   51.178.142.43 → vps-36f72249.vps.ovh.net
   ```

   Es un VPS de OVH, no un rango de red de un operador SIP. Un ITSP no
   entrega servicio desde un `vps-*.vps.ovh.net`; una centralita alojada en
   un VPS, sí.

2. **«Registro: SÍ (1 teléfono)».** Un troncal se dimensiona en *llamadas
   simultáneas* y trae *rango de DIDs*. Que la cuenta se describa como
   «1 teléfono» es la firma de una **extensión**, de un dispositivo.

3. **El usuario de autenticación es un nombre** (`callcenter3`), no un
   número ni un DID. Los operadores identifican la cuenta por número o por
   ID de cliente.

**Conclusión:** son credenciales de **usuario/extensión SIP en otra
centralita**. El protocolo es el mismo (REGISTER + INVITE con auth digest),
por eso se puede montar como troncal y funcionará — pero se comportará
como una extensión, con estas consecuencias:

| | Troncal de operador | Esta cuenta |
|---|---|---|
| Llamadas simultáneas | Las contratadas (p. ej. 4, 10, 30) | **1** (según «1 teléfono») |
| Entrantes | Por DID, tú enrutas | Solo cuando la PBX remota decida timbrar a `callcenter3` |
| DID en la entrante | El número llamado | Probablemente `callcenter3` (o la extensión remota) |
| CallerID saliente | Lo fijas tú | **Lo decide la PBX remota**; tu «Outbound CallerID» será ignorado |
| Qué puedes marcar | Numeración E.164 | Lo que permita el plan de marcación de la PBX remota |
| Registro | Estable | Si un teléfono físico usa las mismas credenciales, **pelea de registro** |

El punto crítico en producción es el último: **no compartas `callcenter3`
con un teléfono físico**. Con un solo contacto permitido, el último que
registra echa al anterior y el servicio va y viene sin patrón aparente.

---

## 3. Opción A — FreePBX / Asterisk (chan_pjsip)

`chan_sip` está obsoleto desde Asterisk 17 y **eliminado en Asterisk 21**.
Si tu FreePBX es reciente, va por `pjsip` sí o sí.

### 3.1 Por GUI (Connectivity → Trunks → Add SIP (chan_pjsip) Trunk)

**Pestaña General**

| Campo | Valor |
|---|---|
| Trunk Name | `callcenter3` |
| Outbound CallerID | *(vacío — lo impone el remoto)* |
| Maximum Channels | `1` |
| Disable Trunk | No |

**pjsip Settings → General**

| Campo | Valor |
|---|---|
| Username | `callcenter3` |
| Auth username | `callcenter3` |
| Secret | `<SECRETO>` |
| Authentication | Outbound |
| Registration | Send |
| SIP Server | `51.178.142.43` |
| SIP Server Port | `5060` |
| Transport | el transporte UDP definido (`0.0.0.0-udp`) |
| Context | `from-pstn` |

**pjsip Settings → Advanced**

| Campo | Valor | Por qué |
|---|---|---|
| From Domain | `51.178.142.43` | Sin esto el `From:` sale con tu IP y muchos servidores rechazan con 403/404 |
| From User | `callcenter3` | |
| Contact User | `callcenter3` | Para que el remoto sepa a quién enviar la entrante |
| Qualify Frequency | `60` | OPTIONS cada 60 s: mantiene abierto el pinhole NAT/firewall |
| DTMF Mode | RFC 4733 | **A confirmar contigo** |
| Direct Media | No | |
| Rewrite Contact | Yes | |
| RTP Symmetric / Force rport | Yes | |
| Media Encryption | None (UDP plano) | |

**Pestaña Codecs:** deja **solo `ulaw` y `alaw`**, desmarca el resto
(g722, gsm, g726…).

> Ojo con el orden: el primero es el preferido. Tú pediste `ulaw, alaw`,
> pero en España lo habitual es **alaw (G.711A)** primero. Si el remoto es
> español, poner ulaw delante puede forzar transcodificación innecesaria.
> Confírmame el orden que quieres.

### 3.2 Configuración equivalente en crudo

Para Asterisk pelado, o como referencia de lo que debe generar FreePBX.
En FreePBX **no edites `pjsip.conf`** (lo reescribe `fwconsole reload`);
si hiciera falta algo a mano, va en `pjsip_custom.conf`.

```ini
[callcenter3]
type=registration
transport=0.0.0.0-udp
outbound_auth=callcenter3
server_uri=sip:51.178.142.43:5060
client_uri=sip:callcenter3@51.178.142.43:5060
contact_user=callcenter3
expiration=3600
retry_interval=60
forbidden_retry_interval=300
line=yes
endpoint=callcenter3

[callcenter3]
type=auth
auth_type=userpass
username=callcenter3
password=<SECRETO>

[callcenter3]
type=aor
contact=sip:51.178.142.43:5060
qualify_frequency=60

[callcenter3]
type=endpoint
transport=0.0.0.0-udp
context=from-pstn
disallow=all
allow=ulaw,alaw
outbound_auth=callcenter3
aors=callcenter3
from_user=callcenter3
from_domain=51.178.142.43
dtmf_mode=rfc4733
direct_media=no
rtp_symmetric=yes
force_rport=yes
rewrite_contact=yes
language=es
```

`line=yes` + `endpoint=` en la sección `registration` hace que las llamadas
entrantes que llegan por ese registro se asocien solas a este endpoint. Es
más fiable que un `type=identify` por IP, y **obligatorio** si algún día
tienes dos troncales contra la misma IP (ahí `identify` sería ambiguo).

### 3.3 Ruta entrante

La entrante **no traerá un DID normal**. Antes de crear la Inbound Route
hay que ver con qué llega realmente (paso 5). Con lo que sepamos:

- Si llega con `callcenter3` → Inbound Route con DID `callcenter3`.
- Si no hay forma de distinguirlo → ruta con DID/CID vacíos (*any*), que
  captura todo lo que entre por ese troncal.

---

## 4. Opción B — FusionPBX / FreeSWITCH

**Accounts → Gateways → Add**

| Campo | Valor |
|---|---|
| Gateway | `callcenter3` |
| Username | `callcenter3` |
| Password | `<SECRETO>` |
| From User | `callcenter3` |
| From Domain | `51.178.142.43` |
| Proxy | `51.178.142.43:5060` |
| Realm | `51.178.142.43` |
| Register | `true` |
| Register Transport | `udp` |
| Expire Seconds | `600` |
| Retry Seconds | `30` |
| Ping | `30` (mantiene NAT abierto) |
| Context | `public` |
| Profile | `external` |
| Enabled | `true` |

**Códecs:** el formulario de gateway de FusionPBX no tiene campo de códecs.
Se limita en uno de estos dos sitios:

- En el perfil `external`: `inbound-codec-prefs` / `outbound-codec-prefs` →
  `PCMU,PCMA` (afecta a todo lo que salga por ese perfil), o
- Por llamada, en la ruta de salida: `absolute_codec_string=PCMU,PCMA`

Aplicar (esto **sí** toca producción, espera tu OK):

```bash
fs_cli -x "sofia profile external rescan reloadxml"
```

---

## 5. Verificación — comandos de solo lectura

Ninguno de estos modifica nada.

### FreePBX / Asterisk

```bash
# Versiones (necesito estas dos)
fwconsole --version
asterisk -rx "core show version"

# ¿pjsip o chan_sip?
asterisk -rx "module show like res_pjsip.so"
asterisk -rx "module show like chan_sip.so"

# Estado del registro y del endpoint
asterisk -rx "pjsip show registrations"
asterisk -rx "pjsip show registration callcenter3"
asterisk -rx "pjsip show endpoint callcenter3"
asterisk -rx "pjsip show aor callcenter3"
```

### Traza SIP real (para ver qué manda el remoto)

```bash
# Opción 1: traza de Asterisk
asterisk -rx "pjsip set logger host 51.178.142.43"
# ...provocar una llamada entrante...
asterisk -rx "pjsip set logger off"

# Opción 2: captura en el interfaz (mejor para diagnosticar)
tcpdump -nni any -s0 udp port 5060 and host 51.178.142.43 -w /tmp/cc3.pcap
# o, si tienes sngrep instalado:
sngrep -d any port 5060 and host 51.178.142.43
```

De la traza necesito el `INVITE` entrante: **Request-URI, `To:` y `From:`**.
Eso decide cómo se construye la ruta entrante y si el CallerID es utilizable.

### FusionPBX / FreeSWITCH

```bash
fs_cli -x "version"
fs_cli -x "sofia status"
fs_cli -x "sofia status gateway callcenter3"   # en FusionPBX puede listarse por UUID
```

---

## 6. Cortafuegos

Si el FreePBX tiene el módulo **Firewall** activo con Responsive Firewall,
`51.178.142.43` debe estar en zona **Trusted**, o los OPTIONS/INVITE del
remoto acabarán bloqueados y el troncal caerá de forma intermitente.

Comprobación (solo lectura):

```bash
fwconsole firewall list
iptables -L -n | head -50
fail2ban-client status asterisk
```

Además: RTP necesita **UDP 10000-20000** abierto hacia esa IP en los dos
sentidos. Si solo abres 5060, hay señalización pero **audio en un solo
sentido o mudo**.

---

## 7. Datos que me faltan para dejarlo fino

1. **¿En qué servidor va?** FreePBX/Asterisk o FusionPBX/FreeSWITCH — la
   configuración no se parece en nada. Y su versión.
2. **¿Qué es `51.178.142.43`?** El rDNS dice que es un VPS de OVH. ¿Es tuyo
   (otra centralita tuya) o de un tercero?
3. **¿Cuántas llamadas simultáneas admite la cuenta?** Si de verdad es 1,
   hay que poner `Maximum Channels = 1` para no generar rechazos.
4. **¿Cómo entran las llamadas?** ¿Hay número/DID asociado? Necesito el
   `INVITE` entrante (paso 5).
5. **¿Qué formato hay que marcar para salir?** E.164 (`+34…`), nacional
   (`9…`), con prefijo, o extensiones internas del remoto.
6. **¿Hay ya un teléfono físico registrado con `callcenter3`?** Si lo hay,
   pide credenciales separadas antes de montar esto.
7. **¿Tu centralita tiene IP pública directa o está detrás de NAT?** Si hay
   NAT hace falta `external_media_address` / `external_signaling_address`.
8. **¿El realm/dominio SIP del remoto es la IP o un dominio?** Cambia
   `from_domain` y el realm de autenticación.
9. **DTMF y orden de códecs**: ¿RFC 4733? ¿`alaw` primero o `ulaw` primero?

---

## 8. Seguridad

La contraseña de `callcenter3` ha viajado por el chat en texto plano. No
está guardada en este repositorio a propósito. Si ese canal no es privado,
rótala en el servidor remoto antes de poner el troncal en producción.

---

## 9. Verificación en vivo del servidor (2026-09-07)

Salida real obtenida por SSH en `51.178.142.43` (root@vps-36f72249):

| Dato | Valor verificado |
|---|---|
| SO | Debian GNU/Linux 12 (bookworm) |
| FreePBX | **17.0.33** (`fwconsole` responde) |
| Asterisk | **22.8.2** |
| FreeSWITCH | `inactive` — este servidor **no** es el FusionPBX |
| Endpoint `callcenter3` | **Existe** en este Asterisk |
| Auth | `InAuth: callcenter3/callcenter3` → el servidor **recibe** el registro |
| AOR | `max_contacts = 1` (el «1 teléfono» de la hoja) |
| Estado | `Unavailable, 0 of inf` → **nadie registrado ahora mismo** |
| Transporte | `0.0.0.0-udp`, puerto 5060 |

### Lectura

`51.178.142.43` es el **FreePBX 17**. La cuenta `callcenter3` no es un
troncal hacia fuera: es una cuenta local con autenticación **entrante**
(patrón de extensión de FreePBX: endpoint + inauth + aor con
`max_contacts=1`). La «hoja de troncal» del inicio son en realidad las
credenciales para que otro equipo **se registre contra este FreePBX**.

Como `chan_sip` no existe en Asterisk 22, todo lo que se haga aquí es
pjsip obligatoriamente (coherente con la sección 3).

### Queda pendiente

1. IP del FusionPBX (la otra centralita, la que consumirá el troncal).
2. Clasificar `callcenter3`: extensión (`context=from-internal`) o troncal
   con registro entrante (`context=from-pstn`/`from-trunk`). Comando:
   `asterisk -rx "pjsip show endpoint callcenter3" | grep -Ei "context|allow|callerid|max_contacts"`
3. Decisión de diseño: troncal IP-a-IP sin registro (recomendado) o
   registro del FusionPBX contra este FreePBX con una cuenta de troncal.

---

## 10. Segunda verificación: callcenter3 SÍ es un troncal (2026-09-07)

Volcado real de `/etc/asterisk/pjsip.*.conf` en el FreePBX:

```ini
; pjsip.auth.conf
[callcenter3]
type=auth
auth_type=userpass
password=<OCULTA>
username=callcenter3

; pjsip.endpoint.conf
[callcenter3]
type=endpoint
transport=0.0.0.0-udp
context=from-pstn          ; ← patrón de TRONCAL, no de extensión
disallow=all
allow=ulaw,alaw,gsm,g726,g722,h264,mpeg4
aors=callcenter3
auth=callcenter3           ; ← autenticación ENTRANTE (recibe el registro)
language=es
direct_media=no
rtp_symmetric=yes
```

### Corrección al análisis de la sección 2/9

`callcenter3` **sí está definido como troncal** en FreePBX: troncal
chan_pjsip con *Registration: Receive* + *Authentication: Inbound* y
`context=from-pstn` (las llamadas que entren por él van a Inbound Routes).

Y el matiz clave sobre el «1 teléfono»: `max_contacts=1` limita el número
de **registros** (un solo equipo conectado a la vez), **no** las llamadas
simultáneas. El propio `pjsip show endpoint` marca `0 of inf`: canales
**ilimitados**. Un único peer registrado puede cursar N llamadas a la vez
por este troncal tal y como está.

### Requisito de la usuaria

Troncal **solo de entrada**, con muchas llamadas simultáneas. El troncal
existente ya lo cumple a nivel de capacidad; queda por resolver:

1. **Quién es el otro extremo** que debe registrarse como `callcenter3`
   (¿proveedor externo de numeración? ¿el FusionPBX propio?) — decide el
   resto del diseño.
2. Las Inbound Routes del FreePBX para lo que entre.
3. Limpieza recomendada de códecs: dejar `alaw,ulaw` (sobran gsm, g726,
   g722 y los de vídeo h264/mpeg4).
