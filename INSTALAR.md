# AATR — sistema de acompanhamento de viagens

Gestor cadastra a viagem · motorista opera três botões no celular · contratante
acompanha por um link. PHP + MySQL, sem Composer, sem Node, feito para rodar em
hospedagem compartilhada com cPanel e phpMyAdmin.

---

## 1. Instalar (uma vez, ~10 minutos)

### 1.1 Subir os arquivos
Mande todo o conteúdo desta pasta para o `public_html` (ou a pasta do domínio).
Mantenha a estrutura: as pastas `inc/`, `api/`, `admin/` e `sql/` precisam ficar
lado a lado com o `index.html`.

### 1.2 Criar o banco
No cPanel → **Bancos de dados MySQL**:

1. crie um banco (ex.: `renato_aatr`);
2. crie um usuário e uma senha;
3. associe o usuário ao banco com **todos os privilégios**;
4. anote nome do banco, usuário e senha.

### 1.3 Importar a estrutura
No **phpMyAdmin**, selecione o banco criado → aba **Importar** → envie
`sql/aatr.sql` → **Executar**.

Isso cria as cinco tabelas — **e só isso**. Nenhum usuário, nenhuma senha,
nenhum hash: o arquivo vive num repositório público, então nada dentro dele
serve de acesso ao sistema. Os acessos são criados no passo 1.6.

### 1.4 Criar o `config.local.php` no servidor
Crie um arquivo `config.local.php` na mesma pasta do `config.php`, com os
dados reais:

```php
<?php
define('DB_HOST', 'localhost');
define('DB_NAME', 'renato_aatr');          // banco criado no passo 1.2
define('DB_USER', 'renato_user');
define('DB_PASS', 'a-senha-do-banco');

define('SITE_URL', 'https://www.aatrtransporte.com.br');   // sem barra no final
define('WHATSAPP_EMPRESA', '5511969104308');               // só dígitos
define('FORCAR_HTTPS', true);                              // depois do passo 1.5
```

O `config.php` lê esse arquivo antes de tudo e respeita o que estiver nele.

**Por que num arquivo separado:** o `config.php` vai para o repositório do
GitHub, então não pode carregar a senha do banco. O `config.local.php` está no
`.gitignore` e fica só no servidor. Se preferir editar o `config.php` direto,
funciona igual — mas aí nunca dê `git add` nele.

`SITE_URL` é o que monta o link de rastreio dentro da mensagem de WhatsApp —
se estiver errado, o contratante recebe um link quebrado.

### 1.5 Ligar o HTTPS
**Obrigatório.** Sem certificado, o navegador não libera o GPS e a área do
motorista não funciona. Ative o SSL grátis no cPanel, depois:

- em `config.php`, troque para `define('FORCAR_HTTPS', true);`
- ou descomente o bloco `RewriteEngine` no `.htaccess`

### 1.6 Criar o acesso do gestor
Abra `https://seudominio.com.br/instalar.php` e preencha nome, e-mail e a
senha que **você** escolher (mínimo 8 caracteres). Esse vira o primeiro acesso
do painel.

Essa tela só funciona enquanto não existir nenhum gestor cadastrado — depois de
criado o primeiro, ela se recusa a rodar. Ainda assim:

> **Apague o `instalar.php` do servidor** logo depois de usar, por FTP ou pelo
> gerenciador de arquivos da hospedagem. Enquanto ele estiver lá, o painel
> mostra um aviso em todas as telas.

### 1.7 Cadastrar os motoristas
No painel, em *Motoristas*, crie um acesso para cada um: você define o código
(`joao.silva`) e a senha, e entrega para ele. A senha fica gravada
criptografada — nem você lê de volta depois. Se o motorista esquecer, é só
definir uma nova.

Não há motorista de exemplo no sistema: todo acesso nasce de uma senha
escolhida por você.

---

## 2. O dia a dia

### Gestor — `/admin`
Cadastra a viagem: código, contratante e o WhatsApp dele, origem, destino,
motorista, veículo e carga.

Deixando **"Calcular distância e tempo pela rota"** marcado, o sistema busca a
rota real de estrada sozinho. O que você digitar na mão sempre vence — o
cálculo só preenche campo em branco.

