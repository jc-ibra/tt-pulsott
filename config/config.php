<?php
// Configuración del sistema. AQUÍ NO VA NINGUNA CREDENCIAL:
// contraseñas y tokens viven en el archivo .env (raíz del proyecto), que no se sube al repositorio.
require_once __DIR__ . '/../app/env.php';
cargarEnv(__DIR__ . '/../.env');

return [
    'db' => [
        'host'    => env('DB_HOST', '127.0.0.1'),
        'puerto'  => (int)env('DB_PORT', '3306'),
        'nombre'  => env('DB_NAME', 'pulsott'),
        'usuario' => env('DB_USER', ''),
        'clave'   => env('DB_PASSWORD', ''),
    ],
    // Conexión con GLPI (botón "Estatus GLPI"), solo lectura. Pide al administrador de GLPI:
    //   1) Configuración > General > API: activar "API REST" y "Iniciar sesión con token externo".
    //   2) Agregar un cliente de API con la IP de este servidor: de ahí sale GLPI_APP_TOKEN.
    //   3) Un usuario de GLPI con permiso SOLO DE LECTURA de tickets: "Token de API" (GLPI_USER_TOKEN).
    'glpi' => [
        'url'             => env('GLPI_URL', ''),
        'app_token'       => env('GLPI_APP_TOKEN', ''),
        'user_token'      => env('GLPI_USER_TOKEN', ''),
        'verificar_ssl'   => envBool('GLPI_VERIFY_SSL', true), // solo false si GLPI usa un certificado interno
        'segundos_espera' => (int)env('GLPI_TIMEOUT', '30'),
    ],

    // Cuántos respaldos por proyecto se guardan en proyectos_versiones.
    'versiones_a_conservar' => 50,

    'seguridad' => [
        'minutos_inactividad'   => 120, // sin actividad, la sesión se cierra
        'horas_sesion_maxima'   => 12,  // aunque haya actividad, se pide entrar de nuevo
        'intentos_por_usuario'  => 5,   // intentos fallidos antes de bloquear la cuenta
        'minutos_bloqueo'       => 15,
        'intentos_por_ip'       => 20,  // intentos fallidos desde una misma IP en 15 minutos
        'largo_minimo_clave'    => 10,
        // Ponlo en true cuando el sistema se use con https:// (certificado instalado en Apache).
        'forzar_https'          => false,
    ],
];
