# Relatório Técnico: Projeto Boleto (projetoBoletoTESTE)

## Visão Geral do Projeto
O **Projeto Boleto** é uma aplicação voltada para a gestão de contas a receber (recebíveis) e automação de comunicação com clientes. A solução permite importar grandes volumes de dados financeiros via planilhas, gerenciar o status de cada cobrança, auditar ações e disparar mensagens automáticas ou personalizadas (via e-mail) com base em gatilhos específicos (como proximidade de vencimento e inadimplência).

O sistema suporta arquitetura multi-tenant (múltiplas empresas), e o controle de acesso é robusto, utilizando perfis diferenciados baseados em permissões (Role-Based Access Control).

## Stack Tecnológico 💻
O projeto é construído predominantemente na linguagem **Python 3** no backend, combinado com uma interface estática de cliente.

### **Backend**
*   **Framework Web:** [FastAPI](https://fastapi.tiangolo.com) - Entrega alta performance e rotas tipadas, facilitando a interação e manutenção.
*   **Banco de Dados & ORM:** [SQLAlchemy 2.0](https://www.sqlalchemy.org) - Mapeamento objeto-relacional robusto. Suporte a PostgreSQL (`psycopg2-binary`) em produção e SQLite local (`boleto.db`).
*   **Autenticação/Segurança:** JWT via `python-jose`, sanitização com `passlib[bcrypt]` para senhas criptografadas.
*   **Validação de Dados:** `pydantic` e `pydantic-settings`.
*   **Processamento de Dados:** `pandas` e `openpyxl` para o processamento de planilhas de importação de lotes (`xlsx`).
*   **Servidor ASGI:** `uvicorn`.

### **Frontend**
*   **Arquitetura Base:** Interface web clássica baseada em Server-Side Delivery de arquivos estáticos. Sem framework pesado (Vanilla HTML, CSS, e JavaScript).
*   **Módulo:** Servido automaticamente via sub-modulo interno da FastAPI utilizando a classe `StaticFiles`.

---

## Arquitetura de Software e Componentes

A estrutura está contida na pasta `/backend/app`, com uma separação clara das responsabilidades.

### 1. Modelagem de Dados (`models.py`)
A base de dados é inteiramente modelada utilizando SQLAlchemy com uma linhagem muito clara:
*   **Empresas (`Company`):** Entidade base do multi-tenant.
*   **Usuários e Papéis (`User` e `RoleEnum`):** Com suporte a vários perfis: Administrador, Importador, Aprovador, Remetente, Auditor e Operador de Cliente.
*   **Clientes (`Customer`):** Detentores da dívida/recebível.
*   **Recebíveis (`Receivable`):** Um título individual, rastreando valores absolutos e saldos, com status gerenciado em máquina de estados (`PAGO`, `EM_ABERTO`, `VENCENDO`, `INADIMPLENTE`, `CANCELADO`).
*   **Mensageria (`MessageTemplate`, `OutboxMessage`, `ManualMessage`):** Controle robusto de notificações aos clientes sobre as pendências financeiras.

### 2. Serviço de Importação de Dados e Staging Área (`services/importer.py`)
Possui uma inteligência notável de processamento de planilhas:
*   **Fila/Staging (Tabelas de Validação):** Para não corromper os dados vivos, a aplicação cria um lote de upload (`UploadBatch`).
*   **Inteligência de Importação:** Transforma as planilhas recebidas utilizando Pandas. Ele normaliza os cabeçalhos exportados, faz *slugify* nos nomes de colunas, localiza registros pelo código/documento e reporta erros ou campos vazios na planilha.
*   **Motor de Deduplicação:** Tenta consolidar informações com as tabelas de validação (`CustomerLinkPending`), requerendo intervenção (merges) ou confirmações antes da sincronização oficial na base.

### 3. Automação e Fila de Mensagens (`services/notifier.py` e Filas de Envios)
*   Atua como uma camada abstrata sobre o envio de e-mails de notificação (SMTP configurado via variáveis de ambiente).
*   Garante que o estado das cobranças dispare mensagens padrão (`last_standard_message_at`) de forma contínua ou via ação de analistas.
*   Mantém o rastreamento em caixas de saídas (`OutboxMessage`) para evitar envios duplicados, logando os IDs num histórico em caso de erro.

### 4. Roteamento e Regras de Negócio (`routes/`)
Os Endpoints seguem separação lógica:
*   **`auth.py`**: Login e registro de usuários.
*   **`clients.py` / `messages.py`**: Endpoints transacionais (CRUD, recuperação, disparos de templates de cobrança).
*   **`imports.py`**: Roteamento voltado ao carregamento e processamento dos batches em staging via arquivos `UploadFile`.
*   **`pages.py`**: Intersecção com o front-end carregando HTML (como `cadastro.html`, `clientes.html` etc).

## Configurações e Variáveis de Ambiente (`config.py` e `.env.example`)
Sinalizado por `.env.example`, exige configurações de:
- Segurança (Chaves JWT, Expiração de Tokens).
- Conexão SMTP para disparos automatizados de notificações.
- Configuração de Bancos Postgres vs Ambientes Locais.
- Criação e Sementes de Acesso Root (*seed* via `settings.ADMIN_EMAIL` no arquivo `main.py`).

## Conclusões
O sistema é bem segmentado e adere a excelentes padrões modernos de engenharia voltados ao *Backend em Python*. A decisão de construir um "Staging Environment" temporário de processamento denota maturidade, pois resguarda o *Database Core* contra usuários inserindo arquivos sujos e com lixos provenientes de exportações de ERPs mal padronizadas.

O projeto está quase totalmente finalizado sob a camada de Back-End. As vias de atualizações de versão recomendadas deverão monitorar a performance do *engine* do `Pandas` local, especialmente ao ler planilhas que superem o `MAX_UPLOAD_SIZE_MB`, podendo precisar ser acoplado com uma plataforma externa como Celery ou um Worker Pool similar escalável em futuras fases.
