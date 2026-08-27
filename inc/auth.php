<?php
/* ============================================================
   AATR — sessão, login e proteção contra tentativa em massa
   ------------------------------------------------------------
   Gestor e motorista usam a mesma sessão PHP, em chaves
   separadas. A senha nunca sai do servidor: o navegador só
   recebe "entrou" ou "não entrou".
   ============================================================ */

function sessao_iniciar(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => requisicao_segura(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('AATRSESS');
    session_start();
}

/* ------------------------------------------------------------
   Bloqueio por tentativas
   ------------------------------------------------------------ */

/** Tentativas falhas deste usuário na janela. */
function login_tentativas(string $identificador): int
{
    return (int)db_valor(
        'SELECT COUNT(*) FROM login_tentativas
          WHERE identificador = ? AND criado_em > DATE_SUB(NOW(), INTERVAL ? MINUTE)',
        [minusc($identificador), LOGIN_JANELA_MIN]
    );
}

/** Tentativas falhas vindas deste IP na janela, somando todos os usuários. */
function login_tentativas_ip(): int
{
    return (int)db_valor(
        'SELECT COUNT(*) FROM login_tentativas
          WHERE ip = ? AND criado_em > DATE_SUB(NOW(), INTERVAL ? MINUTE)',
        [ip_cliente(), LOGIN_JANELA_MIN]
    );
}

/**
 * Bloqueia por usuário no limite normal e por IP num limite bem
 * mais alto.
 *
 * O limite de IP é folgado de propósito: motorista na estrada
 * entra pelo 4G, e a operadora coloca centenas de aparelhos atrás
 * do mesmo IP. Contar tudo junto no mesmo teto travaria motorista
 * que não errou senha nenhuma.
 */
function login_bloqueado(string $identificador): bool
{
    if (login_tentativas($identificador) >= LOGIN_MAX_TENTATIVAS) {
        return true;
    }
    return login_tentativas_ip() >= LOGIN_MAX_TENTATIVAS * 5;
}

function login_registrar_falha(string $identificador): void
{
    db_exec(
        'INSERT INTO login_tentativas (identificador, ip, criado_em) VALUES (?, ?, ?)',
        [minusc($identificador), ip_cliente(), agora()]
    );
    // limpeza oportunista, para a tabela não crescer sem fim
    db_exec('DELETE FROM login_tentativas WHERE criado_em < DATE_SUB(NOW(), INTERVAL 1 DAY)');
}

function login_limpar(string $identificador): void
{
    db_exec(
        'DELETE FROM login_tentativas WHERE identificador = ? AND ip = ?',
        [minusc($identificador), ip_cliente()]
    );
}

/** Minutos que ainda faltam para liberar. */
function login_espera_min(string $identificador): int
{
    $ultima = db_valor(
        'SELECT MAX(criado_em) FROM login_tentativas WHERE identificador = ? OR ip = ?',
        [minusc($identificador), ip_cliente()]
    );
    if (!$ultima) {
        return 0;
    }
    $liberaEm = strtotime($ultima) + LOGIN_JANELA_MIN * 60;
    return max(1, (int)ceil(($liberaEm - time()) / 60));
}

/* ------------------------------------------------------------
   Gestor
   ------------------------------------------------------------ */

function gestor_logar(array $gestor): void
{
    sessao_iniciar();
    session_regenerate_id(true);
    $_SESSION['gestor'] = [
        'id'    => (int)$gestor['id'],
        'nome'  => $gestor['nome'],
        'email' => $gestor['email'],
    ];
}

function gestor(): ?array
{
    sessao_iniciar();
    return $_SESSION['gestor'] ?? null;
}

function gestor_sair(): void
{
    sessao_iniciar();
    unset($_SESSION['gestor']);
}

function exigir_gestor(): array
{
    $g = gestor();
    if (!$g) {
        redirecionar('login.php');
    }
    return $g;
}

/* ------------------------------------------------------------
   Motorista
   ------------------------------------------------------------ */

function motorista_logar(array $motorista): void
{
    sessao_iniciar();
    session_regenerate_id(true);
    $_SESSION['motorista'] = [
        'id'      => (int)$motorista['id'],
        'usuario' => $motorista['usuario'],
        'nome'    => $motorista['nome'],
        'veiculo' => $motorista['veiculo'],
    ];
}

function motorista(): ?array
{
    sessao_iniciar();
    $m = $_SESSION['motorista'] ?? null;
    if (!$m) {
        return null;
    }
    // se o gestor desativar o motorista, a sessão dele cai
    $ativo = db_valor('SELECT ativo FROM motoristas WHERE id = ?', [$m['id']]);
    if ($ativo === null || (int)$ativo !== 1) {
        motorista_sair();
        return null;
    }
    return $m;
}

function motorista_sair(): void
{
    sessao_iniciar();
    unset($_SESSION['motorista']);
}

/** Para as chamadas de API do painel do motorista. */
function exigir_motorista_api(): array
{
    $m = motorista();
    if (!$m) {
        json_erro('Sua sessão expirou. Entre de novo na área do motorista.', 401);
    }
    return $m;
}

/* ------------------------------------------------------------
   CSRF — impede que outro site poste nos seus formulários
   ------------------------------------------------------------ */

function csrf_token(): string
{
    sessao_iniciar();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_campo(): string
{
    return '<input type="hidden" name="_csrf" value="' . h(csrf_token()) . '">';
}

function csrf_valido(?string $token = null): bool
{
    sessao_iniciar();
    $token = $token ?? ($_POST['_csrf'] ?? '');
    return !empty($_SESSION['csrf']) && is_string($token)
        && hash_equals($_SESSION['csrf'], $token);
}

/** Em formulário: derruba a requisição se o token não bater. */
function exigir_csrf(): void
{
    if (!csrf_valido()) {
        http_response_code(419);
        die('<h1>Sessão expirada</h1><p>Recarregue a página e tente de novo.</p>');
    }
}
