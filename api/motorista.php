<?php
/* ============================================================
   AATR — ações do motorista
   ------------------------------------------------------------
   POST api/motorista.php  (JSON, com o cabeçalho X-CSRF)
   Ações: viagens | iniciar | posicao | chegada

   Regra de ouro: quem decide se a ação vale é o servidor.
   O celular só pede. Antes de abrir o WhatsApp, o registro
   já está gravado no banco — então a linha do tempo do
   contratante não depende de o motorista lembrar de enviar
   a mensagem.
   ============================================================ */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/viagem.php';

header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_erro('Método não permitido.', 405);
}

$motorista = exigir_motorista_api();

$token = $_SERVER['HTTP_X_CSRF'] ?? '';
if (!csrf_valido($token)) {
    json_erro('Sua sessão expirou. Recarregue a página e entre de novo.', 419);
}

$dados = corpo_json();
$acao  = isset($dados['acao']) ? (string)$dados['acao'] : '';

/* ------------------------------------------------------------
   Lista as viagens que este motorista pode operar
   ------------------------------------------------------------ */
if ($acao === 'viagens') {
    $lista = [];
    foreach (viagens_do_motorista((int)$motorista['id']) as $v) {
        $lista[] = viagem_resumo($v);
    }
    json_out(['ok' => true, 'viagens' => $lista]);
}

/* ------------------------------------------------------------
   Da aqui para baixo, toda ação precisa de uma viagem
   ------------------------------------------------------------ */
$codigo = so_codigo((string)($dados['codigo'] ?? ''));
if ($codigo === '') {
    json_erro('Escolha a viagem antes de continuar.', 400);
}

$viagem = viagem_por_codigo($codigo);
if (!$viagem) {
    json_erro('Viagem não encontrada. Fale com a programação.', 404);
}

try {
    switch ($acao) {

        case 'iniciar':
            $viagem = viagem_iniciar($viagem, (int)$motorista['id']);
            json_out([
                'ok'       => true,
                'mensagem' => 'Viagem iniciada. Boa estrada!',
                'viagem'   => viagem_resumo($viagem),
                'whatsapp' => monta_whatsapp($viagem, 'inicio'),
            ]);
            // no break — json_out encerra

        case 'posicao':
            $lat = isset($dados['lat']) ? (float)$dados['lat'] : null;
            $lon = isset($dados['lon']) ? (float)$dados['lon'] : null;
            if ($lat === null || $lon === null || ($lat == 0.0 && $lon == 0.0)) {
                json_erro('Pegue a localização antes de enviar.', 400);
            }
            $precisao = isset($dados['precisao']) ? (int)$dados['precisao'] : null;
            $recado   = encurtar(trim((string)($dados['recado'] ?? '')), 255);

            $r = viagem_registrar_posicao($viagem, (int)$motorista['id'], $lat, $lon, $precisao, $recado);
            $viagem = viagem_por_id((int)$viagem['id']);

            json_out([
                'ok'          => true,
                'mensagem'    => 'Localização registrada na viagem.',
                'restante_km' => $r['restante_km'],
                'viagem'      => viagem_resumo($viagem),
                'whatsapp'    => monta_whatsapp($viagem, 'posicao', [
                    'lat' => $lat, 'lon' => $lon, 'precisao' => $precisao, 'recado' => $recado,
                ]),
            ]);

        case 'chegada':
            $recado = encurtar(trim((string)($dados['recado'] ?? '')), 255);
            $viagem = viagem_concluir($viagem, (int)$motorista['id'], $recado);
            json_out([
                'ok'       => true,
                'mensagem' => 'Chegada registrada. Viagem encerrada.',
                'viagem'   => viagem_resumo($viagem),
                'whatsapp' => monta_whatsapp($viagem, 'chegada', ['recado' => $recado]),
            ]);

        default:
            json_erro('Ação desconhecida.', 400);
    }
} catch (ViagemErro $e) {
    // regra de negócio recusou — mensagem já é amigável
    json_erro($e->getMessage(), 409);
} catch (Throwable $e) {
    if (APP_DEBUG) {
        json_erro('Erro: ' . $e->getMessage(), 500);
    }
    json_erro('Não foi possível registrar agora. Tente de novo em instantes.', 500);
}

