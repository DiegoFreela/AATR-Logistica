<?php
/* ============================================================
   AATR — lista de viagens
   ============================================================ */

require_once __DIR__ . '/_layout.php';
exigir_gestor();

$status = get('status');
$busca  = get('q');

$where  = [];
$params = [];

if (isset(VIAGEM_STATUS[$status])) {
    $where[] = 'v.status = ?';
    $params[] = $status;
}
if ($busca !== '') {
    $where[] = '(v.codigo LIKE ? OR v.contratante_nome LIKE ? OR v.origem LIKE ? OR v.destino LIKE ?)';
    $curinga = '%' . $busca . '%';
    array_push($params, $curinga, $curinga, $curinga, $curinga);
}

$sql = 'SELECT ' . VIAGEM_CAMPOS . '
          FROM viagens v
          LEFT JOIN motoristas m ON m.id = v.motorista_id'
     . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
     . " ORDER BY FIELD(v.status,'em_viagem','agendada','concluida','cancelada'),
                COALESCE(v.atualizado_em, v.criado_em) DESC
        LIMIT 200";

$viagens = db_todas($sql, $params);

$contagem = [];
foreach (db_todas('SELECT status, COUNT(*) AS n FROM viagens GROUP BY status') as $linha) {
    $contagem[$linha['status']] = (int)$linha['n'];
}

admin_topo('Viagens', 'index.php');
admin_flash();
?>

<div class="adm-head">
  <div>
    <h1 class="adm-h1">Viagens</h1>
    <p class="adm-sub">
      <?= (int)($contagem['em_viagem'] ?? 0) ?> em viagem ·
      <?= (int)($contagem['agendada'] ?? 0) ?> aguardando ·
      <?= (int)($contagem['concluida'] ?? 0) ?> concluídas
    </p>
  </div>
  <a href="viagem.php" class="btn btn-brand">Nova viagem</a>
</div>

<form method="get" action="index.php" class="adm-filtros">
  <input type="text" name="q" class="form-control" placeholder="Código, contratante, cidade..."
         value="<?= h($busca) ?>">
  <select name="status" class="form-select">
    <option value="">Todos os status</option>
    <?php foreach (VIAGEM_STATUS as $chave => $rotulo): ?>
      <option value="<?= h($chave) ?>" <?= $status === $chave ? 'selected' : '' ?>><?= h($rotulo) ?></option>
    <?php endforeach; ?>
  </select>
  <button type="submit" class="btn btn-ghost-adm">Filtrar</button>
  <?php if ($busca !== '' || $status !== ''): ?>
    <a href="index.php" class="adm-limpar">limpar</a>
  <?php endif; ?>
</form>

<?php if (!$viagens): ?>
  <p class="adm-vazio">
    <?= $busca !== '' || $status !== ''
        ? 'Nenhuma viagem encontrada com esse filtro.'
        : 'Nenhuma viagem cadastrada ainda. Comece pela primeira.' ?>
  </p>
<?php else: ?>

<div class="adm-tabela-wrap">
  <table class="adm-tabela">
    <thead>
      <tr>
        <th>Código</th>
        <th>Rota</th>
        <th>Contratante</th>
        <th>Motorista</th>
        <th>Andamento</th>
        <th>Status</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($viagens as $v):
        $pct = viagem_progresso($v); ?>
        <tr>
          <td>
            <a class="adm-codigo" href="viagem.php?id=<?= (int)$v['id'] ?>"><?= h($v['codigo']) ?></a>
            <small><?= h(fmt_desde($v['atualizado_em'] ?: $v['criado_em'])) ?></small>
          </td>
          <td>
            <b><?= h($v['origem']) ?> → <?= h($v['destino']) ?></b>
            <small><?= h(fmt_km($v['distancia_km'])) ?> · <?= h(fmt_duracao($v['duracao_min'])) ?></small>
          </td>
          <td>
            <?= h($v['contratante_nome']) ?>
            <small><?= h($v['contratante_fone'] ? fone_exibir($v['contratante_fone']) : 'sem telefone') ?></small>
          </td>
          <td><?= h($v['motorista_nome'] ?: '— não atribuída —') ?></td>
          <td class="col-barra">
            <div class="mini-bar"><span style="width: <?= $pct ?>%"></span></div>
            <small><?= $pct ?>%</small>
          </td>
          <td><?= status_pill($v['status']) ?></td>
          <td class="col-acoes">
            <a href="viagem.php?id=<?= (int)$v['id'] ?>">abrir</a>
            <a href="../rastreio.php?codigo=<?= rawurlencode($v['codigo']) ?>" target="_blank" rel="noopener">rastreio</a>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php endif; ?>

<?php admin_rodape(); ?>
