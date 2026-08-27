<?php
/* ============================================================
   AATR — cadastro e acompanhamento de uma viagem
   ------------------------------------------------------------
   Tudo o que define a viagem é decidido aqui, pelo gestor:
   código, contratante e o WhatsApp dele, origem, destino,
   motorista e carga. O motorista não cadastra nada — ele só
   opera os três botões no celular.
   ============================================================ */

require_once __DIR__ . '/_layout.php';
exigir_gestor();

$id     = (int)get('id');
$viagem = $id ? viagem_por_id($id) : null;

if ($id && !$viagem) {
    redirecionar('index.php?erro=' . rawurlencode('Viagem não encontrada.'));
}

$motoristas = db_todas('SELECT id, nome, usuario, veiculo FROM motoristas WHERE ativo = 1 ORDER BY nome');
$erros      = [];
$aviso      = '';

/* Valores que aparecem no formulário. */
$f = [
    'codigo'                => $viagem['codigo']                ?? '',
    'motorista_id'          => (string)($viagem['motorista_id'] ?? ''),
    'contratante_nome'      => $viagem['contratante_nome']      ?? '',
    'contratante_fone'      => isset($viagem['contratante_fone']) && $viagem['contratante_fone'] !== ''
                               ? fone_exibir($viagem['contratante_fone']) : '',
    'origem'                => $viagem['origem']                ?? '',
    'destino'               => $viagem['destino']               ?? '',
    'distancia_km'          => (string)($viagem['distancia_km'] ?? ''),
    'duracao_min'           => (string)($viagem['duracao_min']  ?? ''),
    'veiculo'               => $viagem['veiculo']               ?? '',
    'carga'                 => $viagem['carga']                 ?? '',
    'peso_t'                => (string)($viagem['peso_t']       ?? ''),
    'previsao_carregamento' => !empty($viagem['previsao_carregamento'])
                               ? date('Y-m-d\TH:i', strtotime($viagem['previsao_carregamento'])) : '',
    'origem_lat'            => (string)($viagem['origem_lat']   ?? ''),
    'origem_lon'            => (string)($viagem['origem_lon']   ?? ''),
    'destino_lat'           => (string)($viagem['destino_lat']  ?? ''),
    'destino_lon'           => (string)($viagem['destino_lon']  ?? ''),
];

