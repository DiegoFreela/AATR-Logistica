<?php
/* ============================================================
   AATR — Área do motorista
   ------------------------------------------------------------
   Substitui o antigo motorista.html, que trazia usuário e senha
   escritos no JavaScript (visíveis para qualquer um que abrisse
   o código-fonte). Agora a conferência é feita aqui no servidor,
   contra senha criptografada no banco.
   ============================================================ */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/inc/viagem.php';

/* ---------- sair ---------- */
if (get('sair') !== '') {
    motorista_sair();
    redirecionar('motorista.php');
}

/* ---------- entrar ---------- */
$erro = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    exigir_csrf();

    $usuario = minusc(post('usuario'));
    $senha   = post('senha');

    if ($usuario === '' || $senha === '') {
        $erro = 'Preencha o código e a senha.';
    } elseif (login_bloqueado($usuario)) {
        $erro = 'Muitas tentativas seguidas. Espere ' . login_espera_min($usuario)
              . ' minutos ou fale com a programação.';
    } else {
        $m = db_linha('SELECT * FROM motoristas WHERE usuario = ? AND ativo = 1', [$usuario]);

        if ($m && password_verify($senha, $m['senha_hash'])) {
            // atualiza o hash se o PHP da hospedagem passar a usar algoritmo melhor
            if (password_needs_rehash($m['senha_hash'], PASSWORD_DEFAULT)) {
                db_exec('UPDATE motoristas SET senha_hash = ? WHERE id = ?',
                        [password_hash($senha, PASSWORD_DEFAULT), $m['id']]);
            }
            login_limpar($usuario);
            motorista_logar($m);
            redirecionar('motorista.php');
        }

        login_registrar_falha($usuario);
        $restam = LOGIN_MAX_TENTATIVAS - login_tentativas($usuario);
        $erro = 'Código ou senha não conferem.'
              . ($restam > 0 && $restam <= 3 ? ' Restam ' . $restam . ' tentativas.' : '');
    }
}

$motorista = motorista();
$viagens   = $motorista ? viagens_do_motorista((int)$motorista['id']) : [];

