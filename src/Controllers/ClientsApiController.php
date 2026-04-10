<?php
namespace App\Controllers;

use App\Models\Customer;
use App\Support\Auth;

class ClientsApiController {
    private function getAuthedUser() {
        return Auth::userFromRequest();
    }

    private function formatMoney($value): string {
        return number_format((float) $value, 2, ',', '.');
    }

    private function receivableIdentityKey($receivable): string {
        if (!empty($receivable->receivable_number)) {
            return 'receivable:' . $receivable->receivable_number;
        }

        if (!empty($receivable->nosso_numero)) {
            return 'nosso:' . $receivable->nosso_numero;
        }

        return implode('|', [
            'fallback',
            $receivable->customer_id ?? '',
            $receivable->due_date ?: '',
            $receivable->snapshot_customer_name ?: '',
        ]);
    }

    private function uniqueDebtReceivables($receivables) {
        return $receivables
            ->sortByDesc(function ($receivable) {
                return strtotime((string) ($receivable->updated_at ?? $receivable->created_at ?? '1970-01-01 00:00:00'));
            })
            ->unique(fn ($receivable) => $this->receivableIdentityKey($receivable))
            ->values();
    }

    private function maskEmail(?string $value): ?string {
        if (!$value || strpos($value, '@') === false) {
            return $value;
        }

        [$local, $domain] = explode('@', $value, 2);
        $prefix = substr($local, 0, 2);

        return $prefix . str_repeat('*', max(1, strlen($local) - 2)) . '@' . $domain;
    }

    private function maskDocument(?string $value): ?string {
        if (!$value) {
            return $value;
        }

        $digits = preg_replace('/\D+/', '', $value);
        if (!$digits) {
            return $value;
        }

        $visible = strlen($digits) > 4 ? substr($digits, -4) : $digits;
        return str_repeat('*', max(0, strlen($digits) - strlen($visible))) . $visible;
    }

    private function maskPhone(?string $value): ?string {
        if (!$value) {
            return $value;
        }

        $digits = preg_replace('/\D+/', '', $value);
        if (!$digits) {
            return $value;
        }

        $visible = strlen($digits) > 4 ? substr($digits, -4) : $digits;
        return str_repeat('*', max(0, strlen($digits) - strlen($visible))) . $visible;
    }

    private function serializeReceivable($receivable): array {
        $recipient = $receivable->snapshot_email_billing ?: ($receivable->customer->email_billing ?? null);

        return [
            'id' => (int) $receivable->id,
            'receivable_number' => $receivable->receivable_number,
            'nosso_numero' => $receivable->nosso_numero,
            'status' => $receivable->status,
            'due_date' => $receivable->due_date,
            'due_date_formatted' => $receivable->due_date ? date('d/m/Y', strtotime($receivable->due_date)) : null,
            'amount_total' => $receivable->amount_total,
            'amount_total_formatted' => number_format((float) $receivable->amount_total, 2, ',', '.'),
            'balance_amount' => $receivable->balance_amount,
            'balance_amount_formatted' => number_format((float) $receivable->balance_amount, 2, ',', '.'),
            'last_standard_message_at' => $receivable->last_standard_message_at,
            'can_queue_standard_message' => !empty($recipient),
        ];
    }