/* ============================================================
   POST
   ============================================================ */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    exigir_csrf();
    $acao = post('acao', 'salvar');

    /* ---------- ações rápidas sobre uma viagem existente ---------- */
    if ($acao !== 'salvar') {
        if (!$viagem) {
            redirecionar('index.php?erro=' . rawurlencode('Viagem não encontrada.'));
        }

        if ($acao === 'cancelar') {
            db_exec("UPDATE viagens SET status='cancelada', atualizado_em=? WHERE id=?", [agora(), $viagem['id']]);
            viagem_registrar_evento($id, 'nota', 'Viagem cancelada pela programação', ['origem_registro' => 'gestor']);
            redirecionar('viagem.php?id=' . $id . '&ok=' . rawurlencode('Viagem cancelada.'));
        }

        if ($acao === 'reabrir') {
            db_exec("UPDATE viagens SET status='agendada', iniciada_em=NULL, concluida_em=NULL, atualizado_em=? WHERE id=?",
                    [agora(), $viagem['id']]);
            viagem_registrar_evento($id, 'nota', 'Viagem reaberta pela programação', ['origem_registro' => 'gestor']);
            redirecionar('viagem.php?id=' . $id . '&ok=' . rawurlencode('Viagem reaberta como agendada.'));
        }

        if ($acao === 'nota') {
            $texto = trim(post('nota'));
            if ($texto !== '') {
                viagem_registrar_evento($id, 'nota', $texto, ['origem_registro' => 'gestor']);
                db_exec('UPDATE viagens SET atualizado_em=? WHERE id=?', [agora(), $viagem['id']]);
                redirecionar('viagem.php?id=' . $id . '&ok=' . rawurlencode('Recado publicado na linha do tempo.'));
            }
            redirecionar('viagem.php?id=' . $id);
        }

        if ($acao === 'excluir') {
            db_exec('DELETE FROM viagens WHERE id=?', [$viagem['id']]);
            redirecionar('index.php?ok=' . rawurlencode('Viagem excluída.'));
        }

        redirecionar('viagem.php?id=' . $id);
    }

    /* ---------- salvar ---------- */
    foreach (array_keys($f) as $campo) {
        $f[$campo] = post($campo);
    }

    $f['codigo'] = so_codigo($f['codigo']);
    if ($f['codigo'] === '') {
        $f['codigo'] = gerar_codigo_viagem();
    }
    if (tamanho($f['codigo']) < 4) {
        $erros[] = 'O código da viagem precisa de pelo menos 4 caracteres.';
    }

    $duplicado = db_valor('SELECT id FROM viagens WHERE codigo = ? AND id <> ?', [$f['codigo'], $id]);
    if ($duplicado) {
        $erros[] = 'Já existe outra viagem com o código ' . $f['codigo'] . '.';
    }

    if ($f['contratante_nome'] === '') {
        $erros[] = 'Informe o nome do contratante.';
    }
    if ($f['origem'] === '') {
        $erros[] = 'Informe a cidade de carregamento.';
    }
    if ($f['destino'] === '') {
        $erros[] = 'Informe a cidade de descarga.';
    }

    $fone = fone_normalizar($f['contratante_fone']);
    if ($f['contratante_fone'] !== '' && $fone === '') {
        $erros[] = 'O WhatsApp do contratante não parece válido. Use DDD + número, ex.: (11) 96910-4308.';
    }

    $km  = $f['distancia_km'] !== '' ? (int)$f['distancia_km'] : null;
    $min = $f['duracao_min']  !== '' ? (int)$f['duracao_min']  : null;

    $coords = [
        'origem_lat'  => $f['origem_lat']  !== '' ? (float)$f['origem_lat']  : null,
        'origem_lon'  => $f['origem_lon']  !== '' ? (float)$f['origem_lon']  : null,
        'destino_lat' => $f['destino_lat'] !== '' ? (float)$f['destino_lat'] : null,
        'destino_lon' => $f['destino_lon'] !== '' ? (float)$f['destino_lon'] : null,
    ];
    $fonte = $viagem['rota_fonte'] ?? 'manual';

    /* Calcula a rota quando o gestor pediu, ou quando os campos
       estão vazios num cadastro novo. O que ele digitou na mão
       sempre vence: só preenchemos o que ficou em branco. */
    $querCalcular = post('calcular') !== '';

    if (!$erros && $querCalcular) {
        $r = calcular_rota($f['origem'], $f['destino'], $coords);

        foreach (['origem_lat', 'origem_lon', 'destino_lat', 'destino_lon'] as $c) {
            if ($r[$c] !== null) {
                $coords[$c] = $r[$c];
                $f[$c] = (string)$r[$c];
            }
        }

        if ($r['km'] !== null) {
            if ($km === null)  { $km  = $r['km'];  $f['distancia_km'] = (string)$km; }
            if ($min === null) { $min = $r['min']; $f['duracao_min']  = (string)$min; }
            $fonte = $r['fonte'];
        }
        if ($r['aviso'] !== '') {
            $aviso = $r['aviso'];
        }
    } elseif ($km !== null && (!$viagem || (int)$viagem['distancia_km'] !== $km)) {
        // gestor digitou uma distância diferente da que estava gravada
        $fonte = 'manual';
    }

    if (!$erros) {
        $dados = [
            'codigo'                => $f['codigo'],
            'motorista_id'          => $f['motorista_id'] !== '' ? (int)$f['motorista_id'] : null,
            'contratante_nome'      => encurtar($f['contratante_nome'], 140),
            'contratante_fone'      => $fone,
            'origem'                => encurtar($f['origem'], 160),
            'origem_lat'            => $coords['origem_lat'],
            'origem_lon'            => $coords['origem_lon'],
            'destino'               => encurtar($f['destino'], 160),
            'destino_lat'           => $coords['destino_lat'],
            'destino_lon'           => $coords['destino_lon'],
            'distancia_km'          => $km,
            'duracao_min'           => $min,
            'rota_fonte'            => $fonte,
            'veiculo'               => encurtar($f['veiculo'], 120),
            'carga'                 => encurtar($f['carga'], 255),
            'peso_t'                => $f['peso_t'] !== '' ? (float)$f['peso_t'] : null,
            'previsao_carregamento' => $f['previsao_carregamento'] !== ''
                                       ? date('Y-m-d H:i:s', strtotime($f['previsao_carregamento'])) : null,
        ];

        if ($viagem) {
            $sets = [];
            foreach (array_keys($dados) as $col) {
                $sets[] = "`$col` = ?";
            }
            $sets[] = '`atualizado_em` = ?';
            $valores = array_values($dados);
            $valores[] = agora();
            $valores[] = $viagem['id'];

            db_exec('UPDATE viagens SET ' . implode(', ', $sets) . ' WHERE id = ?', $valores);
            $novoId = (int)$viagem['id'];
            $recado = 'Viagem atualizada.';
        } else {
            $dados['status']    = 'agendada';
            $dados['criado_em'] = agora();
            $cols = array_keys($dados);
            $sql  = 'INSERT INTO viagens (`' . implode('`,`', $cols) . '`) VALUES ('
                  . rtrim(str_repeat('?,', count($cols)), ',') . ')';
            $novoId = db_inserir($sql, array_values($dados));

            viagem_registrar_evento($novoId, 'criada', 'Viagem programada pela AATR', ['origem_registro' => 'gestor']);
            $recado = 'Viagem ' . $f['codigo'] . ' cadastrada.';
        }

        if ($aviso !== '') {
            $recado .= ' ' . $aviso;
        }
        redirecionar('viagem.php?id=' . $novoId . '&ok=' . rawurlencode($recado));
    }
}

