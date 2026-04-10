# Limpeza de imports duplicados

Quando um mesmo par de planilhas for importado varias vezes durante testes, use a rotina abaixo para limpar os batches repetidos e liberar uma reimportacao limpa.

## Comando

```powershell
php scripts/purge_import_batch.php --batch-id=15
```

## Dry run

```powershell
php scripts/purge_import_batch.php --batch-id=15 --dry-run
```

## O que a rotina faz

- localiza o lote informado
- encontra todos os `upload_batches` da mesma empresa com o mesmo par de hashes
- remove `receivables` vinculados a esses batches
- remove mensagens da `outbox_messages` ligadas a essas cobrancas
- remove staging remanescente desses batches
- marca os `upload_batches` como `purged`

## Observacao

Depois da limpeza, o bloqueio de lote duplicado deixa de barrar essas mesmas planilhas, entao voce pode reimportar o par uma unica vez com os dados corretos.

## Reprocessamento pela tela

Os lotes novos agora guardam uma copia das planilhas em `storage/imports/`. Na tela de importacao, use o card `Ultimo lote` e o botao `Reprocessar lote` para limpar os dados daquele par de arquivos e rodar novamente sem pedir um novo envio ao cliente.
