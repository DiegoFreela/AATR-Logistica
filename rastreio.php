<?php
/* ============================================================
   AATR — acompanhamento da viagem (página do contratante)
   ------------------------------------------------------------
   Acesso por código: rastreio.php?codigo=AATR-4417-BR
   Sem login: o código é a chave. Mostra rota, KM, tempo,
   barra de andamento e a linha do tempo montada pelos toques
   do motorista.
   ============================================================ */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/inc/viagem.php';

$codigo = so_codigo(get('codigo'));
$viagem = null;
$erro   = '';

if ($codigo !== '') {
    $viagem = viagem_por_codigo($codigo);
    if (!$viagem) {
        $erro = 'Não encontramos nenhuma viagem com o número ' . $codigo
              . '. Confira com quem contratou o frete.';
    }
}

$dados   = $viagem ? viagem_para_api($viagem) : null;
$titulo  = $viagem
    ? 'Viagem ' . $viagem['codigo'] . ' — ' . $viagem['origem'] . ' → ' . $viagem['destino']
    : 'Acompanhar viagem — AATR Transporte & Logística';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($titulo) ?></title>
<meta name="description" content="Acompanhe em tempo real a viagem da sua carga com a AATR Transporte e Logística.">
<meta name="theme-color" content="#0A2540">
<meta name="robots" content="noindex, nofollow">
<link rel="icon" href="logo-aatr.png">

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Saira+Condensed:wght@600;700;800&family=Barlow:ital,wght@0,400;0,500;0,600;1,500&family=Barlow+Semi+Condensed:wght@500;600&family=JetBrains+Mono:wght@500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="style.css">
</head>
<body class="tp-page">

<header class="tp-top">
  <div class="container">
    <a href="index.html" class="tp-logo">
      <img src="logo-aatr-branco.png" alt="AATR Transporte e Logística">
    </a>
    <a href="https://wa.me/<?= h(WHATSAPP_EMPRESA) ?>" target="_blank" rel="noopener" class="tp-wa">
      Falar com a operação
    </a>
  </div>
</header>

<main class="tp-main">
  <div class="container">

<?php if (!$viagem): ?>

    <!-- ================= BUSCA ================= -->
    <section class="tp-search">
      <p class="eyebrow light"><i class="lines" aria-hidden="true"></i> Acompanhamento</p>
      <h1 class="tp-h1">Onde está a minha carga?</h1>
      <p class="tp-sub">Informe o número da viagem ou do CT-e que a AATR passou para você.</p>

      <form method="get" action="rastreio.php" class="tp-form">
        <label for="codigo" class="visually-hidden">Número da viagem</label>
        <input type="text" id="codigo" name="codigo" class="form-control mono"
               placeholder="AATR-4417-BR" value="<?= h($codigo) ?>"
               autocomplete="off" spellcheck="false" autocapitalize="characters" required>
        <button type="submit" class="btn btn-brand">Consultar</button>
      </form>

      <?php if ($erro !== ''): ?>
        <p class="tp-erro" role="alert"><?= h($erro) ?></p>
      <?php endif; ?>

      <p class="tp-ajuda">Não tem o número em mãos?
        <a href="https://wa.me/<?= h(WHATSAPP_EMPRESA) ?>" target="_blank" rel="noopener">Chame a operação no WhatsApp</a>.
      </p>
    </section>

