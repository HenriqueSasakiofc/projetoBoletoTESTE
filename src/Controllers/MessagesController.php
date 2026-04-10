<?php
namespace App\Controllers;

use App\Models\MessageTemplate;
use App\Models\User;
use App\Models\Customer;

class MessagesController {

    private function getAuthedUser() {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (!$authHeader) return null;
        return User::first(); 
    }

    public function getTemplate() {
        header('Content-Type: application/json');
        $user = $this->getAuthedUser();
        if (!$user) { http_response_code(401); echo json_encode(['message' => 'Unauthorized']); return; }

        $template = MessageTemplate::where('company_id', $user->company_id)->first();
        if (!$template) {
            $template = new MessageTemplate(); // Blank template
        }

        echo json_encode($template);
    }

    public function updateTemplate() {
        header('Content-Type: application/json');
        $user = $this->getAuthedUser();
        if (!$user) { http_response_code(401); echo json_encode(['message' => 'Unauthorized']); return; }

        $input = json_decode(file_get_contents('php://input'), true);
        $subject = $input['subject'] ?? '';
        $body = $input['body'] ?? '';

        $template = MessageTemplate::updateOrCreate(
            ['company_id' => $user->company_id],
            ['subject' => $subject, 'body' => $body]
        );

        echo json_encode($template);
    }

    public function sendManual($id) {
        header('Content-Type: application/json');
        $user = $this->getAuthedUser();
        if (!$user) { http_response_code(401); echo json_encode(['message' => 'Unauthorized']); return; }

        $input = json_decode(file_get_contents('php://input'), true);
        $customer = Customer::find($id);

        if (!$customer) {
            http_response_code(404);
            echo json_encode(['message' => 'Cliente não encontrado.']);
            return;
        }

        // Logic to queue manual message
        // For now, just simulate success
        echo json_encode(['status' => 'success', 'message' => 'Mensagem enviada com sucesso para ' . $customer->full_name]);
    }
}
