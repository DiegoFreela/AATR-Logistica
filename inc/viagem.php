<?php
/* ============================================================
   AATR — regras da viagem
   ------------------------------------------------------------
   Toda decisão sobre o que pode ou não acontecer numa viagem
   mora aqui, do lado do servidor. O celular do motorista só
   pede; quem autoriza é este arquivo.
   ============================================================ */

require_once __DIR__ . '/geo.php';

/** Erro de regra de negócio — vira mensagem amigável na tela. */
class ViagemErro extends Exception
{
}

const VIAGEM_STATUS = [
    'agendada'  => 'Aguardando início',
    'em_viagem' => 'Em viagem',
    'concluida' => 'Concluída',
    'cancelada' => 'Cancelada',
];

/* ------------------------------------------------------------
   Leitura
   ------------------------------------------------------------ */

const VIAGEM_CAMPOS = 'v.*, m.nome AS motorista_nome, m.veiculo AS motorista_veiculo,
                       m.usuario AS motorista_usuario, m.telefone AS motorista_fone';

function viagem_por_codigo(string $codigo): ?array
{
    return db_linha(
        'SELECT ' . VIAGEM_CAMPOS . '
           FROM viagens v
           LEFT JOIN motoristas m ON m.id = v.motorista_id
          WHERE v.codigo = ?',
        [so_codigo($codigo)]
    );
}

function viagem_por_id(int $id): ?array
{
    return db_linha(
        'SELECT ' . VIAGEM_CAMPOS . '
           FROM viagens v
           LEFT JOIN motoristas m ON m.id = v.motorista_id
          WHERE v.id = ?',
        [$id]
    );
}

/** Viagens que este motorista pode operar hoje. */
function viagens_do_motorista(int $motoristaId): array
{
    return db_todas(
        "SELECT * FROM viagens
          WHERE motorista_id = ?
            AND status IN ('agendada','em_viagem')
       ORDER BY FIELD(status,'em_viagem','agendada'), COALESCE(previsao_carregamento, criado_em)",
        [$motoristaId]
    );
}

function viagem_eventos(int $viagemId): array
{
    return db_todas(
        'SELECT * FROM eventos WHERE viagem_id = ? ORDER BY criado_em, id',
        [$viagemId]
    );
}

/** Última posição registrada, ou null. */
function viagem_ultima_posicao(int $viagemId): ?array
{
    return db_linha(
        "SELECT * FROM eventos
          WHERE viagem_id = ? AND tipo = 'posicao' AND lat IS NOT NULL
       ORDER BY criado_em DESC, id DESC LIMIT 1",
        [$viagemId]
    );
}

/* ------------------------------------------------------------
   Cálculos de acompanhamento
   ------------------------------------------------------------ */

/**
 * Quanto falta, em km, de um ponto até o destino da viagem.
 *
 * IMPORTANTE: é linha reta entre o caminhão e o destino, corrigida
 * pelo FATOR_ESTRADA. Serve para o contratante ter noção ("falta
 * 126 km"), não é leitura de odômetro. Em rota com muita curva o
 * número fica otimista. Devolve null se faltar coordenada do destino.
 */
function viagem_restante_km(array $viagem, float $lat, float $lon): ?int
{
    if ($viagem['destino_lat'] === null || $viagem['destino_lon'] === null) {
        return null;
    }
    $km = haversine($lat, $lon, (float)$viagem['destino_lat'], (float)$viagem['destino_lon'])
        * (float)FATOR_ESTRADA;

    // nunca dizer que falta mais do que a viagem inteira
    if (!empty($viagem['distancia_km'])) {
        $km = min($km, (float)$viagem['distancia_km']);
    }
    return (int)round($km);
}

/** 0 a 100, para a barra de progresso do contratante. */
function viagem_progresso(array $viagem): int
{
    if ($viagem['status'] === 'concluida') {
        return 100;
    }
    if ($viagem['status'] !== 'em_viagem') {
        return 0;
    }

    $total = (int)($viagem['distancia_km'] ?? 0);
    $pos   = viagem_ultima_posicao((int)$viagem['id']);

    if ($total > 0 && $pos && $pos['restante_km'] !== null) {
        $pct = (1 - ((int)$pos['restante_km'] / $total)) * 100;
        return (int)max(4, min(97, round($pct)));
    }

    // iniciou mas ainda não mandou posição
    return 6;
}

/**
 * Previsão de chegada = ponto de partida + tempo de viagem.
 *
 * O que muda é o ponto de partida, nesta ordem:
 *   1. já saiu  -> a hora real da saída (é o melhor dado que existe);
 *   2. não saiu -> a previsão de carregamento que o gestor cadastrou.
 *
 * Assim o contratante já vê uma previsão no momento em que a viagem
 * é programada, e ela se ajusta sozinha quando o caminhão sai de fato.
 * Sem tempo de viagem, ou sem nenhum dos dois pontos de partida, não
 * há previsão — e a tela diz isso em vez de inventar uma data.
 *
 * Devolve ['em' => 'Y-m-d H:i:s', 'base' => 'saida'|'carregamento'] ou null.
 */
