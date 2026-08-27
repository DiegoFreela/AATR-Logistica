<?php
/* ============================================================
   AATR — funções de apoio
   ------------------------------------------------------------
   Tudo aqui roda em PHP puro, sem biblioteca externa e sem
   depender de extensão que hospedagem compartilhada às vezes
   não tem (mbstring, intl). Onde a extensão ajuda, usamos;
   quando falta, há plano B.
   ============================================================ */

/* ---------- texto ---------- */

/** Escapa para imprimir dentro do HTML. Use SEMPRE ao ecoar dado do banco. */
function h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Maiúsculas com acento, sem exigir mbstring. */
function maiusc(string $s): string
{
    if (function_exists('mb_strtoupper')) {
        return mb_strtoupper($s, 'UTF-8');
    }
    $mapa = ['á'=>'Á','à'=>'À','ã'=>'Ã','â'=>'Â','é'=>'É','ê'=>'Ê','í'=>'Í',
             'ó'=>'Ó','ô'=>'Ô','õ'=>'Õ','ú'=>'Ú','ü'=>'Ü','ç'=>'Ç'];
    return strtoupper(strtr($s, $mapa));
}

/** Minúsculas com acento, sem exigir mbstring. */
function minusc(string $s): string
{
    if (function_exists('mb_strtolower')) {
        return mb_strtolower($s, 'UTF-8');
    }
    $mapa = ['Á'=>'á','À'=>'à','Ã'=>'ã','Â'=>'â','É'=>'é','Ê'=>'ê','Í'=>'í',
             'Ó'=>'ó','Ô'=>'ô','Õ'=>'õ','Ú'=>'ú','Ü'=>'ü','Ç'=>'ç'];
    return strtolower(strtr($s, $mapa));
}

/** Corta o texto respeitando UTF-8, com reticências. */
function encurtar(string $s, int $max): string
{
    if (function_exists('mb_strlen')) {
        return mb_strlen($s, 'UTF-8') <= $max ? $s : rtrim(mb_substr($s, 0, $max - 1, 'UTF-8')) . '…';
    }
    return strlen($s) <= $max ? $s : rtrim(substr($s, 0, $max - 1)) . '…';
}

/** Tamanho em caracteres, com plano B sem mbstring. */
function tamanho(string $s): int
{
    return function_exists('mb_strlen') ? mb_strlen($s, 'UTF-8') : strlen($s);
}

/** Remove acentos e deixa só letras, números e hífen. Usado no código da viagem. */
function so_codigo(string $s): string
{
    $s = maiusc(trim($s));
    $s = strtr($s, ['Á'=>'A','À'=>'A','Ã'=>'A','Â'=>'A','É'=>'E','Ê'=>'E','Í'=>'I',
                    'Ó'=>'O','Ô'=>'O','Õ'=>'O','Ú'=>'U','Ü'=>'U','Ç'=>'C']);
    return preg_replace('/[^A-Z0-9\-]/', '', $s) ?? '';
}

/* ---------- entrada ---------- */

/** Lê um campo de POST já com trim. */
function post(string $chave, string $padrao = ''): string
{
    return isset($_POST[$chave]) && is_scalar($_POST[$chave]) ? trim((string)$_POST[$chave]) : $padrao;
}

/** Lê um campo de GET já com trim. */
function get(string $chave, string $padrao = ''): string
{
    return isset($_GET[$chave]) && is_scalar($_GET[$chave]) ? trim((string)$_GET[$chave]) : $padrao;
}

/** Lê o corpo JSON de uma requisição da área do motorista. */
function corpo_json(): array
{
    $bruto = file_get_contents('php://input');
    if ($bruto === false || $bruto === '') {
        return [];
    }
    $dados = json_decode($bruto, true);
    return is_array($dados) ? $dados : [];
}

/* ---------- telefone ---------- */

function so_digitos($v): string
{
    return preg_replace('/\D/', '', (string)$v) ?? '';
}

