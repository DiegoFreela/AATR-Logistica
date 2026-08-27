<?php
/* ============================================================
   AATR — entrada do gestor
   ============================================================ */

require_once __DIR__ . '/_layout.php';

if (gestor()) {
    redirecionar('index.php');
}

$erro = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    exigir_csrf();

    $email = minusc(post('email'));
    $senha = post('senha');

    if ($email === '' || $senha === '') {
        $erro = 'Preencha e-mail e senha.';
    } elseif (login_bloqueado($email)) {
        $erro = 'Muitas tentativas seguidas. Espere ' . login_espera_min($email) . ' minutos.';
    } else {
        $g = db_linha('SELECT * FROM gestores WHERE email = ? AND ativo = 1', [$email]);

        if ($g && password_verify($senha, $g['senha_hash'])) {
            if (password_needs_rehash($g['senha_hash'], PASSWORD_DEFAULT)) {
                db_exec('UPDATE gestores SET senha_hash = ? WHERE id = ?',
                        [password_hash($senha, PASSWORD_DEFAULT), $g['id']]);
            }
            login_limpar($email);
            gestor_logar($g);
            redirecionar('index.php');
        }

        login_registrar_falha($email);
        $restam = LOGIN_MAX_TENTATIVAS - login_tentativas($email);
        $erro = 'E-mail ou senha não conferem.'
              . ($restam > 0 && $restam <= 3 ? ' Restam ' . $restam . ' tentativas.' : '');
    }
}

admin_topo('Entrar');
?>

<section class="adm-login">
  <h1 class="adm-h1">Painel da programação</h1>
  <p class="adm-sub">Entre para cadastrar e acompanhar as viagens.</p>

  <form method="post" action="login.php" novalidate>
    <?= csrf_campo() ?>
    <div class="adm-field">
      <label for="email">E-mail</label>
      <input type="email" id="email" name="email" class="form-control"
             autocomplete="username" value="<?= h(post('email')) ?>" required>
    </div>
    <div class="adm-field">
      <label for="senha">Senha</label>
      <input type="password" id="senha" name="senha" class="form-control"
             autocomplete="current-password" required>
    </div>
    <?php if ($erro !== ''): ?>
      <p class="adm-flash erro" role="alert"><?= h($erro) ?></p>
    <?php endif; ?>
    <button type="submit" class="btn btn-brand w-100">Entrar</button>
  </form>

  <p class="adm-login-nota">
    Ainda não tem acesso? O primeiro é criado em <a href="../instalar.php">instalar.php</a>,
    logo depois de importar o banco. Se o seu já existe e você esqueceu a senha, quem cria
    uma nova é outro gestor.
  </p>
</section>

<?php admin_rodape(); ?>