$eventos = $viagem ? viagem_eventos((int)$viagem['id']) : [];

admin_topo($viagem ? 'Viagem ' . $viagem['codigo'] : 'Nova viagem', $viagem ? '' : 'viagem.php');
admin_flash();

if ($erros) {
    echo '<div class="adm-flash erro"><b>Confira antes de salvar:</b><ul>';
    foreach ($erros as $e) {
        echo '<li>' . h($e) . '</li>';
    }
    echo '</ul></div>';
}
if ($aviso !== '' && !$erros) {
    echo '<p class="adm-flash alerta">' . h($aviso) . '</p>';
}
?>

<div class="adm-head">
  <div>
    <h1 class="adm-h1"><?= $viagem ? h($viagem['codigo']) : 'Nova viagem' ?></h1>
    <p class="adm-sub">
      <?php if ($viagem): ?>
        <?= h($viagem['origem']) ?> → <?= h($viagem['destino']) ?> ·
        <?= h(fmt_km($viagem['distancia_km'])) ?> ·
        <?= h(fmt_duracao($viagem['duracao_min'])) ?>
        <span class="adm-fonte">(<?= h(rota_fonte_label($viagem['rota_fonte'])) ?>)</span>
      <?php else: ?>
        O contratante acompanha por um link com este código. O motorista só opera os botões.
      <?php endif; ?>
    </p>
  </div>
  <?php if ($viagem): ?>
    <div class="adm-head-acoes">
      <?= status_pill($viagem['status']) ?>
      <a class="btn btn-ghost-adm" href="../rastreio.php?codigo=<?= rawurlencode($viagem['codigo']) ?>"
         target="_blank" rel="noopener">Ver como o contratante vê</a>
    </div>
  <?php endif; ?>
</div>

