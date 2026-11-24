# Pulso y Seguimiento Operativo Trantor Technologies (PULSOTT)

Sistema web con usuarios y contraseñas que corre en XAMPP (Apache + PHP + MySQL).

## Cómo abrirlo

1. En el XAMPP Control Panel, enciende **Apache** y **MySQL**.
2. Abre <http://localhost/PULSOTT/> e inicia sesión.
3. Abajo a la izquierda verás tu nombre y tu rol.

Desde otra computadora de la red usa la IP de este equipo en lugar de `localhost`.

## Roles

| Rol | Puede |
|---|---|
| Administrador | Todo: capturar proyectos y seguimientos, borrar proyectos, administrar usuarios y ver la bitácora |
| Operador | Capturar y modificar proyectos |
| Consulta | Ver, filtrar y descargar (Excel y reportes) |
| Responsable de área | Ver proyectos y registrar comentarios, compromisos y folios GLPI en desviaciones de su área |

Las cuentas se dan de alta en **Usuarios** (menú del administrador). El sistema genera una
contraseña temporal que se muestra una sola vez; la persona debe cambiarla al entrar.
Al crear un responsable, el administrador asigna su área. Cada actualización queda en un historial
con el autor, comentario, dependencias para solucionar, fecha de captura, compromiso y, si aplica,
el folio GLPI. El responsable puede capturarla directamente desde **Desviaciones → su área →
Detalle de desviaciones → Documentar seguimiento**. El administrador también puede registrar seguimientos
en cualquier área desde ese mismo lugar. En ambos casos, se conserva el usuario que hizo la captura y el área de la desviación.
El responsable no puede modificar proyectos ni escribir en desviaciones de otras áreas. Los seguimientos
aparecen automáticamente en la ficha técnica del registro para consulta del equipo. La opción **Seguimiento**
del menú permite consultar y filtrar desviaciones e historiales; la captura se realiza únicamente desde
**Desviaciones** para evitar duplicar formularios.

## Seguridad incluida

- Contraseñas cifradas con `password_hash` (bcrypt); mínimo 10 caracteres con letras y números.
- Bloqueo de la cuenta 15 minutos tras 5 intentos fallidos, y límite de intentos por IP.
- Sesión con cookie `HttpOnly` y `SameSite=Strict`; se cierra tras 2 horas sin uso o 12 horas en total.
- Desactivar a alguien, cambiarle el rol o restablecer su contraseña cierra sus sesiones al instante.
- Token CSRF en todos los formularios y en cada guardado.
- La API revisa la sesión y el rol en cada petición; solo administradores borran proyectos.
- El servidor valida que cada seguimiento corresponda a una desviación vigente y a su área; el responsable solo puede escribir en su área y el administrador puede hacerlo en cualquiera.
- MySQL se usa con un usuario propio (`pulsott_app`) que solo puede leer y escribir en esta base.
- El HTML, `config/`, `app/`, `database/` y `respaldo/` no se pueden abrir desde el navegador.
- Bitácora de accesos, intentos fallidos, borrados y cambios de usuarios.
- Respaldo de los últimos 50 guardados de cada proyecto, con el usuario que guardó.

Los límites (minutos, intentos, largo de contraseña) se ajustan en `config/config.php`.

## Si se pierde el acceso del administrador

Desde una consola en esta carpeta:

```
C:\xampp\php\php.exe app\crear_admin.php usuario "Nombre completo"
```

Crea el administrador (o le restablece la contraseña) y muestra una contraseña temporal.

## Carpetas

| Ruta | Qué es |
|---|---|
| `index.php` | Entrada: pide sesión y muestra el sistema |
| `login.php`, `logout.php`, `cambiar_clave.php` | Acceso |
| `usuarios.php`, `bitacora.php` | Administración (solo administradores) |
| `seguimiento.php` | Consulta del historial de desviaciones por área |
| `PULSOTT.html` | La pantalla del sistema (se entrega solo desde `index.php`) |
| `api/index.php` | API que lee y guarda en la base de datos |
| `app/nucleo.php` | Sesión, seguridad y conexión compartidas |
| `.env` | **Credenciales** (contraseña de MySQL, tokens de GLPI). No se sube al repositorio (`.gitignore`) y el navegador no puede abrirlo. Plantilla: `.env.example`. |
| `config/config.php` | Ajustes del sistema y de seguridad. **Sin credenciales:** las lee del `.env`. |
| `database/schema.sql` | Crea la base `pulsott` y sus tablas |
| `respaldo/` | Copia del HTML original antes de integrarlo con el login |

## Instalar en otro equipo

1. Copia la carpeta a `C:\xampp\htdocs\`.
2. Ejecuta `database/schema.sql` en phpMyAdmin (pestaña SQL). En una base existente, vuelve a ejecutarlo para aplicar las columnas y tablas nuevas de responsables y seguimiento. Esta actualización agrega `dependencias` al historial sin borrar los registros guardados.
3. Crea en MySQL un usuario con permisos `SELECT, INSERT, UPDATE, DELETE` sobre `pulsott` y ponlo en el `.env` (copia `.env.example` como `.env` y llena `DB_USER` y `DB_PASSWORD`).
4. Crea el primer administrador con `app\crear_admin.php`.

## Recuperar un proyecto borrado o una versión anterior

En phpMyAdmin, busca en `proyectos_versiones` por `proyecto_id`. La columna `datos` tiene el proyecto
completo de ese momento, y `usuario` dice quién lo guardó.
