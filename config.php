<?php
/* ============================================================
   AATR Transporte & Logística — configuração do sistema
   ------------------------------------------------------------
   Este é o ÚNICO arquivo que você precisa editar depois de subir
   para a hospedagem. Preencha os dados do banco, o endereço do
   site e o WhatsApp da empresa.
   ============================================================ */

/* ------------------------------------------------------------
   ATENÇÃO: este arquivo vai para o repositório (GitHub).
   NÃO escreva a senha real do banco aqui.

   Crie um config.local.php ao lado deste, com os dados de
   verdade. Ele é lido primeiro, vence tudo o que está abaixo,
   e o .gitignore o mantém fora do repositório:

       <?php
       define('DB_NAME', 'seu_banco');
       define('DB_USER', 'seu_usuario');
       define('DB_PASS', 'sua_senha');
       define('SITE_URL', 'https://www.aatrtransporte.com.br');
       define('FORCAR_HTTPS', true);
   ------------------------------------------------------------ */
if (is_file(__DIR__ . '/config.local.php')) {
    require __DIR__ . '/config.local.php';
}

/* ------------------------------------------------------------
   1. BANCO DE DADOS
   Pegue estes dados no painel da hospedagem (cPanel > Bancos
   de dados MySQL). Na maioria das hospedagens o host continua
   sendo 'localhost'.
   ------------------------------------------------------------ */
defined('DB_HOST') || define('DB_HOST', 'localhost');
defined('DB_PORT') || define('DB_PORT', 3306);
defined('DB_NAME') || define('DB_NAME', 'aatr');
defined('DB_USER') || define('DB_USER', 'root');
defined('DB_PASS') || define('DB_PASS', '');

/* ------------------------------------------------------------
   2. ENDEREÇO DO SITE
   Sem barra no final. É com isso que montamos o link de rastreio
   que o motorista manda pro contratante no WhatsApp.
   ------------------------------------------------------------ */
defined('SITE_URL') || define('SITE_URL', 'https://www.aatrtransporte.com.br');

/* ------------------------------------------------------------
   3. WHATSAPP DA EMPRESA
   DDI + DDD + número, só dígitos. Sem espaço, hífen ou parêntese.
   ------------------------------------------------------------ */
defined('WHATSAPP_EMPRESA') || define('WHATSAPP_EMPRESA', '5511969104308');
defined('EMAIL_EMPRESA')    || define('EMAIL_EMPRESA', 'contato@aatrtransporte.com.br');

/* ------------------------------------------------------------
   4. CÁLCULO DE ROTA
   Com true, o sistema consulta a rota real de estrada na
   internet (OSRM + Nominatim, gratuitos, sem cadastro) ao
   cadastrar uma viagem. Se a hospedagem bloquear saída para a
   internet, ele cai sozinho para uma estimativa — e o gestor
   sempre pode digitar KM e tempo na mão.
   Deixe false se preferir preencher sempre manualmente.
   ------------------------------------------------------------ */
defined('ROTA_ONLINE')  || define('ROTA_ONLINE', true);
defined('ROTA_TIMEOUT') || define('ROTA_TIMEOUT', 8); // segundos

/* Velocidade média usada na estimativa de tempo quando não há
   rota real disponível (km/h, carga pesada em rodovia). */
defined('VELOCIDADE_MEDIA') || define('VELOCIDADE_MEDIA', 64);

/* Fator aplicado à distância em linha reta para aproximar a
   distância de estrada, quando a rota real não vem. */
defined('FATOR_ESTRADA') || define('FATOR_ESTRADA', 1.25);

/* ------------------------------------------------------------
   5. SEGURANÇA
   ------------------------------------------------------------ */
defined('LOGIN_MAX_TENTATIVAS') || define('LOGIN_MAX_TENTATIVAS', 8);
defined('LOGIN_JANELA_MIN')     || define('LOGIN_JANELA_MIN', 15);

/* Ligue depois que o certificado HTTPS estiver ativo no domínio.
   Com true, qualquer acesso em http:// é redirecionado para
   https://. Lembre: sem HTTPS o navegador não libera o GPS. */
defined('FORCAR_HTTPS') || define('FORCAR_HTTPS', false);

/* ------------------------------------------------------------
   6. AMBIENTE
   ------------------------------------------------------------ */
defined('APP_TZ')    || define('APP_TZ', 'America/Sao_Paulo');
defined('APP_DEBUG') || define('APP_DEBUG', false); // true só enquanto instala

/* ============================================================
   Daqui para baixo não precisa mexer.
   ============================================================ */
date_default_timezone_set(APP_TZ);

if (APP_DEBUG) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);
}

define('APP_RAIZ', __DIR__);

require_once __DIR__ . '/inc/helpers.php';
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/auth.php';

if (FORCAR_HTTPS && !requisicao_segura() && PHP_SAPI !== 'cli') {
    header('Location: https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'], true, 301);
    exit;
}