Na tela da viagem você tem o **link do contratante** pronto para copiar, a
linha do tempo ao vivo, e pode publicar recados nela ("descarga reagendada
para amanhã às 8h"), cancelar, reabrir ou excluir.

### Motorista — `/motorista.php`
Entra com o código e a senha que a programação passou. Vê **apenas as viagens
dele**. Quatro toques na viagem inteira:

1. escolhe a viagem;
2. **Iniciar viagem**;
3. **Pegar localização** → recado opcional → **Enviar pelo WhatsApp** (pode
   repetir quantas vezes quiser durante o trajeto);
4. **Cheguei no destino**.

O WhatsApp do contratante já vem preenchido do cadastro — o motorista não
digita número nenhum.

**Cada toque grava no banco antes de abrir o WhatsApp.** Se ele esquecer de
apertar enviar na conversa, o contratante vê o registro na página do mesmo
jeito.

### Contratante — `/rastreio.php?codigo=AATR-4417-BR`
Sem login: o código é a chave. Vê a rota, os KM, o tempo de viagem, uma barra
com o caminhão andando e a linha do tempo se preenchendo. A página se atualiza
sozinha a cada 45 segundos.

O painel da home (`index.html`) consulta o mesmo banco e leva para essa página.

---

## 3. Segurança

- Senhas gravadas com `password_hash` (bcrypt). Nem o gestor lê de volta — se
  o motorista esquecer, define uma nova.
- Login conferido no servidor. O antigo `motorista.html` trazia usuário e senha
  escritos no JavaScript, visíveis a quem abrisse o código-fonte da página;
  ele agora só redireciona para a versão nova.
- Todas as consultas usam *prepared statements* (sem injeção de SQL).
- Formulários e ações do motorista protegidos por token CSRF.
- 8 tentativas erradas travam o usuário por 15 minutos. O limite por IP é bem
  mais folgado de propósito: motorista na estrada entra pelo 4G, e a operadora
  põe centenas de aparelhos atrás do mesmo IP.
- Quem decide se uma ação vale é sempre o servidor. Mandar posição antes de
  iniciar, iniciar duas vezes, mexer em viagem de outro motorista ou registrar
  depois de encerrada — tudo recusado no PHP, não só escondido na tela.
- A página pública **não** mostra o telefone do contratante nem o código de
  acesso do motorista.
- `inc/`, `sql/` e `config.php` são bloqueados pelo `.htaccess`.

---

## 4. Mapa dos arquivos

```
config.php            o único que você edita
.htaccess             bloqueios, HTTPS, cache

index.html            site institucional (painel da home ligado ao banco)
rastreio.php          página do contratante
motorista.php         área do motorista (login no servidor)
motorista.js          GPS e os três botões — sem senha nenhuma
main.js / style.css   site

admin/
  login.php  index.php  viagem.php  motoristas.php  senha.php  logout.php

api/
  rastreio.php        consulta pública por código (JSON)
  motorista.php       iniciar / posição / chegada

inc/
  db.php       conexão PDO
  auth.php     sessão, login, CSRF, trava de força bruta
  helpers.php  formatação, telefone, escape
  geo.php      distância e tempo (OSRM → estimativa → manual)
  viagem.php   as regras da viagem

sql/aatr.sql          estrutura do banco + acessos iniciais
```

---

## 5. Se algo der errado

**"Sistema indisponível — não foi possível conectar ao banco"**
Dados errados em `config.php`. Confira nome do banco, usuário e senha, e se o
usuário está associado ao banco com todos os privilégios.

**"O GPS só funciona com o site em HTTPS"**
O certificado não está ativo, ou o site foi aberto por `http://`. Veja 1.5.

**O botão não faz nada / "sua sessão expirou"**
A sessão caiu. Recarregue a página e entre de novo.

**A distância veio errada**
Digite o valor certo nos campos *Distância* e *Tempo de viagem* e desmarque o
cálculo automático. O que você digita sempre vence.

**A rota não é calculada**
Algumas hospedagens bloqueiam saída para a internet. Nada quebra: o sistema
avisa e você preenche na mão. Para desligar as tentativas de vez, ponha
`define('ROTA_ONLINE', false);` em `config.php`.

**Quero ver o erro de verdade**
Ponha `define('APP_DEBUG', true);` em `config.php` enquanto investiga.
**Volte para `false`** depois — com debug ligado, mensagens internas aparecem
para o visitante.

---

## 6. O que este sistema não é

**A distância restante é uma estimativa.** Ela é calculada em linha reta entre a
posição que o motorista enviou e o destino, corrigida por um fator de estrada.
Para o contratante ("faltam 126 km") o número é útil e aponta certo, mas em rota
com muita curva ele fica otimista. Precisão de odômetro exigiria integração com
o rastreador do veículo.

**A posição não é contínua.** O sistema registra onde o motorista estava quando
ele apertou o botão — não há ponto azul se mexendo sozinho. Para acompanhamento
em tempo real, use a *localização em tempo real* dentro do próprio WhatsApp,
que roda em paralelo a isto.

**Os KM da rota vêm do OSRM público**, que calcula para carro e é gratuito, sem
contrato de disponibilidade. Ele erra alguns por cento para menos em rotas
longas (Jundiaí → Uberlândia deu 535 km contra ~575 km reais). Para número
contratual, digite na mão.

**O código de rastreio é a única chave.** Quem tiver o código vê a viagem. É o
mesmo modelo das transportadoras grandes, e por isso a página não mostra
telefone nem dado pessoal — mas vale usar códigos pouco óbvios.
