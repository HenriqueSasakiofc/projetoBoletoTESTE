<?php
namespace App\Controllers;

use App\Models\Customer;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class ClientsController {

    private function getCompanyId(): ?int {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if ($authHeader && str_starts_with($authHeader, 'Bearer ')) {
            $token = substr($authHeader, 7);
            try {
                $secretKey = $_ENV['SECRET_KEY'] ?? 'fallback_secret_key_change_me';
                $payload = JWT::decode($token, new Key($secretKey, 'HS256'));
                return (int) $payload->company_id;
            } catch (\Exception $e) {
                // token invalid — fall through to fallback
            }
        }
        // Dev fallback: use first user's company
        $firstUser = \App\Models\User::first();
        return $firstUser ? (int) $firstUser->company_id : null;
    }

    public function index() {
        header('Content-Type: application/json');

        $companyId = $this->getCompanyId();
        if (!$companyId) {
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized']);
            return;
        }

        $page     = max(1, (int)($_GET['page']      ?? 1));
        $pageSize = max(1, (int)($_GET['page_size'] ?? 10));
        $search   = trim($_GET['search'] ?? '');

        $query = Customer::where('company_id', $companyId);

        if ($search) {
            $query->where(function($q) use ($search) {
                $q->where('full_name',       'like', "%$search%")
                  ->orWhere('email_billing', 'like', "%$search%")
                  ->orWhere('document_number','like', "%$search%");
            });
        }

        $total = $query->count();
        $items = $query->orderBy('full_name')
                       ->offset(($page - 1) * $pageSize)
                       ->limit($pageSize)
                       ->get();

        echo json_encode([
            'total'     => $total,
            'page'      => $page,
            'page_size' => $pageSize,
            'items'     => $items,
        ]);
    }

    public function show($id) {
        header('Content-Type: application/json');

        $companyId = $this->getCompanyId();
        if (!$companyId) {
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized']);
            return;
        }

        $customer = Customer::where('company_id', $companyId)
                            ->where('id', $id)
                            ->with('receivables')
                            ->first();

        if (!$customer) {
            http_response_code(404);
            echo json_encode(['error' => 'Cliente não encontrado.']);
            return;
        }

        echo json_encode($customer);
    }
}