/* Monta o pacote que o JavaScript da página consome. */
$pacote = ['viagens' => []];
foreach ($viagens as $v) {
    $pacote['viagens'][] = [
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
        'iniciada_em'  => $v['iniciada_em'] ? fmt_datahora($v['iniciada_em']) : null,
        'rastreio'     => url_rastreio($v['codigo']),
    ];
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Área do motorista — AATR Transporte &amp; Logística</title>
<meta name="description" content="Área restrita para motoristas da AATR: iniciar viagem, enviar localização e registrar a chegada.">
<meta name="theme-color" content="#061A2E">
<meta name="robots" content="noindex, nofollow">
<link rel="icon" href="logo-aatr.png">

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Saira+Condensed:wght@600;700;800&family=Barlow:ital,wght@0,400;0,500;0,600;1,500&family=Barlow+Semi+Condensed:wght@500;600&family=JetBrains+Mono:wght@500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="style.css">
</head>
<body class="driver-page">

<div class="driver-shell">

  <header class="driver-top">
    <a href="index.html" class="driver-logo">
      <img src="logo-aatr-branco.png" alt="AATR Transporte e Logística">
    </a>
    <?php if ($motorista): ?>
      <a href="motorista.php?sair=1" class="driver-exit">Sair</a>
    <?php endif; ?>
  </header>

<?php if (!$motorista): ?>

  <!-- ================= LOGIN ================= -->
  <section class="driver-card" aria-labelledby="loginTitle">
    <p class="eyebrow light"><i class="lines" aria-hidden="true"></i> Área restrita</p>
    <h1 id="loginTitle" class="driver-h1">Área do motorista</h1>
    <p class="driver-sub">Entre com o código que a programação te passou.</p>

    <form method="post" action="motorista.php" novalidate>
      <?= csrf_campo() ?>
      <div class="driver-field">
        <label for="usuario">Código do motorista</label>
        <input type="text" id="usuario" name="usuario" class="form-control"
               autocomplete="username" placeholder="ex.: joao.silva"
               autocapitalize="none" spellcheck="false"
               value="<?= h(post('usuario')) ?>" required>
      </div>
      <div class="driver-field">
        <label for="senha">Senha</label>
        <input type="password" id="senha" name="senha" class="form-control"
               autocomplete="current-password" required>
      </div>
      <?php if ($erro !== ''): ?>
        <p class="driver-msg erro" role="alert"><?= h($erro) ?></p>
      <?php endif; ?>
      <button type="submit" class="btn btn-brand btn-lg w-100">Entrar</button>
    </form>

    <p class="driver-help">Esqueceu o acesso? Fale com a programação:
      <a href="https://wa.me/<?= h(WHATSAPP_EMPRESA) ?>" target="_blank" rel="noopener"><?= h(fone_exibir(WHATSAPP_EMPRESA)) ?></a>
    </p>
  </section>

<?php else: ?>

  <!-- ================= PAINEL ================= -->
  <section class="driver-panel" aria-labelledby="painelTitle">

    <div class="driver-card">
      <p class="eyebrow light"><i class="lines" aria-hidden="true"></i> Conectado</p>
      <h1 id="painelTitle" class="driver-h1">Olá, <?= h(explode(' ', $motorista['nome'])[0]) ?></h1>
      <p class="driver-sub"><?= h($motorista['veiculo'] ?: 'Frota AATR') ?></p>
    </div>

    <?php if (!$viagens): ?>
      <div class="driver-card">
        <h2 class="driver-h2">Nenhuma viagem para você agora</h2>
        <p class="driver-sub">Assim que a programação cadastrar uma viagem no seu nome, ela aparece aqui.
          Atualize a página quando for avisado.</p>
        <a href="motorista.php" class="btn btn-outline-light w-100">Atualizar</a>
      </div>
    <?php else: ?>

      <!-- Passo 1: escolher a viagem -->
      <div class="driver-card">
        <span class="step-badge">Passo 1</span>
        <h2 class="driver-h2">Sua viagem</h2>
        <p class="driver-sub">Toque na viagem que você vai fazer agora.</p>

        <div class="trip-list" id="tripList" role="radiogroup" aria-label="Viagens disponíveis"></div>

        <div class="trip-detail d-none" id="tripDetail" aria-live="polite"></div>

        <button type="button" class="btn btn-brand btn-lg w-100 mt-3 d-none" id="btnIniciar">
          Iniciar viagem
        </button>
        <p class="driver-msg" id="msgViagem" role="alert"></p>
      </div>

      <!-- Passo 2: localização -->
      <div class="driver-card">
        <span class="step-badge">Passo 2</span>
        <h2 class="driver-h2">Mandar minha localização</h2>
        <p class="driver-sub">O celular vai pedir permissão de GPS. Toque em <b>Permitir</b>.</p>

        <button type="button" class="btn btn-outline-light btn-lg w-100" id="btnGps">Pegar localização atual</button>

        <div class="gps-box d-none" id="gpsBox" aria-live="polite">
          <div class="gps-row"><span>Coordenadas</span><b id="gpsCoord">—</b></div>
          <div class="gps-row"><span>Precisão</span><b id="gpsPrec">—</b></div>
          <div class="gps-row"><span>Horário</span><b id="gpsHora">—</b></div>
          <a href="#" target="_blank" rel="noopener" class="gps-map" id="gpsMapa">Conferir no mapa</a>
        </div>

        <p class="driver-msg" id="gpsMsg" role="alert"></p>

        <div class="driver-field mt-3">
          <label for="recado">Recado <i>(opcional)</i></label>
          <input type="text" id="recado" class="form-control" maxlength="120"
                 placeholder="ex.: parado na fila da balança">
        </div>

        <div class="driver-field">
          <label>WhatsApp do contratante</label>
          <div class="fone-fixo" id="foneContratante">Escolha a viagem no passo 1</div>
          <p class="campo-nota">Vem do cadastro da viagem. Quem altera é a programação.</p>
        </div>

        <button type="button" class="btn btn-brand btn-lg w-100" id="btnEnviar">Enviar localização pelo WhatsApp</button>
        <button type="button" class="btn btn-outline-light w-100 mt-2" id="btnCopiar">Copiar mensagem</button>
        <p class="driver-msg" id="envioMsg" role="alert"></p>
      </div>

      <!-- Passo 3: chegada -->
      <div class="driver-card">
        <span class="step-badge">Passo 3</span>
        <h2 class="driver-h2">Cheguei no destino</h2>
        <p class="driver-sub">Aperte só quando o caminhão estiver no local da descarga.
          Isso encerra a linha do tempo do contratante.</p>

        <button type="button" class="btn btn-chegada btn-lg w-100" id="btnChegada">Cheguei no destino</button>
        <p class="driver-msg" id="chegadaMsg" role="alert"></p>
      </div>

      <p class="driver-note">
        Cada toque é registrado no sistema <b>antes</b> de abrir o WhatsApp — o contratante vê o
        andamento na página de rastreio mesmo que a mensagem não seja enviada.
      </p>

    <?php endif; ?>
  </section>

<?php endif; ?>

  <p class="driver-foot">AATR Transporte &amp; Logística · <a href="index.html">Voltar ao site</a></p>
</div>

<?php if ($motorista && $viagens): ?>
<script>
window.AATR = <?= json_encode($pacote, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?>;
window.AATR.csrf = <?= json_encode(csrf_token()) ?>;
</script>
<script src="motorista.js"></script>
<?php endif; ?>

</body>
</html>