function viagem_previsao_chegada(array $viagem): ?array
{
    if (empty($viagem['duracao_min'])) {
        return null;
    }

    if (!empty($viagem['iniciada_em'])) {
        $partida = $viagem['iniciada_em'];
        $base    = 'saida';
    } elseif (!empty($viagem['previsao_carregamento'])) {
        $partida = $viagem['previsao_carregamento'];
        $base    = 'carregamento';
    } else {
        return null;
    }

    $t = strtotime((string)$partida);
    if ($t === false) {
        return null;
    }

    return [
        'em'   => date('Y-m-d H:i:s', $t + ((int)$viagem['duracao_min'] * 60)),
        'base' => $base,
    ];
}

/** Explica de onde saiu a previsão, para não parecer número mágico. */
function viagem_previsao_nota(string $base): string
{
    return $base === 'saida'
        ? 'contando a partir da saída real'
        : 'contando a partir do carregamento programado';
}

/* ------------------------------------------------------------
   Escrita — o passo a passo da linha do tempo
   ------------------------------------------------------------ */

function viagem_registrar_evento(int $viagemId, string $tipo, string $titulo, array $extra = []): int
{
    return db_inserir(
        'INSERT INTO eventos
            (viagem_id, tipo, titulo, recado, lat, lon, precisao_m, restante_km, origem_registro, criado_em)
         VALUES (?,?,?,?,?,?,?,?,?,?)',
        [
            $viagemId,
            $tipo,
            encurtar($titulo, 160),
            encurtar($extra['recado'] ?? '', 255),
            $extra['lat'] ?? null,
            $extra['lon'] ?? null,
            $extra['precisao_m'] ?? null,
            $extra['restante_km'] ?? null,
            $extra['origem_registro'] ?? 'motorista',
            agora(),
        ]
    );
}

/**
 * Confere se este motorista pode mexer nesta viagem.
 * @throws ViagemErro
 */
function viagem_exigir_dono(array $viagem, int $motoristaId): void
{
    if ((int)$viagem['motorista_id'] !== $motoristaId) {
        throw new ViagemErro('Esta viagem está atribuída a outro motorista. Fale com a programação.');
    }
}

/**
 * Motorista aperta "Iniciar viagem".
 * @throws ViagemErro
 */
function viagem_iniciar(array $viagem, int $motoristaId): array
{
    viagem_exigir_dono($viagem, $motoristaId);

    if ($viagem['status'] === 'em_viagem') {
        throw new ViagemErro('Esta viagem já foi iniciada em ' . fmt_datahora($viagem['iniciada_em']) . '.');
    }
    if ($viagem['status'] === 'concluida') {
        throw new ViagemErro('Esta viagem já foi encerrada. Não dá para iniciar de novo.');
    }
    if ($viagem['status'] === 'cancelada') {
        throw new ViagemErro('Esta viagem foi cancelada pela programação.');
    }

    /* O "AND status='agendada'" faz a troca de estado ser atômica: se
       o motorista tocar duas vezes e as duas chegarem juntas, só uma
       altera a linha — a outra sai com rowCount 0 e é recusada aqui,
       antes de gravar um segundo "viagem iniciada" na linha do tempo. */
    $alterou = db_exec(
        "UPDATE viagens SET status='em_viagem', iniciada_em=?, atualizado_em=? WHERE id=? AND status='agendada'",
        [agora(), agora(), $viagem['id']]
    )->rowCount();

    if ($alterou === 0) {
        throw new ViagemErro('Esta viagem já foi iniciada.');
    }

    viagem_registrar_evento((int)$viagem['id'], 'inicio', 'Viagem iniciada em ' . $viagem['origem'], [
        'lat'         => $viagem['origem_lat'],
        'lon'         => $viagem['origem_lon'],
        'restante_km' => $viagem['distancia_km'],
    ]);

    return viagem_por_id((int)$viagem['id']);
}

/**
 * Motorista manda a posição atual.
 * @throws ViagemErro
 */
function viagem_registrar_posicao(array $viagem, int $motoristaId, float $lat, float $lon, ?int $precisao, string $recado): array
{
    viagem_exigir_dono($viagem, $motoristaId);

    if ($viagem['status'] === 'agendada') {
        throw new ViagemErro('Aperte "Iniciar viagem" antes de mandar a localização.');
    }
    if ($viagem['status'] === 'concluida') {
        throw new ViagemErro('Esta viagem já foi encerrada.');
    }
    if ($viagem['status'] === 'cancelada') {
        throw new ViagemErro('Esta viagem foi cancelada pela programação.');
    }
    if ($lat < -34 || $lat > 6 || $lon < -74 || $lon > -34) {
        throw new ViagemErro('A localização recebida está fora do Brasil. Pegue o GPS de novo.');
    }

    $restante = viagem_restante_km($viagem, $lat, $lon);
    $titulo   = $recado !== '' ? $recado : 'Posição atualizada';

    $id = viagem_registrar_evento((int)$viagem['id'], 'posicao', $titulo, [
        'recado'      => $recado,
        'lat'         => $lat,
        'lon'         => $lon,
        'precisao_m'  => $precisao,
        'restante_km' => $restante,
    ]);

    db_exec('UPDATE viagens SET atualizado_em=? WHERE id=?', [agora(), $viagem['id']]);

    return ['evento_id' => $id, 'restante_km' => $restante];
}

