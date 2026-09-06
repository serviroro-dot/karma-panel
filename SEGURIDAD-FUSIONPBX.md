# FusionPBX — qué no se toca y qué sí, al retirar el acceso de un técnico

> **Documento sanitizado a propósito.** No contiene IPs, puertos, dominios,
> nombres de usuario ni rutas propias de ninguna instalación concreta.
> Mientras este repositorio sea público, no debe añadirse ninguno.

---

## 1 · La regla de oro: son dos capas distintas

El error caro es tratar «seguridad» y «telefonía» como si fueran lo mismo.
No lo son, y en FusionPBX viven en sitios separados.

| | **Capa A — QUIÉN ENTRA** | **Capa B — CÓMO SUENAN LOS TELÉFONOS** |
|---|---|---|
| Qué es | Cuentas, contraseñas, llaves | Números, extensiones, rutas, audio |
| ¿Se toca al echar a un técnico? | **Sí. Es justo el trabajo.** | **No. Jamás sin petición expresa.** |
| Si te equivocas | Alguien no puede entrar al panel | **Se caen las llamadas** |

Tocar la capa A **no corta ni una sola llamada**. Borrar una llave SSH, cambiar
la contraseña del panel web o quitar una cuenta de Linux no interrumpe una
conversación en curso ni impide que entre la siguiente. FreeSWITCH ni se entera.

---

## 2 · Lista negra — no se toca

| Elemento | Por qué |
|---|---|
| Números de teléfono y geográficos | Se pierde el número de entrada |
| Extensiones | Los teléfonos dejan de registrarse |
| **Contraseñas SIP** | **Los teléfonos dejan de registrarse** |
| Troncales / gateways | Se corta la salida y la entrada |
| Perfiles SIP | Tira el servicio entero |
| Rutas y enrutamiento | Las llamadas dejan de llegar donde deben |
| RTP y puertos de voz | Llamadas mudas (se conectan y no hay audio) |
| Teléfonos / softphone | Fuera de tu control, se quedan sin línea |
| Nginx | El panel deja de responder |
| Ficheros de configuración de telefonía | Requiere recarga; recargar corta |
| Certificados / DNS | Rompe el acceso web y el SIP sobre TLS |

**Ojo con la trampa de las contraseñas.** En FusionPBX hay dos cosas que se
llaman «contraseña» y no tienen nada que ver:

- **Contraseña de usuario web** (el login del panel) → cambiarla es **seguro**.
- **Contraseña SIP de una extensión** (el secreto del teléfono) → cambiarla
  **deja el teléfono sin línea** hasta que se reconfigure el aparato.

Están en menús distintos. Confundirlas es la forma más rápida de tumbar una
centralita creyendo que estás asegurándola.

---

## 3 · Lista blanca — esto sí hay que tocar

Retirar a un técnico no es un solo cambio. Son **siete puertas**, y dejar una
abierta anula las otras seis.

1. **Llaves SSH** — `authorized_keys` de `root` y de cada cuenta con `/home`.
2. **Cuentas de sistema** — bloquear la cuenta y sacarla del grupo `sudo`.
3. **Reglas de `sudo`** — el fichero principal y el directorio `sudoers.d`.
   Un `NOPASSWD:ALL` olvidado devuelve el control entero.
4. **Usuarios del panel web** — tabla `v_users` de la base de datos.
5. **Claves de API** — columna `api_key` de esa misma tabla. Una clave de API
   entra **sin contraseña**: cambiar la contraseña no la revoca.
6. **Usuarios de la base de datos** — una cuenta de PostgreSQL con acceso por
   red es una puerta trasera completa.
7. **Tareas programadas** — `cron` y temporizadores de systemd. Una tarea puede
   volver a crear la llave borrada cada noche.

### Las tres puertas que casi nadie mira

Vaciar `authorized_keys` no basta si el propio servicio SSH está configurado
para buscar las llaves en otro sitio. Hay que revisar tres directivas:

- `AuthorizedKeysFile` — puede apuntar a una ruta alternativa.
- `AuthorizedKeysCommand` — ejecuta un programa que **genera** llaves válidas.
- `TrustedUserCAKeys` — acepta cualquier llave firmada por una autoridad.

Con cualquiera de las tres manipulada, se entra con los ficheros de llaves
completamente vacíos.

---

## 4 · Orden de trabajo

1. **Copia de seguridad, y bajarla fuera del servidor.** Antes de nada.
   Una copia que sigue dentro de la máquina no es una copia de seguridad.
2. **Mirar** — inventario completo de las siete puertas. Sin tocar.
3. **Cerrar** — llaves, cuentas, `sudo`, usuarios web, claves de API.
4. **Contraseñas nuevas** y llave propia instalada y **probada en una segunda
   sesión** antes de cerrar la primera.
5. **Verificar** — repetir el inventario del paso 2 y comparar.

> **Nunca cierres la sesión SSH que estás usando hasta haber abierto una
> segunda con las credenciales nuevas.** Si te equivocas, esa primera sesión
> abierta es lo único que te separa de la consola de rescate.

---

## 5 · Verificar es comparar, no confiar

Una comprobación solo vale si mira lo mismo que miraría el atacante.

| Error | Por qué falla | Cómo se hace bien |
|---|---|---|
| Buscar solo en TCP | Una VPN habitual escucha en **UDP** | Revisar ambos protocolos |
| `grep -r` | **No sigue enlaces simbólicos**, y las configuraciones web están llenas | Usar `grep -R` |
| Listar solo puertos a la escucha | Una shell inversa **sale**, no escucha | Revisar conexiones salientes establecidas |
| Barrer con `find -xdev` | **No entra en otras particiones montadas** | Barrer cada partición |
| Fiarse de los binarios | Un `sshd` manipulado supera todo lo demás | Verificar integridad de paquetes |

Y la regla de fondo: **no se afirma el estado de un sistema sin la salida del
comando delante.** «Está limpio» sin evidencia es una suposición disfrazada.