<?php else: ?>

    <!-- ================= CABEÇALHO DA VIAGEM ================= -->
    <section class="tp-head">
      <div class="tp-head-left">
        <p class="eyebrow light"><i class="lines" aria-hidden="true"></i> Viagem <?= h($dados['codigo']) ?></p>
        <h1 class="tp-h1"><?= h($dados['origem']) ?> <em>→</em> <?= h($dados['destino']) ?></h1>
      </div>
      <span class="tp-status <?= h($dados['status']) ?>"><?= h($dados['status_label']) ?></span>
    </section>

    <!-- ================= NÚMEROS DA ROTA ================= -->
    <section class="tp-facts">
      <div><span>Distância</span><b><?= h(fmt_km($dados['distancia_km'])) ?></b></div>
      <div><span>Tempo de viagem</span><b><?= h($dados['duracao_label']) ?></b></div>
      <div><span>Veículo</span><b><?= h($dados['veiculo'] ?: '—') ?></b></div>

      <div>
        <span><?= $dados['iniciada_em'] ? 'Saída' : 'Carregamento' ?></span>
        <b><?= h($dados['iniciada_em'] ?: ($dados['carregamento'] ?: 'a programar')) ?></b>
      </div>

      <div>
        <?php if ($dados['status'] === 'concluida'): ?>
          <span>Entregue em</span>
          <b><?= h($dados['concluida_em'] ?? '—') ?></b>
        <?php else: ?>
          <span>Previsão de chegada</span>
          <b><?= h($dados['previsao'] ?? 'a definir') ?></b>
          <small><?= h($dados['previsao_nota'] ?? 'depende do carregamento ser programado') ?></small>
        <?php endif; ?>
      </div>
    </section>

    <!-- ================= BARRA DA VIAGEM ================= -->
    <section class="tp-progress">
      <div class="tp-progress-ends">
        <span><?= h($dados['origem']) ?></span>
        <span><?= h($dados['destino']) ?></span>
      </div>

      <div class="tp-bar" role="img"
           aria-label="Andamento da viagem: <?= (int)$dados['progresso'] ?> por cento">
        <div class="tp-bar-fill" style="width: <?= (int)$dados['progresso'] ?>%"></div>
        <span class="tp-truck<?= $dados['status'] === 'concluida' ? '' : ' virado' ?>"
              style="left: <?= (int)$dados['progresso'] ?>%">
          <?= $dados['status'] === 'concluida' ? '📦' : '🚛' ?>
        </span>
      </div>

      <p class="tp-progress-note">
        <?php
        $ultima = viagem_ultima_posicao((int)$viagem['id']);
        if ($dados['status'] === 'concluida') {
            echo 'Carga entregue. Viagem encerrada pelo motorista.';
        } elseif ($dados['status'] === 'agendada') {
            echo 'Viagem programada. A linha do tempo começa quando o motorista iniciar o trajeto.';
        } elseif ($ultima && $ultima['restante_km'] !== null) {
            echo 'Faltam aproximadamente <b>' . h(fmt_km($ultima['restante_km']))
               . '</b> para o destino · última posição ' . h(fmt_desde($ultima['criado_em']));
        } else {
            echo 'Viagem iniciada. Aguardando a primeira posição do motorista.';
        }
        ?>
      </p>
    </section>

    <!-- ================= LINHA DO TEMPO ================= -->
    <section class="tp-timeline-wrap">
      <h2 class="tp-h2">Linha do tempo</h2>

      <?php if (!$dados['eventos']): ?>
        <p class="tp-vazio">Nenhum registro ainda.</p>
      <?php else: ?>
        <ol class="tp-timeline">
          <?php foreach ($dados['eventos'] as $i => $ev): ?>
            <li class="tp-step <?= h($ev['tipo']) ?><?= $i === count($dados['eventos']) - 1 ? ' ultimo' : '' ?>">
              <span class="tp-step-ico" aria-hidden="true"><?= h($ev['icone']) ?></span>
              <div class="tp-step-body">
                <b class="tp-step-title"><?= h($ev['titulo']) ?></b>
                <span class="tp-step-when"><?= h($ev['quando']) ?> · <?= h($ev['desde']) ?></span>
                <?php if ($ev['restante_km'] !== null && $ev['tipo'] !== 'chegada'): ?>
                  <span class="tp-step-km">faltam <?= h(fmt_km($ev['restante_km'])) ?></span>
                <?php endif; ?>
                <?php if ($ev['mapa']): ?>
                  <a class="tp-step-map" href="<?= h($ev['mapa']) ?>" target="_blank" rel="noopener">Ver no mapa</a>
                <?php endif; ?>
              </div>
            </li>
          <?php endforeach; ?>
        </ol>
      <?php endif; ?>

      <p class="tp-rodape">
        Atualizado <?= h($dados['atualizado']) ?> · esta página se atualiza sozinha.
        <br>
        <small>A distância restante é calculada a partir da posição enviada pelo motorista até o
        destino, em linha reta corrigida. Serve como referência de andamento, não como leitura
        de odômetro.</small>
      </p>
    </section>

<?php endif; ?>

  </div>
</main>

<footer class="tp-foot">
  <div class="container">
    <span>© <?= date('Y') ?> AATR Transporte &amp; Logística · Jundiaí — SP</span>
    <span>
      <a href="https://wa.me/<?= h(WHATSAPP_EMPRESA) ?>" target="_blank" rel="noopener"><?= h(fone_exibir(WHATSAPP_EMPRESA)) ?></a>
      · <a href="mailto:<?= h(EMAIL_EMPRESA) ?>"><?= h(EMAIL_EMPRESA) ?></a>
    </span>
  </div>
</footer>

<?php if ($viagem && $dados['status'] !== 'concluida'): ?>
<script>
/* Confere o servidor a cada 45s e só recarrega quando algo mudou de fato. */
(function () {
  'use strict';
  var codigo = <?= json_encode($dados['codigo']) ?>;
  var marca  = <?= json_encode($dados['status'] . '|' . count($dados['eventos']) . '|' . $dados['progresso']) ?>;

  setInterval(async function () {
    if (document.hidden) return;
    try {
      var r = await fetch('api/rastreio.php?codigo=' + encodeURIComponent(codigo), { cache: 'no-store' });
      var d = await r.json();
      if (!d.ok) return;
      var nova = d.status + '|' + d.eventos.length + '|' + d.progresso;
      if (nova !== marca) location.reload();
    } catch (e) { /* sem rede: tenta de novo no próximo ciclo */ }
  }, 45000);
})();
</script>
<?php endif; ?>

</body>
</html>
