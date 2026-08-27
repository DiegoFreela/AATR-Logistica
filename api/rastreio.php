<?php
/* ============================================================
   AATR — consulta pública de viagem
   ------------------------------------------------------------
   GET api/rastreio.php?codigo=AATR-4417-BR
   Alimenta o painel da home (index.html) e a página do
   contratante. Só devolve o que é do interesse de quem
   contratou o frete: rota, andamento e linha do tempo.
   Telefone do contratante e dados de acesso NÃO saem daqui.
   ============================================================ */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/viagem.php';

header('Cache-Control: no-store');

$codigo = so_codigo(get('codigo'));

if (tamanho($codigo) < 4) {
    json_erro('Informe o número da viagem ou do CT-e.', 400);
}

$viagem = viagem_por_codigo($codigo);

if (!$viagem) {
    json_erro('Não encontramos nenhuma viagem com esse número. Confira com quem contratou o frete.', 404);
}

json_out(viagem_para_api($viagem));