<div class="adm-colunas">

  <!-- ================= FORMULÁRIO ================= -->
  <section class="adm-card">
    <h2 class="adm-h2"><?= $viagem ? 'Dados da viagem' : 'Cadastrar viagem' ?></h2>

    <form method="post" action="viagem.php<?= $viagem ? '?id=' . (int)$viagem['id'] : '' ?>" novalidate>
      <?= csrf_campo() ?>
      <input type="hidden" name="acao" value="salvar">

      <div class="adm-grid">
        <div class="adm-field">
          <label for="codigo">Código da viagem</label>
          <input type="text" id="codigo" name="codigo" class="form-control mono"
                 placeholder="deixe em branco para gerar" value="<?= h($f['codigo']) ?>">
          <small>É o que o contratante digita para acompanhar.</small>
        </div>

        <div class="adm-field">
          <label for="motorista_id">Motorista</label>
          <select id="motorista_id" name="motorista_id" class="form-select">
            <option value="">— definir depois —</option>
            <?php foreach ($motoristas as $m): ?>
              <option value="<?= (int)$m['id'] ?>" <?= (string)$m['id'] === $f['motorista_id'] ? 'selected' : '' ?>>
                <?= h($m['nome']) ?> (<?= h($m['usuario']) ?>)
              </option>
            <?php endforeach; ?>
          </select>
          <small>Só ele enxerga e opera esta viagem no celular.</small>
        </div>

        <div class="adm-field">
          <label for="contratante_nome">Contratante</label>
          <input type="text" id="contratante_nome" name="contratante_nome" class="form-control"
                 value="<?= h($f['contratante_nome']) ?>" required>
        </div>

        <div class="adm-field">
          <label for="contratante_fone">WhatsApp do contratante</label>
          <input type="tel" id="contratante_fone" name="contratante_fone" class="form-control"
                 placeholder="(11) 96910-4308" value="<?= h($f['contratante_fone']) ?>">
          <small>É para cá que o motorista manda a localização.</small>
        </div>

        <div class="adm-field">
          <label for="origem">Carregamento em</label>
          <input type="text" id="origem" name="origem" class="form-control"
                 placeholder="Jundiaí SP" value="<?= h($f['origem']) ?>" required>
        </div>

        <div class="adm-field">
          <label for="destino">Descarga em</label>
          <input type="text" id="destino" name="destino" class="form-control"
                 placeholder="Uberlândia MG" value="<?= h($f['destino']) ?>" required>
        </div>

        <div class="adm-field">
          <label for="distancia_km">Distância (km)</label>
          <input type="number" id="distancia_km" name="distancia_km" class="form-control"
                 min="0" step="1" value="<?= h($f['distancia_km']) ?>">
        </div>

        <div class="adm-field">
          <label for="duracao_min">Tempo de viagem (minutos)</label>
          <input type="number" id="duracao_min" name="duracao_min" class="form-control"
                 min="0" step="5" value="<?= h($f['duracao_min']) ?>">
          <small>570 minutos = 9h30.</small>
        </div>

        <div class="adm-field">
          <label for="veiculo">Veículo</label>
          <input type="text" id="veiculo" name="veiculo" class="form-control"
                 placeholder="Carreta baú" value="<?= h($f['veiculo']) ?>">
        </div>

        <div class="adm-field">
          <label for="peso_t">Peso (t)</label>
          <input type="number" id="peso_t" name="peso_t" class="form-control"
                 min="0" step="0.5" value="<?= h($f['peso_t']) ?>">
        </div>

        <div class="adm-field col-2">
          <label for="carga">Carga</label>
          <input type="text" id="carga" name="carga" class="form-control"
                 placeholder="Carga paletizada — 24 pallets" value="<?= h($f['carga']) ?>">
        </div>

        <div class="adm-field">
          <label for="previsao_carregamento">Previsão de carregamento</label>
          <input type="datetime-local" id="previsao_carregamento" name="previsao_carregamento"
                 class="form-control" value="<?= h($f['previsao_carregamento']) ?>">
        </div>
      </div>

      <label class="adm-check">
        <input type="checkbox" name="calcular" value="1" <?= $viagem ? '' : 'checked' ?>>
        <span>Calcular distância e tempo pela rota (só preenche os campos que ficarem em branco)</span>
      </label>

      <details class="adm-avancado">
        <summary>Coordenadas (opcional)</summary>
        <p class="adm-sub">Preencha só se o cálculo automático errar a cidade. São usadas para
          medir quanto falta até o destino.</p>
        <div class="adm-grid">
          <div class="adm-field">
            <label for="origem_lat">Origem — latitude</label>
            <input type="text" id="origem_lat" name="origem_lat" class="form-control mono"
                   placeholder="-23.1857" value="<?= h($f['origem_lat']) ?>">
          </div>
          <div class="adm-field">
            <label for="origem_lon">Origem — longitude</label>
            <input type="text" id="origem_lon" name="origem_lon" class="form-control mono"
                   placeholder="-46.8978" value="<?= h($f['origem_lon']) ?>">
          </div>
          <div class="adm-field">
            <label for="destino_lat">Destino — latitude</label>
            <input type="text" id="destino_lat" name="destino_lat" class="form-control mono"
                   placeholder="-18.9186" value="<?= h($f['destino_lat']) ?>">
          </div>
          <div class="adm-field">
            <label for="destino_lon">Destino — longitude</label>
            <input type="text" id="destino_lon" name="destino_lon" class="form-control mono"
                   placeholder="-48.2772" value="<?= h($f['destino_lon']) ?>">
          </div>
        </div>
      </details>

      <div class="adm-acoes">
        <button type="submit" class="btn btn-brand"><?= $viagem ? 'Salvar alterações' : 'Cadastrar viagem' ?></button>
        <a href="index.php" class="btn btn-ghost-adm">Voltar</a>
      </div>
    </form>
  </section>

  <!-- ================= ACOMPANHAMENTO ================= -->
  <?php if ($viagem): ?>
  <section class="adm-card">
    <h2 class="adm-h2">Acompanhamento</h2>

    <div class="adm-link-box">
      <span>Link do contratante</span>
      <input type="text" class="form-control mono" readonly
             value="<?= h(url_rastreio($viagem['codigo'])) ?>"
             onclick="this.select()">
      <small>Mande este link junto com a confirmação do frete.</small>
    </div>

    <?php $prev = viagem_previsao_chegada($viagem); ?>
    <div class="adm-mini-fatos">
      <div><span>Andamento</span><b><?= viagem_progresso($viagem) ?>%</b></div>
      <div><span>Carregamento</span><b><?= h(fmt_datahora($viagem['previsao_carregamento'])) ?></b></div>
      <div><span>Iniciada</span><b><?= h(fmt_datahora($viagem['iniciada_em'])) ?></b></div>
      <div>
        <span>Previsão de chegada</span>
        <b><?= h($prev ? fmt_datahora($prev['em']) : '—') ?></b>
      </div>
      <div><span>Encerrada</span><b><?= h(fmt_datahora($viagem['concluida_em'])) ?></b></div>
      <div><span>Registros</span><b><?= count($eventos) ?></b></div>
    </div>
    <?php if ($prev): ?>
      <p class="adm-sub"><?= h(ucfirst(viagem_previsao_nota($prev['base']))) ?>
        (<?= h(fmt_duracao($viagem['duracao_min'])) ?> de viagem).</p>
    <?php elseif (empty($viagem['duracao_min'])): ?>
      <p class="adm-sub">Sem tempo de viagem cadastrado, não há previsão de chegada para mostrar ao contratante.</p>
    <?php else: ?>
      <p class="adm-sub">Preencha a <b>previsão de carregamento</b> para o contratante já ver uma previsão de chegada antes de o caminhão sair.</p>
    <?php endif; ?>

    <h3 class="adm-h3">Linha do tempo</h3>
    <?php if (!$eventos): ?>
      <p class="adm-vazio">Nenhum registro ainda.</p>
    <?php else: ?>
      <ol class="adm-timeline">
        <?php foreach ($eventos as $ev): ?>
          <li>
            <span class="ico" aria-hidden="true"><?= h(evento_icone($ev['tipo'])) ?></span>
            <div>
              <b><?= h($ev['titulo']) ?></b>
              <small>
                <?= h(fmt_datahora($ev['criado_em'], true)) ?>
                <?php if ($ev['restante_km'] !== null): ?> · faltam <?= h(fmt_km($ev['restante_km'])) ?><?php endif; ?>
                <?php if ($ev['precisao_m'] !== null): ?> · ± <?= (int)$ev['precisao_m'] ?> m<?php endif; ?>
                <?php if ($ev['origem_registro'] === 'gestor'): ?> · pela programação<?php endif; ?>
              </small>
              <?php if ($mapa = evento_mapa($ev)): ?>
                <a href="<?= h($mapa) ?>" target="_blank" rel="noopener">ver no mapa</a>
              <?php endif; ?>
            </div>
          </li>
        <?php endforeach; ?>
      </ol>
    <?php endif; ?>

    <form method="post" action="viagem.php?id=<?= (int)$viagem['id'] ?>" class="adm-nota">
      <?= csrf_campo() ?>
      <input type="hidden" name="acao" value="nota">
      <label for="nota">Publicar um recado na linha do tempo</label>
      <div class="adm-nota-linha">
        <input type="text" id="nota" name="nota" class="form-control" maxlength="160"
               placeholder="ex.: descarga reagendada para amanhã às 8h">
        <button type="submit" class="btn btn-ghost-adm">Publicar</button>
      </div>
    </form>

    <div class="adm-perigo">
      <h3 class="adm-h3">Ações da programação</h3>
      <div class="adm-perigo-botoes">
        <?php if ($viagem['status'] !== 'cancelada' && $viagem['status'] !== 'concluida'): ?>
          <form method="post" action="viagem.php?id=<?= (int)$viagem['id'] ?>"
                onsubmit="return confirm('Cancelar esta viagem?');">
            <?= csrf_campo() ?>
            <input type="hidden" name="acao" value="cancelar">
            <button type="submit" class="btn btn-ghost-adm">Cancelar viagem</button>
          </form>
        <?php endif; ?>

        <?php if ($viagem['status'] === 'concluida' || $viagem['status'] === 'cancelada'): ?>
          <form method="post" action="viagem.php?id=<?= (int)$viagem['id'] ?>"
                onsubmit="return confirm('Reabrir esta viagem como agendada? O motorista poderá iniciar de novo.');">
            <?= csrf_campo() ?>
            <input type="hidden" name="acao" value="reabrir">
            <button type="submit" class="btn btn-ghost-adm">Reabrir</button>
          </form>
        <?php endif; ?>

        <form method="post" action="viagem.php?id=<?= (int)$viagem['id'] ?>"
              onsubmit="return confirm('Excluir a viagem e toda a linha do tempo? Não dá para desfazer.');">
          <?= csrf_campo() ?>
          <input type="hidden" name="acao" value="excluir">
          <button type="submit" class="btn btn-excluir">Excluir viagem</button>
        </form>
      </div>
    </div>
  </section>
  <?php endif; ?>

</div>

<?php admin_rodape(); ?>
