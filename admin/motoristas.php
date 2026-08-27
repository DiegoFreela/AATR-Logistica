<?php
/* ============================================================
   AATR — cadastro de motoristas
   ------------------------------------------------------------
   O gestor cria o acesso e entrega o código e a senha para o
   motorista. A senha é gravada criptografada; nem o gestor
   consegue ler depois — se o motorista esquecer, define uma nova.
   ============================================================ */

require_once __DIR__ . '/_layout.php';
exigir_gestor();

$id       = (int)get('id');
$editando = $id ? db_linha('SELECT * FROM motoristas WHERE id = ?', [$id]) : null;

if ($id && !$editando) {
    redirecionar('motoristas.php?erro=' . rawurlencode('Motorista não encontrado.'));
}

$erros = [];
$f = [
    'nome'     => $editando['nome']     ?? '',
    'usuario'  => $editando['usuario']  ?? '',
    'veiculo'  => $editando['veiculo']  ?? '',
    'telefone' => isset($editando['telefone']) && $editando['telefone'] !== ''
                  ? fone_exibir($editando['telefone']) : '',
    'ativo'    => (string)($editando['ativo'] ?? '1'),
];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    exigir_csrf();
    $acao = post('acao', 'salvar');

    if ($acao === 'desativar' && $editando) {
        db_exec('UPDATE motoristas SET ativo = 0 WHERE id = ?', [$id]);
        redirecionar('motoristas.php?ok=' . rawurlencode('Acesso de ' . $editando['nome'] . ' desativado.'));
    }
    if ($acao === 'ativar' && $editando) {
        db_exec('UPDATE motoristas SET ativo = 1 WHERE id = ?', [$id]);
        redirecionar('motoristas.php?ok=' . rawurlencode('Acesso de ' . $editando['nome'] . ' reativado.'));
    }

    $f['nome']     = post('nome');
    $f['usuario']  = minusc(preg_replace('/[^a-zA-Z0-9._-]/', '', post('usuario')) ?? '');
    $f['veiculo']  = post('veiculo');
    $f['telefone'] = post('telefone');
    $f['ativo']    = post('ativo', '1');
    $senha         = post('senha');

    if ($f['nome'] === '') {
        $erros[] = 'Informe o nome do motorista.';
    }
    if (tamanho($f['usuario']) < 3) {
        $erros[] = 'O código de acesso precisa de ao menos 3 caracteres (letras, números, ponto ou hífen).';
    }
    if (db_valor('SELECT id FROM motoristas WHERE usuario = ? AND id <> ?', [$f['usuario'], $id])) {
        $erros[] = 'Já existe um motorista com o código ' . $f['usuario'] . '.';
    }
    if (!$editando && tamanho($senha) < 6) {
        $erros[] = 'A senha precisa de pelo menos 6 caracteres.';
    }
    if ($editando && $senha !== '' && tamanho($senha) < 6) {
        $erros[] = 'A nova senha precisa de pelo menos 6 caracteres.';
    }

    $fone = fone_normalizar($f['telefone']);
    if ($f['telefone'] !== '' && $fone === '') {
        $erros[] = 'O telefone do motorista não parece válido.';
    }

    if (!$erros) {
        if ($editando) {
            db_exec(
                'UPDATE motoristas SET nome=?, usuario=?, veiculo=?, telefone=?, ativo=? WHERE id=?',
                [$f['nome'], $f['usuario'], $f['veiculo'], $fone, (int)$f['ativo'], $id]
            );
            if ($senha !== '') {
                db_exec('UPDATE motoristas SET senha_hash = ? WHERE id = ?',
                        [password_hash($senha, PASSWORD_DEFAULT), $id]);
            }
            $recado = 'Motorista ' . $f['nome'] . ' atualizado.'
                    . ($senha !== '' ? ' Senha trocada.' : '');
        } else {
            db_inserir(
                'INSERT INTO motoristas (usuario, senha_hash, nome, veiculo, telefone, ativo, criado_em)
                 VALUES (?,?,?,?,?,?,?)',
                [$f['usuario'], password_hash($senha, PASSWORD_DEFAULT), $f['nome'],
                 $f['veiculo'], $fone, (int)$f['ativo'], agora()]
            );
            $recado = 'Motorista ' . $f['nome'] . ' cadastrado. Entregue o código '
                    . $f['usuario'] . ' e a senha para ele.';
        }
        redirecionar('motoristas.php?ok=' . rawurlencode($recado));
    }
}

$lista = db_todas(
    'SELECT m.*,
            (SELECT COUNT(*) FROM viagens v WHERE v.motorista_id = m.id AND v.status = "em_viagem") AS rodando,
            (SELECT COUNT(*) FROM viagens v WHERE v.motorista_id = m.id) AS total
       FROM motoristas m
   ORDER BY m.ativo DESC, m.nome'
);

admin_topo('Motoristas', 'motoristas.php');
admin_flash();

