<?php
namespace App\Services;

use PhpOffice\PhpSpreadsheet\IOFactory;
use App\Models\StagingCustomer;
use App\Models\StagingReceivable;

class ImporterService {
    private static function latinize(string $value): string {
        if (class_exists(\Transliterator::class)) {
            $transliterator = \Transliterator::create('NFD; [:Nonspacing Mark:] Remove; NFC;');
            if ($transliterator) {
                $value = $transliterator->transliterate($value);
            }
        } elseif (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
            if ($converted !== false) {
                $value = $converted;
            }
        }

        return $value;
    }

    // Normalizes text by removing accents, spaces and making it lowercase
    public static function slugify(?string $value): string {
        if (!$value) return '';
        $value = self::latinize(mb_strtolower(trim($value), 'UTF-8'));
        
        $unwanted_array = ['Š'=>'S', 'š'=>'s', 'Ž'=>'Z', 'ž'=>'z', 'À'=>'A', 'Á'=>'A', 'Â'=>'A', 'Ã'=>'A', 'Ä'=>'A', 'Å'=>'A', 'Æ'=>'A', 'Ç'=>'C', 'È'=>'E', 'É'=>'E',
                           'Ê'=>'E', 'Ë'=>'E', 'Ì'=>'I', 'Í'=>'I', 'Î'=>'I', 'Ï'=>'I', 'Ñ'=>'N', 'Ò'=>'O', 'Ó'=>'O', 'Ô'=>'O', 'Õ'=>'O', 'Ö'=>'O', 'Ø'=>'O', 'Ù'=>'U',
                           'Ú'=>'U', 'Û'=>'U', 'Ü'=>'U', 'Ý'=>'Y', 'Þ'=>'B', 'ß'=>'Ss', 'à'=>'a', 'á'=>'a', 'â'=>'a', 'ã'=>'a', 'ä'=>'a', 'å'=>'a', 'æ'=>'a', 'ç'=>'c',
                           'è'=>'e', 'é'=>'e', 'ê'=>'e', 'ë'=>'e', 'ì'=>'i', 'í'=>'i', 'î'=>'i', 'ï'=>'i', 'ð'=>'o', 'ñ'=>'n', 'ò'=>'o', 'ó'=>'o', 'ô'=>'o', 'õ'=>'o',
                           'ö'=>'o', 'ø'=>'o', 'ù'=>'u', 'ú'=>'u', 'û'=>'u', 'ý'=>'y', 'þ'=>'b', 'ÿ'=>'y' ];
        
        $value = strtr($value, $unwanted_array);
        // Remove everything but letters, numbers and underscores
        $value = preg_replace('/[^a-z0-9]+/', '_', $value);
        return trim($value, '_');
    }

    public static function sanitizeCell($value): ?string {
        if ($value === null) {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }

    public static function sanitizeDocument($value): ?string {
        $value = self::sanitizeCell($value);
        if (!$value) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $value);
        return $digits !== '' ? $digits : null;
    }

    public static function sanitizeEmail($value): ?string {
        $value = self::sanitizeCell($value);
        if (!$value) {
            return null;
        }

        $value = mb_strtolower($value, 'UTF-8');
        return filter_var($value, FILTER_VALIDATE_EMAIL) ? $value : null;
    }

    private static function headerAliases(): array {
        return [
            'nome', 'nome_cliente', 'nome cliente', 'nome do cliente', 'cliente', 'sacado',
            'razao_social', 'razao social', 'full_name',
            'codigo', 'código', 'codigo_cliente', 'cod_cliente', 'id_cliente', 'external_code',
            'cpf', 'cnpj', 'cpf_cnpj', 'cnpj_cpf', 'cpf/cnpj', 'cnpj/cpf', 'documento',
            'documento_cliente', 'document_number', 'customer_document_number',
            'email_para_cobranca', 'email para cobranca', 'email para cobrança',
            'email_cobranca', 'email de cobranca', 'email de cobrança',
            'email_do_faturamento', 'email do faturamento',
            'email_do_financeiro', 'email do financeiro',
            'email_financeiro', 'email_financial', 'email_billing', 'email', 'e_mail',
            'telefone', 'celular', 'phone', 'outros_contatos', 'other_contacts', 'contatos',
            'numero_titulo', 'numero titulo', 'titulo', 'título', 'receivable_number', 'document',
            'nosso_numero', 'nosso numero', 'nosso_num', 'nosso_numero_banco',
            'tipo_cobranca', 'tipo', 'carteira', 'charge_type',
            'data_emissao', 'emissao', 'emissão', 'issue_date',
            'vencimento', 'data_vencimento', 'due_date',
            'valor', 'valor_total', 'valor original', 'amount_total',
            'saldo', 'saldo_atual', 'balance_amount',
            'saldo_sem_juros', 'saldo sem juros', 'saldo sem juros multa', 'saldo sem juros/multa',
            'balance_without_interest',
            'status', 'situacao', 'situação', 'status_raw',
        ];
    }

