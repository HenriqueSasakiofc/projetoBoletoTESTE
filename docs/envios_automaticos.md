# Envios Automaticos por Vencimento

## Objetivo

O sistema agora agenda e envia cobrancas automaticas com base na data de vencimento de cada titulo, e nao mais na data de importacao da planilha.

Eventos automaticos:

- `lembrete_7_dias`: envia exatamente 7 dias antes do vencimento
- `vencimento_hoje`: envia exatamente no dia do vencimento
- `vencido`: envia uma vez a partir do primeiro dia apos o vencimento

## Historico e anti-duplicacao

Cada envio automatico gera um registro em `outbox_messages` com:

- `receivable_id`
- `customer_id`
- `notification_event`
- `scheduled_for_date`
- `status`
- `sent_at`
- `error_message`

O sistema bloqueia duplicidade por:

- empresa
- cobranca
- evento automatico
- data logica do evento

Exemplo:

- titulo vence em `2026-04-10`
- lembrete de 7 dias: `2026-04-03`
- vencimento hoje: `2026-04-10`
- vencido: `2026-04-11`

Mesmo que o job rode varias vezes em `2026-04-12`, o evento `vencido` continua sendo unico para essa cobranca.

## Regras de atraso do job

- `lembrete_7_dias`: so envia no dia exato
- `vencimento_hoje`: so envia no dia exato
- `vencido`: se o job falhar no primeiro dia, envia uma vez no proximo processamento disponivel

## Importacao

A importacao continua criando e atualizando clientes e cobrancas normalmente, mas nao dispara mais envios automaticos ao finalizar o lote.

## Migracao do banco

Antes de usar a rotina nova em uma base existente, rode:

```powershell
php scripts/migrate_automatic_notifications.php
```

## Execucao manual

Rodar para uma empresa especifica, sem enviar SMTP:

```powershell
php scripts/run_auto_notifications.php --company-id=1 --date=2026-04-10 --dry-run --skip-dispatch
```

Rodar para uma empresa e tentar enviar a fila:

```powershell
php scripts/run_auto_notifications.php --company-id=1 --date=2026-04-10
```

Rodar para todas as empresas:

```powershell
php scripts/run_auto_notifications.php --date=2026-04-10
```

## Agendamento diario

No Windows Task Scheduler, o ideal e executar diariamente um comando como:

```powershell
php C:\Projects\projetoBoletoTESTE\scripts\run_auto_notifications.php
```

## Variavel de ambiente

Defina a timezone usada na comparacao por dia corrido:

```env
APP_TIMEZONE=America/Sao_Paulo
```
