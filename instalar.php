<?php
/* ============================================================
   AATR — instalação: cria o primeiro acesso do gestor
   ------------------------------------------------------------
   Existe para que NENHUMA senha precise ficar escrita no
   sql/aatr.sql nem em qualquer arquivo do repositório. Quem
   define a senha é você, aqui, na hora de instalar.

   Só funciona enquanto não houver nenhum gestor cadastrado.
   Depois disso ele se recusa a rodar — mas apague o arquivo
   do servidor assim que terminar.
   ============================================================ */

require_once __DIR__ . '/config.php';

$erros    = [];
$pronto   = false;
$semBanco = false;
$jaTem    = false;

/* ---------- o banco já foi importado? ---------- */
try {
    $jaTem = (int)db_valor('SELECT COUNT(*) FROM gestores') > 0;
} catch (Throwable $e) {
    $semBanco = true;
}

$nome  = post('nome');
$email = minusc(post('email'));

if (!$semBanco && !$jaTem && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    exigir_csrf();

    $senha = post('senha');
    $conf  = post('confirma');

    if ($nome === '') {
        $erros[] = 'Informe o seu nome.';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $erros[] = 'Informe um e-mail válido — é com ele que você vai entrar.';
    }
    if (tamanho($senha) < 8) {
        $erros[] = 'A senha precisa de pelo menos 8 caracteres.';
    }
    if ($senha !== $conf) {
        $erros[] = 'A confirmação não bate com a senha.';
    }

    if (!$erros) {
        db_inserir(
            'INSERT INTO gestores (nome, email, senha_hash, ativo, criado_em) VALUES (?,?,?,1,?)',
            [encurtar($nome, 120), $email, password_hash($senha, PASSWORD_DEFAULT), agora()]
        );
        $pronto = true;
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Instalação — AATR Transporte &amp; Logística</title>
<meta name="robots" content="noindex, nofollow">
<link rel="icon" href="logo-aatr.png">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Saira+Condensed:wght@600;700;800&family=Barlow:wght@400;500;600&family=JetBrains+Mono:wght@500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="style.css">
</head>
<body class="adm">

<header class="adm-top">
  <div class="adm-wrap">
    <span class="adm-logo">
      <img src="logo-aatr-branco.png" alt="AATR">
      <span>Instalação</span>
    </span>
  </div>
</header>

<main class="adm-main">
  <div class="adm-wrap">

<?php if ($semBanco): ?>

    <section class="adm-card adm-card-estreito">
      <h1 class="adm-h1">Falta importar o banco</h1>
      <p class="adm-sub">Não encontrei as tabelas do sistema. Antes de continuar:</p>
      <ol class="adm-passos">
        <li>Crie o banco no cPanel e anote usuário e senha.</li>
        <li>Coloque esses dados num <b>config.local.php</b>, ao lado do config.php.</li>
        <li>No phpMyAdmin, selecione o banco e importe <b>sql/aatr.sql</b>.</li>
      </ol>
      <p class="adm-sub">Depois recarregue esta página.</p>
      <div class="adm-acoes"><a href="instalar.php" class="btn btn-brand">Tentar de novo</a></div>
    </section>

<?php elseif ($jaTem): ?>

    <section class="adm-card adm-card-estreito">
      <h1 class="adm-h1">Já instalado</h1>
      <p class="adm-sub">Já existe um gestor cadastrado, então esta tela não faz mais nada —
        ninguém consegue usá-la para criar um acesso.</p>
      <div class="adm-alerta-senha">
        <b>Apague este arquivo do servidor.</b>
        Remova o <strong>instalar.php</strong> por FTP ou pelo gerenciador de arquivos
        da hospedagem. Ele não é mais necessário.
      </div>
      <div class="adm-acoes"><a href="admin/login.php" class="btn btn-brand">Ir para o painel</a></div>
    </section>

<?php elseif ($pronto): ?>

    <section class="adm-card adm-card-estreito">
      <h1 class="adm-h1">Pronto</h1>
      <p class="adm-sub">Acesso criado para <b><?= h($email) ?></b>. Entre no painel com a senha
        que você acabou de escolher.</p>
      <div class="adm-alerta-senha">
        <b>Agora apague o instalar.php do servidor.</b>
        Ele já se recusa a rodar de novo, mas o certo é não deixá-lo no ar.
      </div>
      <ol class="adm-passos">
        <li>Entre no painel e cadastre os <b>motoristas</b> — você escolhe o código e a senha de cada um.</li>
        <li>Cadastre a primeira <b>viagem</b>, com o WhatsApp do contratante.</li>
        <li>Mande o link de rastreio para quem contratou o frete.</li>
      </ol>
      <div class="adm-acoes"><a href="admin/login.php" class="btn btn-brand">Entrar no painel</a></div>
    </section>

<?php else: ?>

    <section class="adm-card adm-card-estreito">
      <h1 class="adm-h1">Criar o acesso do gestor</h1>
      <p class="adm-sub">Este é o primeiro e único acesso criado por aqui. A senha é escolhida
        por você agora — o sistema não vem com senha nenhuma de fábrica.</p>

      <?php if ($erros): ?>
        <div class="adm-flash erro"><ul>
          <?php foreach ($erros as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
        </ul></div>
      <?php endif; ?>

      <form method="post" action="instalar.php" novalidate>
        <?= csrf_campo() ?>
        <div class="adm-field">
          <label for="nome">Seu nome</label>
          <input type="text" id="nome" name="nome" class="form-control"
                 value="<?= h($nome) ?>" required>
        </div>
        <div class="adm-field">
          <label for="email">E-mail de acesso</label>
          <input type="email" id="email" name="email" class="form-control"
                 autocomplete="username" value="<?= h($email) ?>" required>
        </div>
        <div class="adm-field">
          <label for="senha">Senha</label>
          <input type="password" id="senha" name="senha" class="form-control"
                 autocomplete="new-password" required>
          <small>Mínimo 8 caracteres. Anote num lugar seguro.</small>
        </div>
        <div class="adm-field">
          <label for="confirma">Repita a senha</label>
          <input type="password" id="confirma" name="confirma" class="form-control"
                 autocomplete="new-password" required>
        </div>
        <div class="adm-acoes">
          <button type="submit" class="btn btn-brand">Criar acesso</button>
        </div>
      </form>
    </section>

<?php endif; ?>

  </div>
</main>

<footer class="adm-foot">
  <div class="adm-wrap">AATR Transporte &amp; Logística · instalação</div>
</footer>

</body>
</html>