    public function index() {
        header('Content-Type: application/json');

        $user = $this->getAuthedUser();
        if (!$user) {
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized']);
            return;
        }

        $page = max(1, (int) ($_GET['page'] ?? 1));
        $pageSize = max(1, (int) ($_GET['page_size'] ?? 10));
        $search = trim($_GET['search'] ?? '');
        $today = date('Y-m-d');

        $query = Customer::where('company_id', $user->company_id)
            ->with(['receivables' => function ($q) {
                $q->where('is_active', true)
                    ->whereNotIn('status', ['PAGO', 'BAIXADO', 'CANCELADO'])
                    ->where(function ($balanceQuery) {
                        $balanceQuery->whereNull('balance_amount')
                            ->orWhere('balance_amount', '>', 0);
                    });
            }]);

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('full_name', 'like', "%$search%")
                    ->orWhere('email_billing', 'like', "%$search%")
                    ->orWhere('document_number', 'like', "%$search%");
            });
        }

        $total = $query->count();
        $items = $query->orderBy('full_name')
            ->offset(($page - 1) * $pageSize)
            ->limit($pageSize)
            ->get()
            ->map(function ($customer) use ($today) {
                $uniqueReceivables = $this->uniqueDebtReceivables($customer->receivables);
                $debtAmountTotal = $uniqueReceivables->sum(function ($receivable) {
                    return (float) ($receivable->balance_amount ?? $receivable->amount_total ?? 0);
                });
                $overdueReceivablesTotal = $uniqueReceivables->filter(function ($receivable) use ($today) {
                    return !empty($receivable->due_date) && $receivable->due_date < $today;
                })->count();

                return [
                    'id' => (int) $customer->id,
                    'external_code' => $customer->external_code,
                    'full_name' => $customer->full_name,
                    'email_billing' => $customer->email_billing,
                    'phone' => $customer->phone,
                    'document_number' => $customer->document_number,
                    'is_active' => (bool) $customer->is_active,
                    'debt_amount_total' => round($debtAmountTotal, 2),
                    'debt_amount_total_formatted' => $this->formatMoney($debtAmountTotal),
                    'overdue_receivables_total' => $overdueReceivablesTotal,
                ];
            })
            ->values();

        echo json_encode([
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize,
            'items' => $items,
        ]);
    }

    public function show($id) {
        header('Content-Type: application/json');

        $user = $this->getAuthedUser();
        if (!$user) {
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized']);
            return;
        }

        $customer = Customer::where('company_id', $user->company_id)
            ->where('id', $id)
            ->with(['receivables' => function ($query) {
                $query->where('is_active', true)
                    ->with('customer')
                    ->orderBy('due_date', 'asc')
                    ->orderBy('id', 'asc');
            }])
            ->first();

        if (!$customer) {
            http_response_code(404);
            echo json_encode(['error' => 'Cliente nao encontrado.']);
            return;
        }

        $uniqueReceivables = $this->uniqueDebtReceivables($customer->receivables);
        $debtAmountTotal = $uniqueReceivables->sum(function ($receivable) {
            if (in_array($receivable->status, ['PAGO', 'BAIXADO', 'CANCELADO'], true)) {
                return 0;
            }

            return (float) ($receivable->balance_amount ?? $receivable->amount_total ?? 0);
        });

        echo json_encode([
            'id' => (int) $customer->id,
            'external_code' => $customer->external_code,
            'full_name' => $customer->full_name,
            'document_number' => $customer->document_number,
            'document_number_masked' => $this->maskDocument($customer->document_number),
            'email_billing' => $customer->email_billing,
            'email_billing_masked' => $this->maskEmail($customer->email_billing),
            'email_financial' => $customer->email_financial,
            'email_financial_masked' => $this->maskEmail($customer->email_financial),
            'phone' => $customer->phone,
            'phone_masked' => $this->maskPhone($customer->phone),
            'other_contacts' => $customer->other_contacts,
            'is_active' => (bool) $customer->is_active,
            'debt_amount_total' => round($debtAmountTotal, 2),
            'debt_amount_total_formatted' => $this->formatMoney($debtAmountTotal),
            'receivables' => $uniqueReceivables->map(fn ($receivable) => $this->serializeReceivable($receivable))->values(),
        ]);
    }

    public function destroy($id) {
        header('Content-Type: application/json');

        $user = $this->getAuthedUser();
        if (!$user) {
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized']);
            return;
        }

        $customer = Customer::where('company_id', $user->company_id)
            ->where('id', $id)
            ->first();

        if (!$customer) {
            http_response_code(404);
            echo json_encode(['error' => 'Cliente nao encontrado.']);
            return;
        }

        $customerName = $customer->full_name;
        $receivablesCount = $customer->receivables()->count();
        $customer->delete();

        echo json_encode([
            'status' => 'deleted',
            'message' => "Registro do cliente {$customerName} excluido com sucesso.",
            'deleted_receivables' => $receivablesCount,
        ]);
    }
}
