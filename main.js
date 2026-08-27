/* ============================================================
   AATR Transporte & Logística — interações
   ============================================================ */
(function () {
  'use strict';

  const WHATSAPP = '5511969104308'; // DDI + DDD + número, só dígitos
  const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  /* ---------- Ano no rodapé ---------- */
  const ano = document.getElementById('ano');
  if (ano) ano.textContent = new Date().getFullYear();

  /* ---------- Navbar sólida ao rolar ---------- */
  const nav = document.getElementById('siteNav');
  const onScroll = () => nav.classList.toggle('solid', window.scrollY > 60);
  onScroll();
  window.addEventListener('scroll', onScroll, { passive: true });

  /* ---------- Fecha o menu ao clicar num link (mobile) ---------- */
  const menu = document.getElementById('menu');
  document.querySelectorAll('#menu a').forEach(a => {
    a.addEventListener('click', () => {
      if (menu.classList.contains('show')) {
        bootstrap.Collapse.getOrCreateInstance(menu).hide();
      }
    });
  });

  /* ---------- Reveal on scroll ---------- */
  const reveals = document.querySelectorAll('.reveal');
  if (reduced || !('IntersectionObserver' in window)) {
    reveals.forEach(el => el.classList.add('in'));
  } else {
    const io = new IntersectionObserver((entries) => {
      entries.forEach((e, i) => {
        if (e.isIntersecting) {
          setTimeout(() => e.target.classList.add('in'), (i % 4) * 90);
          io.unobserve(e.target);
        }
      });
    }, { threshold: 0.12, rootMargin: '0px 0px -60px' });
    reveals.forEach(el => io.observe(el));
  }

  /* ---------- Contadores ---------- */
  const nums = document.querySelectorAll('.num');
  const runCount = (el) => {
    const to = parseFloat(el.dataset.to);
    const dec = parseInt(el.dataset.decimals || '0', 10);
    const suffix = el.dataset.suffix || '';
    const fmt = (v) => v.toLocaleString('pt-BR', { minimumFractionDigits: dec, maximumFractionDigits: dec }) + suffix;
    if (reduced) { el.textContent = fmt(to); return; }
    const dur = 1400, t0 = performance.now();
    const tick = (t) => {
      const p = Math.min((t - t0) / dur, 1);
      el.textContent = fmt(to * (1 - Math.pow(1 - p, 3)));
      if (p < 1) requestAnimationFrame(tick);
    };
    requestAnimationFrame(tick);
  };
  if ('IntersectionObserver' in window) {
    const ioN = new IntersectionObserver((entries) => {
      entries.forEach(e => { if (e.isIntersecting) { runCount(e.target); ioN.unobserve(e.target); } });
    }, { threshold: 0.6 });
    nums.forEach(n => ioN.observe(n));
  } else {
    nums.forEach(runCount);
  }

  /* ============================================================
     PAINEL DE VIAGEM
     ------------------------------------------------------------
     Consulta o sistema da AATR (api/rastreio.php). O que aparece
     aqui é o mesmo que o contratante vê na página completa.
     ============================================================ */
  const input   = document.getElementById('codigo');
  const btn     = document.getElementById('btnRastrear');
  const empty   = document.getElementById('trackEmpty');
  const bodyBox = document.getElementById('trackBody');
  const steps   = document.querySelectorAll('#stepsTrack li');
  const truck   = document.getElementById('routeTruck');
  const link    = document.getElementById('trackLink');

  const setStage = (stage) => {
    steps.forEach(li => {
      const n = parseInt(li.dataset.step, 10);
      li.classList.remove('done', 'now');
      if (n < stage) li.classList.add('done');
      if (n === stage) li.classList.add('now');
    });
    truck.style.left = ((stage - 1) / 3 * 100) + '%';
    truck.textContent = stage === 4 ? '📦' : '🚛';
    // o emoji do caminhão aponta para a esquerda; a rota corre para a direita
    truck.classList.toggle('virado', stage !== 4);
  };

  /* Os quatro passos do painel resumem o status vindo do servidor. */
  const estagio = (d) => {
    if (d.status === 'concluida') return 4;
    if (d.status === 'agendada' || d.status === 'cancelada') return 1;
    return d.progresso >= 85 ? 3 : 2;
  };

  /* Troca só o texto do aviso, mantendo o ponto pulsante do layout. */
  const textoAviso = empty ? empty.lastChild : null;
  const mostrarAviso = (texto) => {
    bodyBox.classList.add('d-none');
    empty.classList.remove('d-none');
    if (textoAviso && textoAviso.nodeType === 3) {
      textoAviso.nodeValue = ' ' + texto;
    } else {
      empty.textContent = texto;
    }
  };

  const preencher = (d) => {
    document.getElementById('routeFrom').textContent = d.origem;
    document.getElementById('routeTo').textContent   = d.destino;
    document.getElementById('metaVeic').textContent  = d.veiculo || '—';
    document.getElementById('metaPeso').textContent  =
      d.distancia_km ? d.distancia_km.toLocaleString('pt-BR') + ' km' : '—';

    document.getElementById('metaPrev').textContent =
      d.status === 'concluida' ? 'Entregue'
      : d.status === 'cancelada' ? 'Cancelada'
      : (d.previsao || 'a definir');

    if (link) link.href = 'rastreio.php?codigo=' + encodeURIComponent(d.codigo);

    empty.classList.add('d-none');
    bodyBox.classList.remove('d-none');

    const alvo = estagio(d);
    if (reduced) { setStage(alvo); return; }

    setStage(1);
    let s = 1;
    const march = setInterval(() => {
      s++;
      if (s > alvo) { clearInterval(march); return; }
      setStage(s);
    }, 420);
  };

  const rastrear = async () => {
    const code = (input.value || '').trim().toUpperCase();
    if (code.length < 4) { input.classList.add('is-bad'); input.focus(); return; }
    input.classList.remove('is-bad');
    input.value = code;

    const rotulo = btn.textContent;
    btn.disabled = true;
    btn.textContent = 'Consultando...';
    mostrarAviso('Consultando o sistema...');

    try {
      const r = await fetch('api/rastreio.php?codigo=' + encodeURIComponent(code), { cache: 'no-store' });
      const d = await r.json();
      if (!d.ok) { mostrarAviso(d.erro || 'Não foi possível consultar agora.'); return; }
      preencher(d);
    } catch (e) {
      mostrarAviso('Não conseguimos falar com o sistema agora. Tente de novo em instantes ou chame a operação no WhatsApp.');
    } finally {
      btn.disabled = false;
      btn.textContent = rotulo;
    }
  };

  if (btn) {
    btn.addEventListener('click', rastrear);
    input.addEventListener('keydown', (e) => { if (e.key === 'Enter') rastrear(); });
    input.addEventListener('input', () => input.classList.remove('is-bad'));
    document.querySelectorAll('.code-chip').forEach(chip => {
      chip.addEventListener('click', () => { input.value = chip.dataset.code; rastrear(); });
    });
  }

  /* ============================================================
     DIMENSIONADOR DE CARGA
     ============================================================ */
  const VEICULOS = [
    { nome: 'Toco',    t: 7,  m3: 35,  nota: 'até 7 t e cerca de 8 pallets' },
    { nome: 'Truck',   t: 14, m3: 50,  nota: 'até 14 t e cerca de 14 pallets' },
    { nome: 'Carreta', t: 27, m3: 95,  nota: 'até 27 t e cerca de 28 pallets' },
    { nome: 'Bitrem',  t: 37, m3: 120, nota: 'até 37 t, para rotas longas de alto volume' }
  ];

  const szPeso = document.getElementById('szPeso');
  const szVol  = document.getElementById('szVol');
  const szOut  = document.getElementById('sizerOut');
  const szVeic = document.getElementById('sizerVeic');
  const szTxt  = document.getElementById('sizerTxt');
  const szCta  = document.getElementById('sizerCta');
  let carroceria = 'bau';

  const dimensionar = () => {
    const p = parseFloat(szPeso.value) || 0;
    const v = parseFloat(szVol.value) || 0;
    const escolhido = VEICULOS.find(x => p <= x.t && v <= x.m3);
    const nomeCarroceria = carroceria === 'bau' ? 'baú' : 'grade baixa';

    if (!escolhido) {
      szOut.classList.add('over');
      szVeic.textContent = 'Acima de 37 t';
      szTxt.textContent = 'Nessa faixa dividimos em mais de uma viagem ou avaliamos equipamento especial. Fale com a operação.';
      return;
    }

    szOut.classList.remove('over');
    szVeic.textContent = escolhido.nome + ' ' + nomeCarroceria;
    const limitante = (p / escolhido.t) >= (v / escolhido.m3) ? 'pelo peso' : 'pelo volume';
    szTxt.textContent = 'Indicado ' + limitante + ': ' + escolhido.nota +
      (carroceria === 'grade' ? '. Carga vai lonada, com cintas e catracas.' : '. Compartimento fechado e lacrado.');
  };

  if (szPeso) {
    [szPeso, szVol].forEach(el => el.addEventListener('input', dimensionar));
    document.querySelectorAll('.sizer-toggle button').forEach(b => {
      b.addEventListener('click', () => {
        document.querySelectorAll('.sizer-toggle button').forEach(x => x.classList.remove('on'));
        b.classList.add('on');
        carroceria = b.dataset.body;
        dimensionar();
      });
    });
    // leva a sugestão para o formulário de cotação
    szCta.addEventListener('click', () => {
      const peso = document.getElementById('peso');
      const carr = document.getElementById('carroceria');
      const veic = document.getElementById('veiculo');
      if (peso && szPeso.value) peso.value = szPeso.value;
      if (carr) carr.value = carroceria === 'bau' ? 'Baú' : 'Grade baixa';
      if (veic) {
        const sugestao = szVeic.textContent.split(' ')[0];
        const achou = Array.from(veic.options).find(o => o.value === sugestao);
        veic.value = achou ? sugestao : 'Indicar pela AATR';
      }
    });
    dimensionar();
  }

  /* ============================================================
     FORMULÁRIO DE COTAÇÃO → WhatsApp
     ============================================================ */
  const form = document.getElementById('quoteForm');
  const note = document.getElementById('quoteNote');

  if (form) {
    form.addEventListener('submit', (e) => {
      e.preventDefault();
      const val = (id) => (document.getElementById(id).value || '').trim();
      let ok = true;

      ['nome', 'fone', 'origem', 'destino'].forEach(id => {
        const el = document.getElementById(id);
        const bad = el.value.trim() === '';
        el.classList.toggle('is-bad', bad);
        if (bad && ok) { el.focus(); ok = false; }
      });

      if (!ok) {
        note.textContent = 'Preencha nome, WhatsApp, carregamento e descarga para seguir.';
        note.classList.add('warn');
        return;
      }

      note.textContent = 'Tudo certo. Abrindo o WhatsApp com a mensagem pronta.';
      note.classList.remove('warn');

      const msg =
        'Olá, AATR! Quero cotar um frete.\n\n' +
        'Nome: ' + val('nome') + '\n' +
        (val('empresa') ? 'Empresa: ' + val('empresa') + '\n' : '') +
        'WhatsApp: ' + val('fone') + '\n' +
        'Serviço: ' + val('servico') + '\n' +
        'Carregamento em: ' + val('origem') + '\n' +
        'Descarga em: ' + val('destino') + '\n' +
        (val('peso') ? 'Peso: ' + val('peso') + ' t\n' : '') +
        'Carroceria: ' + val('carroceria') + '\n' +
        'Veículo: ' + val('veiculo') + '\n' +
        (val('detalhes') ? 'Detalhes: ' + val('detalhes') : '');

      window.open('https://wa.me/' + WHATSAPP + '?text=' + encodeURIComponent(msg), '_blank', 'noopener');
    });

    form.querySelectorAll('.form-control').forEach(el => {
      el.addEventListener('input', () => el.classList.remove('is-bad'));
    });
  }
})();
