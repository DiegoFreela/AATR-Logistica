/* ============================================================
   AATR — Área do motorista (painel)
   ------------------------------------------------------------
   Este arquivo não guarda mais senha nenhuma. Quem confere o
   login é o servidor (motorista.php). Aqui só tratamos GPS,
   os três botões da viagem e a abertura do WhatsApp.

   Ordem de cada ação: grava no servidor -> só depois abre o
   WhatsApp. Se o servidor recusar, nada é enviado.
   ============================================================ */
(function () {
  'use strict';

  const dados = window.AATR || { viagens: [], csrf: '' };
  const viagens = dados.viagens || [];
  if (!viagens.length) return;

  const $ = (id) => document.getElementById(id);

  const tripList   = $('tripList');
  const tripDetail = $('tripDetail');
  const btnIniciar = $('btnIniciar');
  const btnGps     = $('btnGps');
  const btnEnviar  = $('btnEnviar');
  const btnCopiar  = $('btnCopiar');
  const btnChegada = $('btnChegada');
  const foneBox    = $('foneContratante');
  const recado     = $('recado');

  let atual = null;          // viagem selecionada
  let posicao = null;        // última leitura do GPS
  let ultimaMensagem = '';   // texto montado pelo servidor, para o botão copiar

  /* ============================================================
     Mensagens na tela
     ============================================================ */
  const aviso = (el, texto, tipo) => {
    const alvo = $(el);
    if (!alvo) return;
    alvo.textContent = texto || '';
    alvo.className = 'driver-msg' + (tipo ? ' ' + tipo : '');
  };

  const limparAvisos = () => {
    ['msgViagem', 'envioMsg', 'chegadaMsg'].forEach(id => aviso(id, ''));
  };

  /* ============================================================
     Conversa com o servidor
     ============================================================ */
  const api = async (acao, extra) => {
    let resposta;
    try {
      resposta = await fetch('api/motorista.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF': dados.csrf },
        body: JSON.stringify(Object.assign({ acao: acao }, extra || {}))
      });
    } catch (e) {
      throw new Error('Sem conexão com o servidor. Confira o sinal de internet e tente de novo.');
    }

    let corpo;
    try {
      corpo = await resposta.json();
    } catch (e) {
      throw new Error('O servidor respondeu de um jeito inesperado. Tente de novo em instantes.');
    }

    if (!resposta.ok || !corpo.ok) {
      throw new Error(corpo.erro || 'Não foi possível concluir esta ação.');
    }
    return corpo;
  };

  /* Abre o WhatsApp. A janela é aberta no toque (síncrono) e só
     depois recebe o endereço — senão o celular bloqueia o popup. */
  const abrirWhatsApp = (janela, numero, texto) => {
    const url = 'https://wa.me/' + numero + '?text=' + encodeURIComponent(texto);
    if (janela && !janela.closed) {
      janela.location.href = url;
    } else {
      window.location.href = url;
    }
  };

  const fecharJanela = (janela) => {
    if (janela && !janela.closed) {
      try { janela.close(); } catch (e) { /* ignora */ }
    }
  };

  /* ============================================================
     Lista de viagens
     ============================================================ */
  const montarLista = () => {
    tripList.innerHTML = '';

    viagens.forEach((v) => {
      const item = document.createElement('button');
      item.type = 'button';
      item.className = 'trip-item' + (atual && atual.codigo === v.codigo ? ' on' : '');
      item.setAttribute('role', 'radio');
      item.setAttribute('aria-checked', atual && atual.codigo === v.codigo ? 'true' : 'false');

      const km = v.distancia_km ? v.distancia_km + ' km · ' + v.duracao : v.duracao;

      item.innerHTML =
        '<span class="trip-code">' + v.codigo + '</span>' +
        '<span class="trip-badge ' + v.status + '">' + v.status_label + '</span>' +
        '<span class="trip-route">' + v.origem + ' → ' + v.destino + '</span>' +
        '<span class="trip-km">' + km + '</span>';

      item.addEventListener('click', () => selecionar(v));
      tripList.appendChild(item);
    });
  };

  const selecionar = (v) => {
    atual = v;
    limparAvisos();
    montarLista();

    tripDetail.classList.remove('d-none');
    tripDetail.innerHTML =
      '<div class="trip-detail-row"><span>Contratante</span><b>' + v.contratante + '</b></div>' +
      (v.carga ? '<div class="trip-detail-row"><span>Carga</span><b>' + v.carga + '</b></div>' : '') +
      (v.iniciada_em ? '<div class="trip-detail-row"><span>Iniciada</span><b>' + v.iniciada_em + '</b></div>' : '') +
      '<a class="trip-link" href="' + v.rastreio + '" target="_blank" rel="noopener">Ver a página do contratante</a>';

    foneBox.textContent = v.fone_exibir || 'Sem telefone cadastrado';
    foneBox.classList.toggle('vazio', !v.fone_exibir);

    atualizarBotoes();
  };

  const atualizarBotoes = () => {
    if (!atual) return;

    const agendada = atual.status === 'agendada';
    const rodando  = atual.status === 'em_viagem';

    btnIniciar.classList.toggle('d-none', !agendada);

    btnEnviar.disabled  = !rodando;
    btnChegada.disabled = !rodando;

    btnEnviar.textContent = rodando
      ? 'Enviar localização pelo WhatsApp'
      : 'Inicie a viagem no passo 1';
    btnChegada.textContent = rodando
      ? 'Cheguei no destino'
      : 'Inicie a viagem no passo 1';
  };

  /* ============================================================
     Passo 1 — Iniciar viagem
     ============================================================ */
  btnIniciar.addEventListener('click', async () => {
    if (!atual) { aviso('msgViagem', 'Escolha a viagem primeiro.', 'erro'); return; }

    const janela = window.open('', '_blank');
    btnIniciar.disabled = true;
    aviso('msgViagem', 'Registrando o início da viagem...', '');

    try {
      const r = await api('iniciar', { codigo: atual.codigo });

      atual.status = r.viagem.status;
      atual.status_label = r.viagem.status_label;
      montarLista();
      atualizarBotoes();
      aviso('msgViagem', r.mensagem, 'ok');

      ultimaMensagem = r.whatsapp.texto;
      if (r.whatsapp.numero) {
        abrirWhatsApp(janela, r.whatsapp.numero, r.whatsapp.texto);
      } else {
        fecharJanela(janela);
        aviso('msgViagem', r.mensagem + ' (a viagem não tem WhatsApp do contratante cadastrado)', 'alerta');
      }
    } catch (e) {
      fecharJanela(janela);
      aviso('msgViagem', e.message, 'erro');
    } finally {
      btnIniciar.disabled = false;
    }
  });

  /* ============================================================
     Passo 2 — GPS
     ============================================================ */
  btnGps.addEventListener('click', () => {
    if (!navigator.geolocation) {
      aviso('gpsMsg', 'Este navegador não consegue pegar localização. Tente pelo Chrome ou Safari do celular.', 'erro');
      return;
    }
    if (location.protocol !== 'https:' && location.hostname !== 'localhost' && location.hostname !== '127.0.0.1') {
      aviso('gpsMsg', 'O GPS só funciona com o site em HTTPS. Peça ao suporte para ativar o certificado no domínio.', 'erro');
      return;
    }

    aviso('gpsMsg', 'Procurando sinal de GPS...', '');
    btnGps.disabled = true;

    navigator.geolocation.getCurrentPosition(
      (pos) => {
        btnGps.disabled = false;
        posicao = {
          lat: pos.coords.latitude,
          lon: pos.coords.longitude,
          prec: Math.round(pos.coords.accuracy),
          hora: new Date()
        };

        $('gpsCoord').textContent = posicao.lat.toFixed(6) + ', ' + posicao.lon.toFixed(6);
        $('gpsPrec').textContent = '± ' + posicao.prec + ' m';
        $('gpsHora').textContent = posicao.hora.toLocaleString('pt-BR', {
          day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit'
        });
        $('gpsMapa').href = 'https://www.google.com/maps?q=' +
          posicao.lat.toFixed(6) + ',' + posicao.lon.toFixed(6);
        $('gpsBox').classList.remove('d-none');

        if (posicao.prec > 100) {
          aviso('gpsMsg', 'Sinal fraco (± ' + posicao.prec + ' m). Se puder, saia de baixo de cobertura e pegue de novo.', 'alerta');
        } else {
          aviso('gpsMsg', 'Localização capturada.', 'ok');
        }
      },
      (err) => {
        btnGps.disabled = false;
        const textos = {
          1: 'Permissão negada. Libere a localização para este site nas configurações do navegador e tente de novo.',
          2: 'Não foi possível achar o sinal. Vá para um lugar aberto e tente de novo.',
          3: 'Demorou demais para responder. Toque no botão novamente.'
        };
        aviso('gpsMsg', textos[err.code] || 'Não deu para pegar a localização.', 'erro');
      },
      { enableHighAccuracy: true, timeout: 20000, maximumAge: 0 }
    );
  });

  /* ============================================================
     Passo 2 — Enviar localização
     ============================================================ */
  btnEnviar.addEventListener('click', async () => {
    if (!atual) { aviso('envioMsg', 'Escolha a viagem no passo 1.', 'erro'); return; }
    if (atual.status !== 'em_viagem') {
      aviso('envioMsg', 'Aperte "Iniciar viagem" antes de mandar a localização.', 'erro');
      return;
    }
    if (!posicao) {
      aviso('envioMsg', 'Pegue a localização primeiro, no botão acima.', 'erro');
      return;
    }

    const janela = window.open('', '_blank');
    btnEnviar.disabled = true;
    aviso('envioMsg', 'Registrando a posição...', '');

    try {
      const r = await api('posicao', {
        codigo: atual.codigo,
        lat: posicao.lat,
        lon: posicao.lon,
        precisao: posicao.prec,
        recado: recado.value.trim()
      });

      ultimaMensagem = r.whatsapp.texto;
      const falta = r.restante_km !== null && r.restante_km !== undefined
        ? ' Faltam aprox. ' + r.restante_km + ' km.' : '';

      if (r.whatsapp.numero) {
        abrirWhatsApp(janela, r.whatsapp.numero, r.whatsapp.texto);
        aviso('envioMsg', 'Registrado.' + falta + ' Abrindo o WhatsApp...', 'ok');
      } else {
        fecharJanela(janela);
        aviso('envioMsg', 'Registrado.' + falta + ' A viagem não tem WhatsApp cadastrado — use "Copiar mensagem".', 'alerta');
      }
      recado.value = '';
    } catch (e) {
      fecharJanela(janela);
      aviso('envioMsg', e.message, 'erro');
    } finally {
      btnEnviar.disabled = false;
      atualizarBotoes();
    }
  });

  /* ============================================================
     Passo 2 — Copiar a última mensagem
     ============================================================ */
  btnCopiar.addEventListener('click', async () => {
    if (!ultimaMensagem) {
      aviso('envioMsg', 'Envie a localização primeiro. Aí a mensagem fica pronta para copiar.', 'erro');
      return;
    }
    try {
      await navigator.clipboard.writeText(ultimaMensagem);
      aviso('envioMsg', 'Mensagem copiada. É só colar onde precisar.', 'ok');
    } catch (e) {
      const ta = document.createElement('textarea');
      ta.value = ultimaMensagem;
      ta.setAttribute('readonly', '');
      ta.style.position = 'fixed';
      ta.style.opacity = '0';
      document.body.appendChild(ta);
      ta.select();
      try { document.execCommand('copy'); } catch (e2) { /* ignora */ }
      ta.remove();
      aviso('envioMsg', 'Mensagem copiada.', 'ok');
    }
  });

  /* ============================================================
     Passo 3 — Cheguei no destino
     ============================================================ */
  btnChegada.addEventListener('click', async () => {
    if (!atual) { aviso('chegadaMsg', 'Escolha a viagem no passo 1.', 'erro'); return; }
    if (atual.status !== 'em_viagem') {
      aviso('chegadaMsg', 'Esta viagem ainda não foi iniciada.', 'erro');
      return;
    }
    if (!window.confirm('Confirmar chegada em ' + atual.destino + '?\n\nIsso encerra a viagem e não dá para desfazer no celular.')) {
      return;
    }

    const janela = window.open('', '_blank');
    btnChegada.disabled = true;
    aviso('chegadaMsg', 'Registrando a chegada...', '');

    try {
      const r = await api('chegada', {
        codigo: atual.codigo,
        recado: recado.value.trim()
      });

      atual.status = r.viagem.status;
      atual.status_label = r.viagem.status_label;
      ultimaMensagem = r.whatsapp.texto;

      montarLista();
      atualizarBotoes();
      aviso('chegadaMsg', r.mensagem + ' Boa! Recarregue a página para ver as próximas viagens.', 'ok');

      if (r.whatsapp.numero) {
        abrirWhatsApp(janela, r.whatsapp.numero, r.whatsapp.texto);
      } else {
        fecharJanela(janela);
      }
      recado.value = '';
    } catch (e) {
      fecharJanela(janela);
      aviso('chegadaMsg', e.message, 'erro');
    } finally {
      btnChegada.disabled = false;
    }
  });

  /* ============================================================
     Início
     ============================================================ */
  // já abre com a viagem em andamento, se houver; senão a primeira
  const emAndamento = viagens.find(v => v.status === 'em_viagem');
  selecionar(emAndamento || viagens[0]);
})();