    private static function detectHeaderRowIndex(array $rows): int {
        $bestIndex = 0;
        $bestScore = -1;
        $aliases = array_map(fn ($value) => self::slugify($value), self::headerAliases());
        $aliases = array_flip($aliases);

        foreach (array_slice($rows, 0, 10, true) as $index => $row) {
            $score = 0;
            foreach ($row as $value) {
                $normalized = self::slugify(self::sanitizeCell($value) ?? '');
                if ($normalized !== '' && isset($aliases[$normalized])) {
                    $score++;
                }
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestIndex = $index;
            }
        }

        return $bestScore >= 2 ? $bestIndex : 0;
    }

    private static function deduplicateHeaders(array $headers): array {
        $counts = [];
        $result = [];

        foreach ($headers as $index => $rawHeader) {
            $header = self::sanitizeCell($rawHeader) ?: 'coluna_' . ($index + 1);
            $count = $counts[$header] ?? 0;
            $result[] = $count === 0 ? $header : $header . '_' . ($count + 1);
            $counts[$header] = $count + 1;
        }

        return $result;
    }

    public static function normalizeName(?string $name): string {
        if (!$name) return '';
        $name = self::latinize(mb_strtolower(trim($name), 'UTF-8'));
        
        $unwanted_array = ['Š'=>'S', 'š'=>'s', 'Ž'=>'Z', 'ž'=>'z', 'À'=>'A', 'Á'=>'A', 'Â'=>'A', 'Ã'=>'A', 'Ä'=>'A', 'Å'=>'A', 'Æ'=>'A', 'Ç'=>'C', 'È'=>'E', 'É'=>'E',
                           'Ê'=>'E', 'Ë'=>'E', 'Ì'=>'I', 'Í'=>'I', 'Î'=>'I', 'Ï'=>'I', 'Ñ'=>'N', 'Ò'=>'O', 'Ó'=>'O', 'Ô'=>'O', 'Õ'=>'O', 'Ö'=>'O', 'Ø'=>'O', 'Ù'=>'U',
                           'Ú'=>'U', 'Û'=>'U', 'Ü'=>'U', 'Ý'=>'Y', 'Þ'=>'B', 'ß'=>'Ss', 'à'=>'a', 'á'=>'a', 'â'=>'a', 'ã'=>'a', 'ä'=>'a', 'å'=>'a', 'æ'=>'a', 'ç'=>'c',
                           'è'=>'e', 'é'=>'e', 'ê'=>'e', 'ë'=>'e', 'ì'=>'i', 'í'=>'i', 'î'=>'i', 'ï'=>'i', 'ð'=>'o', 'ñ'=>'n', 'ò'=>'o', 'ó'=>'o', 'ô'=>'o', 'õ'=>'o',
                           'ö'=>'o', 'ø'=>'o', 'ù'=>'u', 'ú'=>'u', 'û'=>'u', 'ý'=>'y', 'þ'=>'b', 'ÿ'=>'y' ];
        
        $name = strtr($name, $unwanted_array);
        // Remove tudo que nao for letra ou numero, preservando os espaços
        $name = preg_replace('/[^a-z0-9 ]+/', ' ', $name);
        return trim(preg_replace('/\s+/', ' ', $name));
    }

    // Helps parsing the array rows dynamically based on an alias list exactly as Python did 
    private static function pickValue(array $row, array $aliases) {
        $normalizedRow = [];
        $compactRow = [];
        foreach ($row as $k => $v) {
            $normalizedKey = self::slugify($k);
            $normalizedRow[$normalizedKey] = $v;
            $compactRow[str_replace('_', '', $normalizedKey)] = $v;
        }
        foreach ($aliases as $alias) {
            $aliasKey = self::slugify($alias);
            if (isset($normalizedRow[$aliasKey]) && trim((string) $normalizedRow[$aliasKey]) !== '') {
                return $normalizedRow[$aliasKey];
            }

            $compactAliasKey = str_replace('_', '', $aliasKey);
            if (isset($compactRow[$compactAliasKey]) && trim((string) $compactRow[$compactAliasKey]) !== '') {
                return $compactRow[$compactAliasKey];
            }

            $bestDistance = null;
            $bestValue = null;
            $distanceLimit = strlen($compactAliasKey) >= 10 ? 2 : 1;

            foreach ($compactRow as $compactRowKey => $compactValue) {
                if (trim((string) $compactValue) === '') {
                    continue;
                }

                $distance = levenshtein($compactAliasKey, $compactRowKey);
                if ($distance <= $distanceLimit && ($bestDistance === null || $distance < $bestDistance)) {
                    $bestDistance = $distance;
                    $bestValue = $compactValue;
                }
            }

            if ($bestValue !== null) {
                return $bestValue;
            }
        }
        return null;
    }