if ($erros) {
    echo '<div class="adm-flash erro"><b>Confira antes de salvar:</b><ul>';
    foreach ($erros as $e) {
        echo '<li>' . h($e) . '</li>';
    }
    echo '</ul></div>';
}
?>

<div class="adm-head">
  <div>
    <h1 class="adm-h1">Motoristas</h1>
    <p class="adm-sub">Quem tem acesso à área do motorista e pode operar viagens no celular.</p>
  </div>
</div>

<div class="adm-colunas">

  <section class="adm-card">
    <h2 class="adm-h2"><?= $editando ? 'Editar ' . h($editando['nome']) : 'Novo motorista' ?></h2>

    <form method="post" action="motoristas.php<?= $editando ? '?id=' . $id : '' ?>" novalidate>
      <?= csrf_campo() ?>
      <input type="hidden" name="acao" value="salvar">

      <div class="adm-grid">
        <div class="adm-field col-2">
          <label for="nome">Nome</label>
          <input type="text" id="nome" name="nome" class="form-control" value="<?= h($f['nome']) ?>" required>
        </div>

        <div class="adm-field">
          <label for="usuario">Código de acesso</label>
          <input type="text" id="usuario" name="usuario" class="form-control mono"
                 placeholder="joao.silva" autocapitalize="none" spellcheck="false"
                 value="<?= h($f['usuario']) ?>" required>
          <small>É o que ele digita para entrar.</small>
        </div>

        <div class="adm-field">
          <label for="senha"><?= $editando ? 'Nova senha' : 'Senha' ?></label>
          <input type="text" id="senha" name="senha" class="form-control mono"
                 autocomplete="new-password"
                 placeholder="<?= $editando ? 'deixe em branco para manter' : 'mínimo 6 caracteres' ?>"
                 <?= $editando ? '' : 'required' ?>>
          <small>Anote agora: depois de salvar ninguém consegue ler de volta.</small>
        </div>

        <div class="adm-field">
          <label for="veiculo">Veículo</label>
          <input type="text" id="veiculo" name="veiculo" class="form-control"
                 placeholder="Carreta baú · ABC-1D23" value="<?= h($f['veiculo']) ?>">
        </div>

        <div class="adm-field">
          <label for="telefone">Telefone do motorista</label>
          <input type="tel" id="telefone" name="telefone" class="form-control"
                 placeholder="(11) 90000-0000" value="<?= h($f['telefone']) ?>">
        </div>

        <div class="adm-field">
          <label for="ativo">Situação</label>
          <select id="ativo" name="ativo" class="form-select">
            <option value="1" <?= $f['ativo'] === '1' ? 'selected' : '' ?>>Ativo</option>
            <option value="0" <?= $f['ativo'] === '0' ? 'selected' : '' ?>>Sem acesso</option>
          </select>
        </div>
      </div>

      <div class="adm-acoes">
        <button type="submit" class="btn btn-brand"><?= $editando ? 'Salvar' : 'Cadastrar motorista' ?></button>
        <?php if ($editando): ?>
          <a href="motoristas.php" class="btn btn-ghost-adm">Cancelar edição</a>
        <?php endif; ?>
      </div>
    </form>
  </section>

  <section class="adm-card">
    <h2 class="adm-h2">Cadastrados</h2>

    <?php if (!$lista): ?>
      <p class="adm-vazio">Nenhum motorista ainda.</p>
    <?php else: ?>
      <div class="adm-tabela-wrap">
        <table class="adm-tabela">
          <thead>
            <tr><th>Motorista</th><th>Código</th><th>Viagens</th><th>Situação</th><th></th></tr>
          </thead>
          <tbody>
            <?php foreach ($lista as $m): ?>
              <tr class="<?= (int)$m['ativo'] === 1 ? '' : 'inativo' ?>">
                <td>
                  <b><?= h($m['nome']) ?></b>
                  <small><?= h($m['veiculo'] ?: '—') ?></small>
                </td>
                <td class="mono"><?= h($m['usuario']) ?></td>
                <td>
                  <?= (int)$m['total'] ?> no total
                  <?php if ((int)$m['rodando'] > 0): ?>
                    <small class="destaque"><?= (int)$m['rodando'] ?> em viagem agora</small>
                  <?php endif; ?>
                </td>
                <td>
                  <span class="pill <?= (int)$m['ativo'] === 1 ? 'agendada' : 'cancelada' ?>">
                    <?= (int)$m['ativo'] === 1 ? 'Ativo' : 'Sem acesso' ?>
                  </span>
                </td>
                <td class="col-acoes">
                  <a href="motoristas.php?id=<?= (int)$m['id'] ?>">editar</a>
                  <form method="post" action="motoristas.php?id=<?= (int)$m['id'] ?>" class="inline">
                    <?= csrf_campo() ?>
                    <input type="hidden" name="acao" value="<?= (int)$m['ativo'] === 1 ? 'desativar' : 'ativar' ?>">
                    <button type="submit" class="link-btn">
                      <?= (int)$m['ativo'] === 1 ? 'desativar' : 'reativar' ?>
                    </button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>

</div>

<?php admin_rodape(); ?>
