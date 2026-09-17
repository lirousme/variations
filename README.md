Noem da aplicação: Lexical Chunks Combinatorics
Estilização: TailwindCSS, darkmode.
Linguagens: PHP, JS, HTML.
Banco de dados: MySQL, dados de acesso ficam no arquivo .env (DB_HOST, DB_NAME, DB_USER, DB_PASS).
Padrão arquitetural: Front Controller, combinado com Routing/Dispatcher.
App Shell responsivo com Sidebar persistente.
Endereço dos arquivo do app: public_html/variations/
public_html/variations/app.index é o arquivo do Front Controller.
Publicação e rotas
O servidor precisa apontar o diretório público para esta pasta e permitir a leitura do .htaccess. Ele encaminha URLs para index.php; arquivos existentes, como CSS e APIs, continuam acessíveis diretamente. Em Apache, habilite mod_rewrite e use AllowOverride FileInfo Options (ou AllowOverride All) para que URLs internas não retornem 404 antes de chegarem ao Front Controller.

Não defina APP_BASE_PATH em instalações comuns. Quando houver proxy reverso, defina-o com o caminho público real, por exemplo APP_BASE_PATH=/variations.

Front Controller Pattern → Router → Dispatcher → Static HTML Pages

Arquitetura MPA (Multi-Page Application).

## Modelo de combinações

Cada **sistema** é um espaço de trabalho isolado. Dentro dele, o usuário cadastra tipos de elementos, elementos textuais e estruturas ordenadas. Uma estrutura guarda uma lista de IDs de tipos (por exemplo, `[1, 2, 1]`); portanto, ordem e repetição são preservadas e estruturas podem ter qualquer quantidade de posições.

Ao gerar uma estrutura, o aplicativo produz o produto cartesiano dos elementos disponíveis em cada posição, sempre filtrando por `system_id` e pelo tipo exigido. As combinações geradas persistem tanto o texto final quanto os IDs dos elementos, na ordem usada. As chaves estrangeiras e as validações do controlador evitam que um tipo ou elemento de outro sistema seja utilizado.

## Instalação

1. Crie um banco MySQL e execute `database/schema.sql`.
2. Copie `.env.example` para `.env` e informe as credenciais.
3. Aponte o diretório público do servidor para `public_html/variations/`, com `mod_rewrite` habilitado no Apache.
4. Acesse a aplicação, crie um sistema, seus tipos, elementos e estruturas; em seguida use **Gerar** para persistir as combinações.
