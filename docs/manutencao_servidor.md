# Manutencao no servidor

## Limpeza de planilhas antigas

O sistema guarda uma copia das planilhas importadas em `storage/imports` para permitir reprocessamento de lotes.
Para evitar que esses arquivos pesem o servidor com o tempo, use a rotina:

```powershell
php scripts/cleanup_old_import_files.php
```

Por padrao, a rotina remove arquivos fisicos de planilhas com mais de 30 dias.
Os dados importados no banco nao sao apagados.

Para alterar o prazo, configure no `.env`:

```env
IMPORT_FILE_RETENTION_DAYS=30
```

Tambem e possivel informar o prazo direto no comando:

```powershell
php scripts/cleanup_old_import_files.php --days=60
```

Antes de apagar de verdade, rode em modo simulacao:

```powershell
php scripts/cleanup_old_import_files.php --dry-run
```

## Agendamento recomendado

No servidor 24/7, agende a limpeza para rodar uma vez por dia.

Exemplo em Linux com cron:

```cron
30 2 * * * cd /caminho/do/projeto && php scripts/cleanup_old_import_files.php >> storage/cleanup_import_files.log 2>&1
```

Exemplo no Windows Task Scheduler:

```powershell
php C:\caminho\do\projeto\scripts\cleanup_old_import_files.php
```

Observacao: se uma planilha antiga ainda estiver sendo usada por um lote recente com o mesmo arquivo, ela nao sera removida ate que todos os lotes recentes ultrapassem o prazo de retencao.
