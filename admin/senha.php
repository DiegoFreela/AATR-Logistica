<?php
/* ============================================================
   AATR — trocar a senha do gestor
   ============================================================ */

require_once __DIR__ . '/_layout.php';
$g = exigir_gestor();

$erros = [];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    exigir_csrf();

    $atual = post('atual');
    $nova  = post('nova');
    $conf  = post('confirma');

    $registro = db_linha('SELECT * FROM gestores WHERE id = ?', [$g['id']]);

    if (!$registro || !password_verify($atual, $registro['senha_hash'])) {
        $erros[] = 'A senha atual não confere.';
    }
    if (tamanho($nova) < 8) {
        $erros[] = 'A nova senha precisa de pelo menos 8 caracteres.';
    }
    if ($nova !== $conf) {
        $erros[] = 'A confirmação não bate com a nova senha.';
    }
    if ($nova === $atual) {
        $erros[] = 'A nova senha precisa ser diferente da atual.';
    }

    if (!$erros) {
        db_exec('UPDATE gestores SET senha_hash = ? WHERE id = ?',
                [password_hash($nova, PASSWORD_DEFAULT), $g['id']]);
        redirecionar('index.php?ok=' . rawurlencode('Senha trocada.'));
    }
}

admin_topo('Senha');
admin_flash();

if ($erros) {
    echo '<div class="adm-flash erro"><ul>';
    foreach ($erros as $e) {
        echo '<li>' . h($e) . '</li>';
    }
    echo '</ul></div>';
}
?>

<div class="adm-head">
  <div>
    <h1 class="adm-h1">Trocar senha</h1>
    <p class="adm-sub">Conectado como <?= h($g['email']) ?>.</p>
  </div>
</div>

<section class="adm-card adm-card-estreito">
  <form method="post" action="senha.php" novalidate>
    <?= csrf_campo() ?>
    <div class="adm-field">
      <label for="atual">Senha atual</label>
      <input type="password" id="atual" name="atual" class="form-control"
             autocomplete="current-password" required>
    </div>
    <div class="adm-field">
      <label for="nova">Nova senha</label>
      <input type="password" id="nova" name="nova" class="form-control"
             autocomplete="new-password" required>
      <small>Mínimo 8 caracteres.</small>
    </div>
    <div class="adm-field">
      <label for="confirma">Repita a nova senha</label>
      <input type="password" id="confirma" name="confirma" class="form-control"
             autocomplete="new-password" required>
    </div>
    <div class="adm-acoes">
      <button type="submit" class="btn btn-brand">Trocar senha</button>
      <a href="index.php" class="btn btn-ghost-adm">Voltar</a>
    </div>
  </form>
</section>

<?php admin_rodape(); ?>
