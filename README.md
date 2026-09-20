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

- Um sistema contém tipos de elementos, elementos textuais e estruturas. Um elemento pode ser associado a um ou mais tipos do mesmo sistema.
- Ao cadastrar um tipo, escolha **COM espaço** (padrão) ou **SEM espaço**. Elementos de tipos sem espaço são concatenados ao elemento anterior durante a geração; os demais recebem um espaço antes deles. Também escolha **Maiúscula e minúscula** (padrão), que converte o texto para minúsculas no meio da combinação e capitaliza somente sua primeira letra quando estiver no início, ou **Inicial sempre maiúscula**, que preserva exatamente o texto cadastrado.
- Uma estrutura guarda uma lista ordenada de IDs de tipos, como `[1, 2, 1]`; ordem, repetição e quantidade variável de posições são preservadas.
- Ao gerar uma estrutura, o aplicativo calcula o produto cartesiano dos elementos disponíveis em cada posição e persiste tanto o texto quanto os IDs dos elementos na ordem selecionada.
- As chaves estrangeiras e as validações impedem que tipos ou elementos de um sistema sejam usados em outro. Um elemento não pode repetir o mesmo texto no sistema, mas pode ser associado a vários tipos; ao cadastrá-lo novamente com outros tipos, os novos vínculos são adicionados. O mesmo texto continua permitido em outro sistema.
- A interface possui páginas independentes para tipos, elementos, estruturas e combinações. Cada listagem (inclusive a de sistemas) é paginada em grupos de 10 registros; assim, apenas os itens visíveis são enviados ao navegador, mesmo quando o banco possui milhões de registros.
- No cadastro de elementos, o usuário seleciona um ou mais tipos disponíveis do sistema ativo; em cada posição de uma estrutura, seleciona um único tipo. O servidor também confirma que todos os IDs enviados pertencem ao sistema antes de salvar.

## Instalação

1. Crie um banco MySQL e execute `database/schema.sql`.
2. Copie `.env.example` para `.env` e informe as credenciais.
3. Publique a raiz deste repositório no diretório público desejado e habilite `mod_rewrite` no Apache. Permita a leitura de `.htaccess` com `AllowOverride FileInfo Options` (ou `AllowOverride All`).
4. Acesse a URL pública, crie um sistema, seus tipos, elementos e estruturas; em seguida use **Gerar** para persistir as combinações.

### Atualização de bancos existentes

Além de publicar os arquivos atualizados, adicione os índices usados pela paginação:

```sql
ALTER TABLE combination_structures ADD INDEX idx_structures_system_id (system_id, id);
ALTER TABLE generated_combinations ADD INDEX idx_combinations_system_id (system_id, id);
ALTER TABLE element_types ADD INDEX idx_types_system_id (system_id, id);
ALTER TABLE elements ADD INDEX idx_elements_system_id (system_id, id);
CREATE TABLE element_type_assignments (
  element_id BIGINT UNSIGNED NOT NULL,
  element_type_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (element_id, element_type_id),
  INDEX idx_assignments_type_element (element_type_id, element_id),
  CONSTRAINT fk_assignment_element FOREIGN KEY (element_id) REFERENCES elements(id) ON DELETE CASCADE,
  CONSTRAINT fk_assignment_type FOREIGN KEY (element_type_id) REFERENCES element_types(id) ON DELETE RESTRICT
);
-- Migre os vínculos existentes antes de remover element_type_id da tabela elements.
INSERT INTO element_type_assignments(element_id, element_type_id)
  SELECT id, element_type_id FROM elements;
ALTER TABLE elements DROP FOREIGN KEY fk_element_type, DROP INDEX idx_elements_system_type,
  DROP INDEX unique_element_per_system_type_text, DROP COLUMN element_type_id,
  ADD UNIQUE INDEX unique_element_per_system_text (system_id, text_value_hash);
ALTER TABLE element_types
  ADD COLUMN spacing ENUM('with_space', 'without_space') NOT NULL DEFAULT 'with_space' AFTER name;
ALTER TABLE element_types
  ADD COLUMN letter_case ENUM('mixed_case', 'initial_always_uppercase') NOT NULL DEFAULT 'mixed_case' AFTER spacing;
ALTER TABLE generated_combinations
  ADD COLUMN lexical_chunked INT NOT NULL DEFAULT 0 AFTER element_ids;
```

## Arquitetura

Front Controller → Router/Dispatcher implícito → páginas HTML estáticas no mesmo endpoint. A interface é uma MPA responsiva com sidebar persistente.
