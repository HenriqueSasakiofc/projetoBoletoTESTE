<?php
namespace App\Controllers;

use App\Models\Customer;
use App\Support\Auth;

class ClientsApiController {
    private function getAuthedUser() {
        return Auth::userFromRequest();
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
            ->withCount([
                'receivables as overdue_receivables_total' => function ($q) use ($today) {
                    $q->where('is_active', true)
                        ->whereNotIn('status', ['PAGO', 'BAIXADO', 'CANCELADO'])
                        ->whereNotNull('due_date')
                        ->where('due_date', '<', $today)
                        ->where(function ($balanceQuery) {
                            $balanceQuery->whereNull('balance_amount')
                                ->orWhere('balance_amount', '>', 0);
                        });
                }
            ]);

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
            ->map(function ($customer) {
                return [
                    'id' => (int) $customer->id,
                    'external_code' => $customer->external_code,
                    'full_name' => $customer->full_name,
                    'email_billing' => $customer->email_billing,
                    'phone' => $customer->phone,
                    'document_number' => $customer->document_number,
                    'is_active' => (bool) $customer->is_active,
                    'overdue_receivables_total' => (int) ($customer->overdue_receivables_total ?? 0),
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
            'receivables' => $customer->receivables->map(fn ($receivable) => $this->serializeReceivable($receivable))->values(),
        ]);
    }
}
