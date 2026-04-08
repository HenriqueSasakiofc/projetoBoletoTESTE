<?php
namespace App\Services;

use PhpOffice\PhpSpreadsheet\IOFactory;
use App\Models\StagingCustomer;
use App\Models\StagingReceivable;
use App\Models\UploadBatch;
use Exception;

class ImporterService {
    
    // Normalizes text by removing accents, spaces and making it lowercase
    public static function slugify(?string $value): string {
        if (!$value) return '';
        $value = mb_strtolower(trim($value), 'UTF-8');
        
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

    public static function normalizeName(?string $name): string {
        if (!$name) return '';
        $name = mb_strtolower(trim($name), 'UTF-8');
        
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
        foreach ($row as $k => $v) {
            $normalizedRow[self::slugify($k)] = $v;
        }
        foreach ($aliases as $alias) {
            $aliasKey = self::slugify($alias);
            if (isset($normalizedRow[$aliasKey]) && trim($normalizedRow[$aliasKey]) !== '') {
                return $normalizedRow[$aliasKey];
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

    public static function readExcelAsRecords(string $filepath): array {
        $spreadsheet = IOFactory::load($filepath);
        $worksheet = $spreadsheet->getActiveSheet();
        
        $highestRow = $worksheet->getHighestRow();
        $highestColumn = $worksheet->getHighestColumn();
        $highestColumnIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestColumn);

        // Fetch headers (First row)
        $headers = [];
        for ($col = 1; $col <= $highestColumnIndex; $col++) {
            $headerVal = $worksheet->getCell([\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col), 1])->getFormattedValue();
            $headers[$col] = $headerVal ?: 'coluna_' . $col;
        }

        $records = [];
        for ($row = 2; $row <= $highestRow; $row++) {
            $record = [];
            $isEmpty = true;
            for ($col = 1; $col <= $highestColumnIndex; $col++) {
                $cellVal = $worksheet->getCell([\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col), $row])->getFormattedValue();
                $record[$headers[$col]] = $cellVal;
                if ($cellVal) $isEmpty = false;
            }
            if (!$isEmpty) {
                $records[] = $record;
            }
        }
        return $records;
    }

    public static function processCustomerBatch(int $companyId, int $batchId, array $records) {
        foreach ($records as $index => $row) {
            $fullName = self::pickValue($row, ["nome", "nome_cliente", "cliente", "razao_social", "full_name"]);
            $docNumber = self::pickValue($row, ["cpf", "cnpj", "cpf_cnpj", "documento"]);
            $email = self::pickValue($row, ["email_cobranca", "email", "email_billing", "e_mail", "e_mail_para_cobranca", "e_mail_do_faturamento"]);
            $phone = self::pickValue($row, ["telefone", "celular", "phone"]);

            if (!$fullName) continue;

            StagingCustomer::create([
                'company_id' => $companyId,
                'upload_batch_id' => $batchId,
                'row_number' => $index + 2,
                'full_name' => strval($fullName),
                'normalized_name' => self::normalizeName(strval($fullName)), 
                'document_number' => preg_replace('/\D/', '', $docNumber ?? ''), 
                'email_billing' => filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null,
                'phone' => strval($phone) ?: null,
                'raw_payload' => json_encode($row),
                'validation_status' => 'valid'
            ]);
        }
    }

    public static function processReceivableBatch(int $companyId, int $batchId, array $records) {
        foreach ($records as $index => $row) {
            $customerName = self::pickValue($row, ["nome", "sacado", "cliente"]);
            $customerDoc = self::pickValue($row, ["cpf", "documento_cliente"]);
            $recNumber = self::pickValue($row, ["numero_titulo", "titulo", "receivable_number", "document"]);
            $nossoNumero = self::pickValue($row, ["nosso_numero", "nosso_numero_banco"]);
            $amountTotal = self::pickValue($row, ["valor", "valor_total", "amount_total"]);
            $dueDateRaw = self::pickValue($row, ["vencimento", "due_date"]);

            if (!$customerName || !$amountTotal) continue;

            $amount = self::parseDecimal($amountTotal);
            $parsedDate = date('Y-m-d', strtotime(str_replace('/', '-', $dueDateRaw ?? '')));

            StagingReceivable::create([
                'company_id' => $companyId,
                'upload_batch_id' => $batchId,
                'row_number' => $index + 2,
                'customer_name' => strval($customerName),
                'normalized_customer_name' => self::normalizeName(strval($customerName)),
                'customer_document_number' => preg_replace('/\D/', '', $customerDoc ?? ''),
                'receivable_number' => strval($recNumber),
                'nosso_numero' => strval($nossoNumero ?: null),
                'due_date' => $parsedDate,
                'amount_total' => $amount,
                'raw_payload' => json_encode($row),
                'validation_status' => 'valid'
            ]);
        }
    }
}