/* ============================================================
   Apoio
   ============================================================ */

/** Versão enxuta da viagem, para o painel do celular. */
function viagem_resumo(array $v): array
{
    return [
        'codigo'       => $v['codigo'],
        'status'       => $v['status'],
        'status_label' => VIAGEM_STATUS[$v['status']] ?? $v['status'],
        'origem'       => $v['origem'],
        'destino'      => $v['destino'],
        'distancia_km' => $v['distancia_km'] !== null ? (int)$v['distancia_km'] : null,
        'duracao'      => fmt_duracao($v['duracao_min']),
        'carga'        => $v['carga'],
        'contratante'  => $v['contratante_nome'],
        'fone'         => $v['contratante_fone'],
        'fone_exibir'  => $v['contratante_fone'] ? fone_exibir($v['contratante_fone']) : '',
        'progresso'    => viagem_progresso($v),
        'rastreio'     => url_rastreio($v['codigo']),
    ];
}

/**
 * Monta o texto que vai para o WhatsApp do contratante.
 * Sem acento, para não quebrar em aparelho antigo.
 */
function monta_whatsapp(array $v, string $tipo, array $extra = []): array
{
    $quando = date('d/m/Y') . ' as ' . date('H:i');
    $linhas = ['AATR Transporte e Logistica'];

    switch ($tipo) {
        case 'inicio':
            $linhas[] = 'VIAGEM INICIADA';
            break;
        case 'chegada':
            $linhas[] = 'CHEGADA NO DESTINO';
            break;
        default:
            $linhas[] = 'Localizacao atual da sua carga';
    }

    $linhas[] = '';
    $linhas[] = 'Viagem: ' . $v['codigo'];
    $linhas[] = 'Rota: ' . sem_acento($v['origem']) . ' -> ' . sem_acento($v['destino']);
    if (!empty($v['motorista_nome'])) {
        $linhas[] = 'Motorista: ' . sem_acento($v['motorista_nome']);
    }
    $veic = $v['veiculo'] ?: ($v['motorista_veiculo'] ?? '');
    if ($veic !== '') {
        $linhas[] = 'Veiculo: ' . sem_acento($veic);
    }
    $linhas[] = 'Horario: ' . $quando;

    if ($tipo === 'posicao' && isset($extra['lat'], $extra['lon'])) {
        if (!empty($extra['precisao'])) {
            $linhas[] = 'Precisao: aprox. ' . (int)$extra['precisao'] . ' m';
        }
        $pos = viagem_ultima_posicao((int)$v['id']);
        if ($pos && $pos['restante_km'] !== null) {
            $linhas[] = 'Falta aprox.: ' . (int)$pos['restante_km'] . ' km';
        }
    }

    if (!empty($extra['recado'])) {
        $linhas[] = 'Obs.: ' . sem_acento($extra['recado']);
    }

    if ($tipo === 'posicao' && isset($extra['lat'], $extra['lon'])) {
        $linhas[] = '';
        $linhas[] = 'Ponto no mapa: https://www.google.com/maps?q='
                  . number_format((float)$extra['lat'], 6, '.', '') . ','
                  . number_format((float)$extra['lon'], 6, '.', '');
    }

    $linhas[] = '';
    $linhas[] = 'Acompanhe a viagem: ' . url_rastreio($v['codigo']);

    return [
        'numero' => $v['contratante_fone'],
        'texto'  => implode("\n", $linhas),
    ];
}

/** Tira acento para a mensagem do WhatsApp. */
function sem_acento(string $s): string
{
    return strtr($s, [
        'á'=>'a','à'=>'a','ã'=>'a','â'=>'a','ä'=>'a','é'=>'e','ê'=>'e','è'=>'e',
        'í'=>'i','ì'=>'i','ó'=>'o','ô'=>'o','õ'=>'o','ò'=>'o','ö'=>'o',
        'ú'=>'u','ù'=>'u','ü'=>'u','ç'=>'c','ñ'=>'n',
        'Á'=>'A','À'=>'A','Ã'=>'A','Â'=>'A','É'=>'E','Ê'=>'E','Í'=>'I',
        'Ó'=>'O','Ô'=>'O','Õ'=>'O','Ú'=>'U','Ü'=>'U','Ç'=>'C','Ñ'=>'N',
    ]);
}
