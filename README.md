# AATR Transporte & Logística

Site institucional e sistema de acompanhamento de viagens de carga fechada.

**Gestor** cadastra a viagem · **motorista** opera três botões no celular ·
**contratante** acompanha por um link com o código da viagem.

PHP + MySQL puro: sem Composer, sem Node, sem framework. Feito para rodar em
hospedagem compartilhada com cPanel e phpMyAdmin.

---

## Como funciona

| Quem | Onde | O que faz |
|---|---|---|
| Gestor | `/admin` | Cadastra viagem, contratante, WhatsApp, rota, motorista e carga. Distância e tempo saem sozinhos da rota real de estrada. |
| Motorista | `/motorista.php` | Escolhe a viagem, **Iniciar**, **pega a localização** (com recado), **Cheguei no destino**. Vê só as viagens dele. |
| Contratante | `/rastreio.php?codigo=AATR-4417-BR` | Rota, KM, tempo, barra de andamento e linha do tempo. Sem login, atualiza sozinha. |

Cada toque do motorista grava no banco **antes** de abrir o WhatsApp — a linha
do tempo do contratante existe mesmo se a mensagem não for enviada.

## Rodar na sua máquina

Com o [XAMPP](https://www.apachefriends.org/) instalado em `C:\xampp`, dê dois
cliques em `testar-local.bat`. Ele sobe o MySQL, cria o banco, importa a
estrutura e abre `http://localhost:8080`.

Na primeira vez, abra `http://localhost:8080/instalar.php` e crie o acesso do
gestor com a senha que você quiser. Depois, dentro do painel: cadastre um
motorista e uma viagem para ter o que testar.

**Não existe usuário nem senha de fábrica.** Nada neste repositório serve de
acesso ao sistema — é de propósito, já que o código é público.

O GPS funciona em `localhost` (o navegador trata como origem segura), mas **não**
funciona se você abrir pelo IP da rede local — para testar no celular, precisa
de HTTPS de verdade.

## Subir para a hospedagem

O passo a passo completo está em **[INSTALAR.md](INSTALAR.md)**. Resumo:

1. suba os arquivos mantendo a estrutura de pastas;
2. crie o banco no cPanel e importe `sql/aatr.sql` pelo phpMyAdmin;
3. crie um `config.local.php` no servidor com os dados reais do banco
   (veja abaixo);
4. ative o HTTPS — **sem certificado o navegador não libera o GPS**;
5. abra `/instalar.php`, crie o acesso do gestor com a sua senha, e
   **apague o instalar.php do servidor** (o painel avisa enquanto ele estiver lá);
6. cadastre os motoristas no painel — você escolhe o código e a senha de cada um.

### Credenciais ficam fora do repositório

O `config.php` versionado tem só valores de exemplo. Os dados reais vão num
`config.local.php` criado direto no servidor, que o `.gitignore` mantém fora
do repositório:

```php
<?php
define('DB_HOST', 'localhost');
define('DB_NAME', 'seu_banco');
define('DB_USER', 'seu_usuario');
define('DB_PASS', 'sua_senha');
define('SITE_URL', 'https://www.aatrtransporte.com.br');
define('WHATSAPP_EMPRESA', '5511969104308');
define('FORCAR_HTTPS', true);
```

O `config.php` lê esse arquivo antes de tudo e respeita o que estiver nele.

## Estrutura

```
config.php            template de configuração (sem segredos)
instalar.php          cria o primeiro acesso (apague depois de usar)
.htaccess             bloqueios, HTTPS, cache

index.html            site institucional
rastreio.php          página do contratante
motorista.php         área do motorista (login no servidor)
main.js  motorista.js  style.css

admin/                painel do gestor
api/                  rastreio público + ações do motorista
inc/                  db, auth, helpers, geo, regras da viagem
sql/aatr.sql          estrutura do banco + acessos iniciais
```

## Segurança

- **Nenhuma senha no repositório**: o `sql/aatr.sql` cria só a estrutura, sem
  usuários e sem hashes. Todo acesso nasce de uma senha escolhida por quem instala
- Senhas com `password_hash` (bcrypt); login conferido no servidor
- Prepared statements em todas as consultas
- Token CSRF nos formulários e nas ações do motorista
- Trava de força bruta: 8 tentativas por usuário, limite folgado por IP
  (motorista na estrada divide o IP da operadora)
- Regras da viagem decididas no servidor: posição antes de iniciar, iniciar
  duas vezes, mexer em viagem de outro motorista e registrar depois de
  encerrada são recusados no PHP
- Página pública não expõe telefone do contratante nem acesso do motorista
- `inc/`, `sql/` e `config.php` bloqueados pelo `.htaccess`

## Limitações conhecidas

- A **distância restante** é linha reta corrigida entre a posição enviada e o
  destino. Boa como referência, não é leitura de odômetro.
- A **posição não é contínua**: registra onde o motorista estava quando apertou
  o botão. Para ponto azul em tempo real, use a localização ao vivo do WhatsApp.
- Os **KM da rota** vêm do OSRM público (gratuito, calcula para carro) e erram
  alguns por cento para menos em rota longa. Para número contratual, digite na
  mão — o que o gestor informa sempre vence.
