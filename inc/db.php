<?php
/* ============================================================
   AATR — conexão com o banco (PDO)
   ============================================================ */

/**
 * Devolve a conexão PDO, criando na primeira chamada.
 * Erro de conexão vira uma mensagem legível em vez de despejar
 * usuário e senha do banco na tela.
 */
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        if (APP_DEBUG) {
            die('<h1>Erro de banco</h1><pre>' . h($e->getMessage()) . '</pre>');
        }
        http_response_code(500);
        die('<h1>Sistema indisponível</h1><p>Não foi possível conectar ao banco de dados. '
          . 'Confira os dados em config.php.</p>');
    }

    return $pdo;
}

/** Executa e devolve o statement já preparado. */
function db_exec(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

/** Primeira linha, ou null. */
function db_linha(string $sql, array $params = []): ?array
{
    $linha = db_exec($sql, $params)->fetch();
    return $linha === false ? null : $linha;
}

/** Todas as linhas. */
function db_todas(string $sql, array $params = []): array
{
    return db_exec($sql, $params)->fetchAll();
}

/** Primeira coluna da primeira linha. */
function db_valor(string $sql, array $params = [])
{
    $v = db_exec($sql, $params)->fetchColumn();
    return $v === false ? null : $v;
}

/** INSERT, devolve o id gerado. */
function db_inserir(string $sql, array $params = []): int
{
    db_exec($sql, $params);
    return (int)db()->lastInsertId();
}
