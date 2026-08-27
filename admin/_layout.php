<?php
/* ============================================================
   AATR — moldura das telas do gestor
   ============================================================ */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/viagem.php';

/** Cabeçalho + menu. Chame no começo de cada tela do admin. */
function admin_topo(string $titulo, string $ativo = ''): void
{
    $g = gestor();
    $itens = [
        'index.php'      => 'Viagens',
        'viagem.php'     => 'Nova viagem',
        'motoristas.php' => 'Motoristas',
    ];
    ?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($titulo) ?> — Painel AATR</title>
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#061A2E">
<link rel="icon" href="../logo-aatr.png">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Saira+Condensed:wght@600;700;800&family=Barlow:wght@400;500;600&family=JetBrains+Mono:wght@500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../style.css">
</head>
<body class="adm">

<header class="adm-top">
  <div class="adm-wrap">
    <a href="index.php" class="adm-logo">
      <img src="../logo-aatr-branco.png" alt="AATR">
      <span>Painel</span>
    </a>
    <?php if ($g): ?>
      <nav class="adm-nav">
        <?php foreach ($itens as $arquivo => $rotulo): ?>
          <a href="<?= h($arquivo) ?>" class="<?= $ativo === $arquivo ? 'on' : '' ?>"><?= h($rotulo) ?></a>
        <?php endforeach; ?>
      </nav>
      <div class="adm-eu">
        <span><?= h($g['nome']) ?></span>
        <a href="senha.php">Senha</a>
        <a href="logout.php">Sair</a>
      </div>
    <?php endif; ?>
  </div>
</header>

<main class="adm-main">
  <div class="adm-wrap">
    <?php
    if ($g) {
        admin_alerta_instalador();
    }
}

function admin_rodape(): void
{
    ?>
  </div>
</main>
<footer class="adm-foot">
  <div class="adm-wrap">
    AATR Transporte &amp; Logística · painel da programação ·
    <a href="../index.html" target="_blank" rel="noopener">ver o site</a>
  </div>
</footer>
</body>
</html>
    <?php
}

/**
 * O instalar.php cria o primeiro gestor e depois se recusa a
 * rodar sozinho. Mesmo assim ele não tem razão para continuar
 * no servidor — enquanto estiver lá, o painel avisa.
 */
function admin_alerta_instalador(): void
{
    if (!is_file(dirname(__DIR__) . '/instalar.php')) {
        return;
    }

    echo '<div class="adm-alerta-senha">'
       . '<b>Apague o instalar.php do servidor.</b> '
       . 'Ele já cumpriu a função de criar o seu acesso e não roda mais — '
       . 'mas o certo é removê-lo por FTP ou pelo gerenciador de arquivos '
       . 'da hospedagem.'
       . '</div>';
}

/** Caixa de recado no topo da tela (?ok=... ou ?erro=...). */
function admin_flash(): void
{
    $ok   = get('ok');
    $erro = get('erro');
    if ($ok !== '') {
        echo '<p class="adm-flash ok">' . h($ok) . '</p>';
    }
    if ($erro !== '') {
        echo '<p class="adm-flash erro">' . h($erro) . '</p>';
    }
}

/** Gera um código de viagem livre, no padrão AATR-1234-BR. */
function gerar_codigo_viagem(): string
{
    for ($i = 0; $i < 40; $i++) {
        $codigo = 'AATR-' . random_int(1000, 9999) . '-BR';
        if (!db_valor('SELECT id FROM viagens WHERE codigo = ?', [$codigo])) {
            return $codigo;
        }
    }
    return 'AATR-' . date('ymdHis');
}

/** Etiqueta colorida do status. */
function status_pill(string $status): string
{
    $rotulo = VIAGEM_STATUS[$status] ?? $status;
    return '<span class="pill ' . h($status) . '">' . h($rotulo) . '</span>';
}
