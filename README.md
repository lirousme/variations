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
