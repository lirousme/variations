# Lexical Chunks Combinatorics

Aplicação PHP para criar combinações ordenadas de elementos textuais. Cada **sistema** é isolado: seus tipos, elementos, estruturas e combinações geradas nunca são compartilhados com outro sistema.

## URL de acesso

A URL pública **não contém** `public_html` nem o caminho interno do projeto. Se o projeto for publicado na pasta `variations` do site, acesse:

```text
https://meusite.com/variations/
```

Se ele for o site principal, acesse `https://meusite.com/`. **Não use** `https://meusite.com/variations/public_html/variations/`: essa é uma composição equivocada de caminho físico com URL.

O front controller, os arquivos estáticos e o `.htaccess` agora estão na raiz do projeto (`index.php`, `assets/` e `.htaccess`). Portanto, configure a raiz pública do virtual host para esta raiz — ou, em hospedagem compartilhada, publique o conteúdo desta pasta diretamente em `public_html/variations/`, sem criar outro nível `public_html/variations` dentro dela.

O aplicativo detecta automaticamente quando está em um subdiretório. Só defina `APP_BASE_PATH` quando um proxy reverso alterar o caminho público; por exemplo, `APP_BASE_PATH=/variations`.

## Modelo de combinações

- Um sistema contém tipos de elementos, elementos textuais e estruturas.
- Uma estrutura guarda uma lista ordenada de IDs de tipos, como `[1, 2, 1]`; ordem, repetição e quantidade variável de posições são preservadas.
- Ao gerar uma estrutura, o aplicativo calcula o produto cartesiano dos elementos disponíveis em cada posição e persiste tanto o texto quanto os IDs dos elementos na ordem selecionada.
- As chaves estrangeiras e as validações impedem que tipos ou elementos de um sistema sejam usados em outro.

## Instalação

1. Crie um banco MySQL e execute `database/schema.sql`.
2. Copie `.env.example` para `.env` e informe as credenciais.
3. Publique a raiz deste repositório no diretório público desejado e habilite `mod_rewrite` no Apache. Permita a leitura de `.htaccess` com `AllowOverride FileInfo Options` (ou `AllowOverride All`).
4. Acesse a URL pública, crie um sistema, seus tipos, elementos e estruturas; em seguida use **Gerar** para persistir as combinações.

## Arquitetura

Front Controller → Router/Dispatcher implícito → páginas HTML estáticas no mesmo endpoint. A interface é uma MPA responsiva com sidebar persistente.
