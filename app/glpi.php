<?php
/*
 * Conexión con la API REST de GLPI (apirest.php).
 * Los tokens viven solo en el archivo .env: el navegador nunca los ve.
 * SOLO LECTURA: únicamente abre sesión, consulta tickets y cierra sesión. No escribe nada en GLPI.
 *
 * Estados de ticket en GLPI:
 *   1 Nuevo · 2 En curso (asignado) · 3 En curso (planificado) · 4 En espera · 5 Resuelto · 6 Cerrado
 */
declare(strict_types=1);

const GLPI_ESTADOS = [
    1 => 'Nuevo',
    10 => 'Aprobación',
    2 => 'En curso (asignado)',
    3 => 'En curso (planificado)',
    4 => 'En espera',
    5 => 'Resuelto',
    6 => 'Cerrado',
];

final class GlpiError extends RuntimeException {}

final class Glpi
{
    private ?string $sesion = null;
    private string $base;

    public function __construct(private array $cfg)
    {
        $this->base = rtrim((string)($cfg['url'] ?? ''), '/');
        if (!str_ends_with($this->base, '/apirest.php')) {
            $this->base .= '/apirest.php';
        }
    }

    public static function configurado(array $cfg): bool
    {
        return trim((string)($cfg['url'] ?? '')) !== ''
            && trim((string)($cfg['app_token'] ?? '')) !== ''
            && trim((string)($cfg['user_token'] ?? '')) !== '';
    }

    private function curl(string $ruta): CurlHandle
    {
        $h = curl_init($this->base . $ruta);
        $enc = ['Content-Type: application/json', 'App-Token: ' . $this->cfg['app_token']];
        $enc[] = $this->sesion ? 'Session-Token: ' . $this->sesion : 'Authorization: user_token ' . $this->cfg['user_token'];
        curl_setopt_array($h, [
            CURLOPT_HTTPGET        => true,
            CURLOPT_HTTPHEADER     => $enc,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => (int)($this->cfg['segundos_espera'] ?? 30),
            CURLOPT_SSL_VERIFYPEER => (bool)($this->cfg['verificar_ssl'] ?? true),
            CURLOPT_SSL_VERIFYHOST => ($this->cfg['verificar_ssl'] ?? true) ? 2 : 0,
        ]);
        return $h;
    }

    /** @return array{0:int,1:mixed} código HTTP y respuesta decodificada */
    private function resultado(CurlHandle $h): array
    {
        $txt = curl_multi_getcontent($h) ?? '';
        if ($txt === '' && curl_errno($h)) {
            throw new GlpiError('No se pudo conectar con GLPI: ' . curl_error($h));
        }
        return [(int)curl_getinfo($h, CURLINFO_HTTP_CODE), json_decode($txt, true)];
    }

    private function pedir(string $ruta): array
    {
        $h = $this->curl($ruta);
        $txt = curl_exec($h);
        if ($txt === false) {
            $e = curl_error($h);
            curl_close($h);
            throw new GlpiError('No se pudo conectar con GLPI: ' . $e);
        }
        $codigo = (int)curl_getinfo($h, CURLINFO_HTTP_CODE);
        curl_close($h);
        return [$codigo, json_decode((string)$txt, true)];
    }

    private static function mensaje(mixed $r): string
    {
        if (is_array($r) && isset($r[1]) && is_string($r[1])) {
            return $r[1];
        }
        if (is_array($r) && isset($r[0]) && is_string($r[0])) {
            return $r[0];
        }
        return 'respuesta inesperada';
    }

    public function abrir(): void
    {
        [$c, $r] = $this->pedir('/initSession');
        if ($c !== 200 || empty($r['session_token'])) {
            throw new GlpiError('GLPI rechazó la conexión (' . $c . '): ' . self::mensaje($r) . '. Revisa GLPI_URL y los tokens en el archivo .env.');
        }
        $this->sesion = $r['session_token'];
    }

    public function cerrar(): void
    {
        if ($this->sesion) {
            try { $this->pedir('/killSession'); } catch (Throwable) {}
            $this->sesion = null;
        }
    }

    /**
     * Consulta varios tickets en paralelo (de 10 en 10).
     * @param int[] $ids
     * @return array<int,array{existe:bool,titulo?:string,estado?:int,estadoTxt?:string,error?:string}>
     */
    public function tickets(array $ids): array
    {
        $out = [];
        foreach (array_chunk($ids, 10) as $grupo) {
            $mh = curl_multi_init();
            $hs = [];
            foreach ($grupo as $id) {
                $hs[$id] = $this->curl('/Ticket/' . $id);
                curl_multi_add_handle($mh, $hs[$id]);
            }
            do {
                $st = curl_multi_exec($mh, $activos);
                if ($activos) {
                    curl_multi_select($mh, 1.0);
                }
            } while ($activos && $st === CURLM_OK);
            foreach ($hs as $id => $h) {
                try {
                    [$c, $r] = $this->resultado($h);
                    if ($c === 200 && is_array($r) && isset($r['id'])) {
                        $e = (int)($r['status'] ?? 0);
                        $out[$id] = ['existe' => true, 'titulo' => (string)($r['name'] ?? ''), 'estado' => $e, 'estadoTxt' => GLPI_ESTADOS[$e] ?? (string)$e,
                            'modificado' => !empty($r['date_mod']) ? date('d/m/Y H:i', strtotime((string)$r['date_mod'])) : ''];
                    } elseif ($c === 404) {
                        $out[$id] = ['existe' => false, 'error' => 'No existe en GLPI'];
                    } else {
                        $out[$id] = ['existe' => false, 'error' => 'GLPI respondió ' . $c . ': ' . self::mensaje($r)];
                    }
                } catch (GlpiError $e) {
                    $out[$id] = ['existe' => false, 'error' => $e->getMessage()];
                }
                curl_multi_remove_handle($mh, $h);
                curl_close($h);
            }
            curl_multi_close($mh);
        }
        return $out;
    }
}