/**
 * Motorista aperta "Cheguei no destino".
 * @throws ViagemErro
 */
function viagem_concluir(array $viagem, int $motoristaId, string $recado = ''): array
{
    viagem_exigir_dono($viagem, $motoristaId);

    if ($viagem['status'] === 'agendada') {
        throw new ViagemErro('Esta viagem ainda não foi iniciada.');
    }
    if ($viagem['status'] === 'concluida') {
        throw new ViagemErro('Esta viagem já foi encerrada em ' . fmt_datahora($viagem['concluida_em']) . '.');
    }
    if ($viagem['status'] === 'cancelada') {
        throw new ViagemErro('Esta viagem foi cancelada pela programação.');
    }

    $alterou = db_exec(
        "UPDATE viagens SET status='concluida', concluida_em=?, atualizado_em=? WHERE id=? AND status='em_viagem'",
        [agora(), agora(), $viagem['id']]
    )->rowCount();

    if ($alterou === 0) {
        throw new ViagemErro('Esta viagem já foi encerrada.');
    }

    viagem_registrar_evento((int)$viagem['id'], 'chegada', 'Chegada em ' . $viagem['destino'], [
        'recado'      => $recado,
        'lat'         => $viagem['destino_lat'],
        'lon'         => $viagem['destino_lon'],
        'restante_km' => 0,
    ]);

    return viagem_por_id((int)$viagem['id']);
}

/* ------------------------------------------------------------
   Apresentação
   ------------------------------------------------------------ */

/** Ícone de cada passo na linha do tempo. */
function evento_icone(string $tipo): string
{
    switch ($tipo) {
        case 'criada':  return '📋';
        case 'inicio':  return '🚦';
        case 'posicao': return '📍';
        case 'chegada': return '📦';
        default:        return '•';
    }
}

/** Link do Google Maps para um evento com coordenada. */
function evento_mapa(array $ev): ?string
{
    if ($ev['lat'] === null || $ev['lon'] === null) {
        return null;
    }
    return 'https://www.google.com/maps?q=' . number_format((float)$ev['lat'], 6, '.', '')
         . ',' . number_format((float)$ev['lon'], 6, '.', '');
}

/**
 * Monta a viagem inteira no formato que a página de rastreio e a
 * API consomem. É a mesma fonte para os dois, então o que o
 * contratante vê na página é exatamente o que a API devolve.
 */
function viagem_para_api(array $v): array
{
    $eventos = viagem_eventos((int)$v['id']);
    $linha   = [];

    foreach ($eventos as $ev) {
        $linha[] = [
            'tipo'        => $ev['tipo'],
            'icone'       => evento_icone($ev['tipo']),
            'titulo'      => $ev['titulo'],
            'recado'      => $ev['recado'],
            'quando'      => fmt_datahora($ev['criado_em']),
            'quando_iso'  => date('c', strtotime($ev['criado_em'])),
            'desde'       => fmt_desde($ev['criado_em']),
            'restante_km' => $ev['restante_km'] !== null ? (int)$ev['restante_km'] : null,
            'precisao_m'  => $ev['precisao_m'] !== null ? (int)$ev['precisao_m'] : null,
            'mapa'        => evento_mapa($ev),
        ];
    }

    $prev = viagem_previsao_chegada($v);

    return [
        'ok'            => true,
        'codigo'        => $v['codigo'],
        'status'        => $v['status'],
        'status_label'  => VIAGEM_STATUS[$v['status']] ?? $v['status'],
        'origem'        => $v['origem'],
        'destino'       => $v['destino'],
        'distancia_km'  => $v['distancia_km'] !== null ? (int)$v['distancia_km'] : null,
        'duracao_min'   => $v['duracao_min'] !== null ? (int)$v['duracao_min'] : null,
        'duracao_label' => fmt_duracao($v['duracao_min']),
        'veiculo'       => $v['veiculo'] ?: ($v['motorista_veiculo'] ?? ''),
        'carga'         => $v['carga'],
        'peso_t'        => $v['peso_t'] !== null ? (float)$v['peso_t'] : null,
        'motorista'     => $v['motorista_nome'] ?? '',
        'progresso'     => viagem_progresso($v),
        'carregamento'  => $v['previsao_carregamento'] ? fmt_datahora($v['previsao_carregamento'], true) : null,
        'iniciada_em'   => $v['iniciada_em'] ? fmt_datahora($v['iniciada_em'], true) : null,
        'concluida_em'  => $v['concluida_em'] ? fmt_datahora($v['concluida_em'], true) : null,
        'previsao'      => $prev ? fmt_datahora($prev['em'], true) : null,
        'previsao_nota' => $prev ? viagem_previsao_nota($prev['base']) : null,
        'atualizado'    => fmt_desde($v['atualizado_em'] ?: $v['criado_em']),
        'eventos'       => $linha,
    ];
}