    public static function parseDecimal($value): ?float {
        if ($value === null || $value === '') return null;
        if (is_numeric($value)) return (float)$value;
        $str = str_replace(['R$', ' '], '', $value);
        if (strpos($str, ',') !== false && strpos($str, '.') !== false) {
            $str = str_replace(['.', ','], ['', '.'], $str);
        } else {
            $str = str_replace(',', '.', $str);
        }
        return is_numeric($str) ? (float)$str : null;
    }

    public static function parseDate($value): ?string {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        if (is_numeric($value)) {
            try {
                return \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float) $value)
                    ->format('Y-m-d');
            } catch (\Exception $e) {
                return null;
            }
        }

        $timestamp = strtotime(str_replace('/', '-', (string) $value));
        if ($timestamp === false) {
            return null;
        }

        return date('Y-m-d', $timestamp);
    }

    public static function readExcelAsRecords(string $filepath): array {
        $spreadsheet = IOFactory::load($filepath);
        $worksheet = $spreadsheet->getActiveSheet();
        
        $highestRow = $worksheet->getHighestRow();
        $highestColumn = $worksheet->getHighestColumn();
        $highestColumnIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestColumn);

        $rows = [];
        for ($row = 1; $row <= $highestRow; $row++) {
            $line = [];
            $hasValue = false;

            for ($col = 1; $col <= $highestColumnIndex; $col++) {
                $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col);
                $cellVal = $worksheet->getCell($colLetter . $row)->getFormattedValue();
                $line[] = $cellVal;
                if ($cellVal !== null && trim((string) $cellVal) !== '') {
                    $hasValue = true;
                }
            }

            if ($hasValue) {
                $rows[] = $line;
            }
        }

        if (empty($rows)) {
            return [];
        }

        $headerRowIndex = self::detectHeaderRowIndex($rows);
        $headers = self::deduplicateHeaders($rows[$headerRowIndex]);

        $records = [];
        for ($row = $headerRowIndex + 1; $row < count($rows); $row++) {
            $record = [];
            $isEmpty = true;

            foreach ($headers as $index => $header) {
                $value = $rows[$row][$index] ?? null;
                $record[$header] = self::sanitizeCell($value);
                if ($record[$header] !== null) {
                    $isEmpty = false;
                }
            }

            if (!$isEmpty) {
                $records[] = $record;
            }
        }

        return $records;
    }

    public static function processCustomerBatch(int $companyId, int $batchId, array $records): array {
        $accepted = 0;
        $invalid = 0;

        foreach ($records as $index => $row) {
            $fullName = self::sanitizeCell(self::pickValue($row, [
                "nome", "nome_cliente", "nome cliente", "nome do cliente", "cliente",
                "razao_social", "razao social", "full_name"
            ]));
            $externalCode = self::sanitizeCell(self::pickValue($row, [
                "codigo", "código", "codigo_cliente", "cod_cliente", "id_cliente", "external_code"
            ]));
            $docNumber = self::sanitizeDocument(self::pickValue($row, [
                "cpf", "cnpj", "cpf_cnpj", "cnpj_cpf", "cpf/cnpj", "cnpj/cpf", "documento", "document_number"
            ]));
            $email = self::sanitizeEmail(self::pickValue($row, [
                "email_para_cobranca", "email para cobranca", "email para cobrança",
                "email_cobranca", "email de cobranca", "email de cobrança",
                "email_do_faturamento", "email do faturamento",
                "email_do_financeiro", "email do financeiro",
                "email", "e_mail", "email_billing"
            ]));
            $emailFinancial = self::sanitizeEmail(self::pickValue($row, [
                "email_financeiro", "email do financeiro", "email_do_financeiro",
                "email do faturamento", "email_do_faturamento", "email_financial"
            ]));
            $phone = self::sanitizeCell(self::pickValue($row, ["telefone", "celular", "phone"]));
            $otherContacts = self::sanitizeCell(self::pickValue($row, [
                "outros_contatos", "other_contacts", "contatos", "email_do_comprador", "email do comprador"
            ]));

            $errors = [];
            if (!$fullName) {
                $errors[] = 'Nome do cliente nao encontrado.';
            }

            if ($errors) {
                $invalid++;
            } else {
                $accepted++;
            }

            StagingCustomer::create([
                'company_id' => $companyId,
                'upload_batch_id' => $batchId,
                'row_number' => $index + 2,
                'external_code' => $externalCode,
                'full_name' => strval($fullName ?: ''),
                'normalized_name' => self::normalizeName(strval($fullName ?: '')),
                'document_number' => $docNumber,
                'email_billing' => $email,
                'email_financial' => $emailFinancial,
                'phone' => $phone,
                'other_contacts' => $otherContacts,
                'raw_payload' => json_encode($row),
                'validation_status' => $errors ? 'invalid' : 'valid',
                'validation_errors' => $errors ? json_encode($errors) : null
            ]);
        }

        return [
            'total' => count($records),
            'accepted' => $accepted,
            'invalid' => $invalid,
        ];
    }

    public static function processReceivableBatch(int $companyId, int $batchId, array $records): array {
        $accepted = 0;
        $invalid = 0;

        foreach ($records as $index => $row) {
            $customerExternalCode = self::sanitizeCell(self::pickValue($row, [
                "codigo_cliente", "cod_cliente", "id_cliente", "codigo", "código", "customer_external_code"
            ]));
            $customerName = self::sanitizeCell(self::pickValue($row, [
                "nome", "nome_cliente", "nome cliente", "nome do cliente", "cliente",
                "sacado", "razao_social", "razao social", "customer_name"
            ]));
            $customerDoc = self::sanitizeDocument(self::pickValue($row, [
                "cpf", "cnpj", "cpf_cnpj", "cnpj_cpf", "cpf/cnpj", "cnpj/cpf",
                "documento_cliente", "customer_document_number"
            ]));
            $recNumber = self::sanitizeCell(self::pickValue($row, [
                "numero_titulo", "numero titulo", "titulo", "título", "documento", "receivable_number", "document"
            ]));
            $nossoNumero = self::sanitizeCell(self::pickValue($row, [
                "nosso_numero", "nosso numero", "nosso_num", "nosso_numero_banco"
            ]));
            $chargeType = self::sanitizeCell(self::pickValue($row, [
                "tipo_cobranca", "tipo", "carteira", "charge_type"
            ]));
            $issueDate = self::parseDate(self::pickValue($row, [
                "data_emissao", "emissao", "emissão", "issue_date"
            ]));
            $amountTotal = self::parseDecimal(self::pickValue($row, [
                "valor", "valor_total", "valor original", "amount_total"
            ]));
            $balanceAmount = self::parseDecimal(self::pickValue($row, [
                "saldo", "saldo_atual", "balance_amount"
            ]));
            $balanceWithoutInterest = self::parseDecimal(self::pickValue($row, [
                "saldo_sem_juros", "saldo sem juros", "saldo sem juros multa",
                "saldo sem juros/multa", "balance_without_interest"
            ]));
            $statusRaw = self::sanitizeCell(self::pickValue($row, [
                "status", "situacao", "situação", "status_raw"
            ]));
            $emailBilling = self::sanitizeEmail(self::pickValue($row, [
                "email_cobranca", "email para cobranca", "email para cobrança", "email", "email_billing"
            ]));
            $dueDateRaw = self::pickValue($row, ["vencimento", "data_vencimento", "due_date"]);
            $parsedDate = self::parseDate($dueDateRaw);

            $errors = [];
            if (!$customerName && !$customerExternalCode && !$customerDoc) {
                $errors[] = 'Identificacao do cliente da cobranca nao encontrada.';
            }
            if (!$parsedDate) {
                $errors[] = 'Data de vencimento invalida ou ausente.';
            }
            if ($amountTotal === null) {
                $errors[] = 'Valor total invalido ou ausente.';
            }

            if ($errors) {
                $invalid++;
            } else {
                $accepted++;
            }

            StagingReceivable::create([
                'company_id' => $companyId,
                'upload_batch_id' => $batchId,
                'row_number' => $index + 2,
                'customer_external_code' => $customerExternalCode,
                'customer_name' => strval($customerName ?: ''),
                'normalized_customer_name' => self::normalizeName(strval($customerName ?: '')),
                'customer_document_number' => $customerDoc,
                'receivable_number' => $recNumber,
                'nosso_numero' => $nossoNumero,
                'charge_type' => $chargeType,
                'issue_date' => $issueDate,
                'due_date' => $parsedDate,
                'amount_total' => $amountTotal,
                'balance_amount' => $balanceAmount ?? $amountTotal,
                'balance_without_interest' => $balanceWithoutInterest ?? $amountTotal,
                'status_raw' => $statusRaw,
                'email_billing' => $emailBilling,
                'raw_payload' => json_encode($row),
                'validation_status' => $errors ? 'invalid' : 'valid',
                'validation_errors' => $errors ? json_encode($errors) : null
            ]);
        }

        return [
            'total' => count($records),
            'accepted' => $accepted,
            'invalid' => $invalid,
        ];
    }
}