/**
 * Normaliza para o formato que o wa.me aceita: 55 + DDD + número,
 * só dígitos. Devolve '' se não parecer um telefone brasileiro.
 */
function fone_normalizar($v): string
{
    $d = so_digitos($v);
    if ($d === '') {
        return '';
    }
    if (strpos($d, '55') === 0 && (strlen($d) === 12 || strlen($d) === 13)) {
        return $d;
    }
    if (strlen($d) === 10 || strlen($d) === 11) {
        return '55' . $d;
    }
    return '';
}

/** (11) 96910-4308 a partir de 5511969104308. */
function fone_exibir(string $d): string
{
    $n = preg_replace('/^55/', '', so_digitos($d));
    if (strlen($n) === 11) {
        return '(' . substr($n, 0, 2) . ') ' . substr($n, 2, 5) . '-' . substr($n, 7);
    }
    if (strlen($n) === 10) {
        return '(' . substr($n, 0, 2) . ') ' . substr($n, 2, 4) . '-' . substr($n, 6);
    }
    return $d;
}

/* ---------- números e datas ---------- */

function agora(): string
{
    return date('Y-m-d H:i:s');
}

/** "612 km" */
function fmt_km($km): string
{
    if ($km === null || $km === '') {
        return '—';
    }
    return number_format((float)$km, 0, ',', '.') . ' km';
}

/** 570 minutos -> "9h30". */
function fmt_duracao($min): string
{
    if ($min === null || $min === '') {
        return '—';
    }
    $min = (int)round((float)$min);
    $h = intdiv($min, 60);
    $m = $min % 60;
    if ($h <= 0) {
        return $m . ' min';
    }
    return $m > 0 ? $h . 'h' . str_pad((string)$m, 2, '0', STR_PAD_LEFT) : $h . 'h';
}

/** "25/08 às 14:32" */
function fmt_datahora($sql, bool $comAno = false): string
{
    if (!$sql) {
        return '—';
    }
    $t = strtotime((string)$sql);
    if ($t === false) {
        return '—';
    }
    return date($comAno ? 'd/m/Y \à\s H:i' : 'd/m \à\s H:i', $t);
}

/** "há 12 min", "há 3 h", "há 2 dias" */
function fmt_desde($sql): string
{
    if (!$sql) {
        return '';
    }
    $t = strtotime((string)$sql);
    if ($t === false) {
        return '';
    }
    $s = time() - $t;
    if ($s < 60)     return 'agora há pouco';
    if ($s < 3600)   return 'há ' . intdiv($s, 60) . ' min';
    if ($s < 86400)  return 'há ' . intdiv($s, 3600) . ' h';
    $d = intdiv($s, 86400);
    return 'há ' . $d . ($d === 1 ? ' dia' : ' dias');
}

/* ---------- respostas ---------- */

function json_out(array $dados, int $codigo = 200): void
{
    if (!headers_sent()) {
        http_response_code($codigo);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
    }
    echo json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function json_erro(string $mensagem, int $codigo = 400): void
{
    json_out(['ok' => false, 'erro' => $mensagem], $codigo);
}

function redirecionar(string $url): void
{
    if (!headers_sent()) {
        header('Location: ' . $url, true, 302);
    }
    exit;
}

/* ---------- ambiente ---------- */

function requisicao_segura(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    // Hospedagem atrás de proxy/CDN
    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
        return true;
    }
    return (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;
}

function ip_cliente(): string
{
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $chave) {
        if (!empty($_SERVER[$chave])) {
            $ip = trim(explode(',', (string)$_SERVER[$chave])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }
    return '0.0.0.0';
}

/** Link do site montado a partir de SITE_URL, sem barra dupla. */
function url_site(string $caminho = ''): string
{
    return rtrim(SITE_URL, '/') . '/' . ltrim($caminho, '/');
}

/** Link público de rastreio de uma viagem. */
function url_rastreio(string $codigo): string
{
    return url_site('rastreio.php?codigo=' . rawurlencode($codigo));
}
