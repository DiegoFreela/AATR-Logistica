<?php
/* ============================================================
   AATR — distância, tempo de viagem e coordenadas
   ------------------------------------------------------------
   Ordem de tentativa ao cadastrar uma viagem:
     1) rota real de estrada (OSRM), a partir das coordenadas;
     2) estimativa por linha reta corrigida (FATOR_ESTRADA);
     3) o que o gestor digitar na mão — sempre tem a palavra final.

   Os serviços usados (Nominatim e OSRM) são públicos e gratuitos.
   Se a hospedagem bloquear saída para a internet, nada quebra:
   as funções devolvem null e o cadastro segue no manual.
   ============================================================ */

/**
 * GET simples que devolve JSON decodificado, ou null.
 * Usa cURL quando existe; senão tenta file_get_contents.
 */
function http_json(string $url): ?array
{
    $ua = 'AATR-Transporte/1.0 (+' . SITE_URL . ')';

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => ROTA_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => ROTA_TIMEOUT,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_USERAGENT      => $ua,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);
        $corpo  = curl_exec($ch);
        $codigo = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($corpo === false || $codigo !== 200) {
            return null;
        }
    } elseif (ini_get('allow_url_fopen')) {
        $ctx = stream_context_create(['http' => [
            'timeout' => ROTA_TIMEOUT,
            'header'  => "User-Agent: {$ua}\r\nAccept: application/json\r\n",
        ]]);
        $corpo = @file_get_contents($url, false, $ctx);
        if ($corpo === false) {
            return null;
        }
    } else {
        return null;
    }

    $dados = json_decode((string)$corpo, true);
    return is_array($dados) ? $dados : null;
}

/**
 * Descobre latitude/longitude de "Jundiaí SP".
 * Devolve ['lat'=>float,'lon'=>float,'nome'=>string] ou null.
 */
function geocodificar(string $termo): ?array
{
    $termo = trim($termo);
    if ($termo === '' || !ROTA_ONLINE) {
        return null;
    }

    $url = 'https://nominatim.openstreetmap.org/search?format=json&limit=1&countrycodes=br&q='
         . rawurlencode($termo);

    $r = http_json($url);
    if (!$r || !isset($r[0]['lat'], $r[0]['lon'])) {
        return null;
    }

    return [
        'lat'  => (float)$r[0]['lat'],
        'lon'  => (float)$r[0]['lon'],
        'nome' => (string)($r[0]['display_name'] ?? $termo),
    ];
}

/** Distância em linha reta, em km (fórmula de haversine). */
function haversine(float $lat1, float $lon1, float $lat2, float $lon2): float
{
    $R = 6371.0088;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) ** 2
       + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
    return $R * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

/**
 * Rota real de estrada entre dois pontos.
 * Devolve ['km'=>int,'min'=>int] ou null se o serviço não responder.
 */
function rota_estrada(float $lat1, float $lon1, float $lat2, float $lon2): ?array
{
    if (!ROTA_ONLINE) {
        return null;
    }

    $url = sprintf(
        'https://router.project-osrm.org/route/v1/driving/%F,%F;%F,%F?overview=false&alternatives=false',
        $lon1, $lat1, $lon2, $lat2
    );

    $r = http_json($url);
    if (!$r || ($r['code'] ?? '') !== 'Ok' || !isset($r['routes'][0]['distance'], $r['routes'][0]['duration'])) {
        return null;
    }

    $km = (float)$r['routes'][0]['distance'] / 1000;
    // O OSRM público calcula para carro. Carga pesada roda mais devagar,
    // então recalculamos o tempo pela velocidade média da frota.
    $min = $km / max(1, (int)VELOCIDADE_MEDIA) * 60;

    return ['km' => (int)round($km), 'min' => (int)round($min)];
}

/** Estimativa por linha reta, quando não há rota real. */
function rota_estimada(float $lat1, float $lon1, float $lat2, float $lon2): array
{
    $km  = haversine($lat1, $lon1, $lat2, $lon2) * (float)FATOR_ESTRADA;
    $min = $km / max(1, (int)VELOCIDADE_MEDIA) * 60;
    return ['km' => (int)round($km), 'min' => (int)round($min)];
}

/**
 * Resolve origem e destino de uma vez.
 *
 * Devolve sempre um array com as chaves:
 *   km, min, fonte, origem_lat, origem_lon, destino_lat, destino_lon, aviso
 * Qualquer uma pode vir null — quem chama decide o que fazer.
 *
 * fonte: 'osrm' (rota real) | 'estimado' (linha reta) | 'indisponivel'
 */
function calcular_rota(string $origem, string $destino, ?array $coords = null): array
{
    $saida = [
        'km' => null, 'min' => null, 'fonte' => 'indisponivel',
        'origem_lat' => null, 'origem_lon' => null,
        'destino_lat' => null, 'destino_lon' => null,
        'aviso' => '',
    ];

    // coordenadas informadas na mão têm prioridade
    $o = null;
    $d = null;
    if ($coords && isset($coords['origem_lat'], $coords['origem_lon'])
        && $coords['origem_lat'] !== null && $coords['origem_lon'] !== null) {
        $o = ['lat' => (float)$coords['origem_lat'], 'lon' => (float)$coords['origem_lon']];
    }
    if ($coords && isset($coords['destino_lat'], $coords['destino_lon'])
        && $coords['destino_lat'] !== null && $coords['destino_lon'] !== null) {
        $d = ['lat' => (float)$coords['destino_lat'], 'lon' => (float)$coords['destino_lon']];
    }

    if (!$o) {
        $o = geocodificar($origem);
    }
    if (!$d) {
        $d = geocodificar($destino);
    }

    if (!$o || !$d) {
        $saida['aviso'] = 'Não foi possível localizar '
            . (!$o && !$d ? 'a origem e o destino' : (!$o ? 'a origem' : 'o destino'))
            . ' no mapa. Confira a grafia (ex.: "Jundiaí SP") ou preencha KM e tempo na mão.';
        if ($o) { $saida['origem_lat'] = $o['lat'];  $saida['origem_lon'] = $o['lon']; }
        if ($d) { $saida['destino_lat'] = $d['lat']; $saida['destino_lon'] = $d['lon']; }
        return $saida;
    }

    $saida['origem_lat']  = $o['lat'];
    $saida['origem_lon']  = $o['lon'];
    $saida['destino_lat'] = $d['lat'];
    $saida['destino_lon'] = $d['lon'];

    $rota = rota_estrada($o['lat'], $o['lon'], $d['lat'], $d['lon']);
    if ($rota) {
        $saida['km']    = $rota['km'];
        $saida['min']   = $rota['min'];
        $saida['fonte'] = 'osrm';
        return $saida;
    }

    $est = rota_estimada($o['lat'], $o['lon'], $d['lat'], $d['lon']);
    $saida['km']    = $est['km'];
    $saida['min']   = $est['min'];
    $saida['fonte'] = 'estimado';
    $saida['aviso'] = 'O serviço de rotas não respondeu. Estes KM são uma estimativa por '
                    . 'distância em linha reta — confira e ajuste se precisar.';
    return $saida;
}

/** Rótulo legível da fonte, para mostrar no admin. */
function rota_fonte_label(string $fonte): string
{
    switch ($fonte) {
        case 'osrm':     return 'rota real de estrada';
        case 'estimado': return 'estimativa por linha reta';
        case 'manual':   return 'informado pelo gestor';
        default:         return 'não calculado';
    }
}
