<?php

declare(strict_types=1);

namespace Iter\Lgpd\Controllers;

use Iter\Admin\Auth;
use Iter\Admin\Breadcrumb;
use Iter\Core\Messages;
use Iter\Core\View\Layout;
use Iter\Lgpd\Model\LgpdException;
use Iter\Lgpd\Model\PersonalDataService;
use Iter\Lgpd\Model\RequestService;
use Iter\Log\Model\OperationLog;

/** LGPD → Solicitações: register a data subject's request, give the copy, erase, answer or refuse */
class Lgpdrequest
{
    public const ADMIN_RESOURCE = 'Iter_Lgpd::requests';

    public function Index(): void
    {
        Breadcrumb::set([['label' => 'Dashboard', 'url' => '/admin/index'], ['label' => 'LGPD'], ['label' => 'Solicitações']]);

        echo Layout::renderAdmin('admin_lgpdrequest_index');
    }

    public function New(): void
    {
        Breadcrumb::set([['label' => 'Dashboard', 'url' => '/admin/index'], ['label' => 'Solicitações', 'url' => '/admin/lgpdrequest/index'], ['label' => 'Nova solicitação']]);

        echo Layout::renderAdmin('admin_lgpdrequest_new');
    }

    public function Save(): void
    {
        try {
            $id = (new RequestService())->create([
                'website_id' => (int) ($_POST['website_id'] ?? 0),
                'email' => (string) ($_POST['email'] ?? ''),
                'type' => (string) ($_POST['type'] ?? ''),
                'origin' => 'admin',
                'note' => (string) ($_POST['note'] ?? ''),
                'admin_user_id' => (int) Auth::user()->getId(),
            ]);
        } catch (LgpdException $e) {
            Messages::addError($e->getMessage());
            $_SESSION['iter_old_input'] = array_intersect_key($_POST, array_flip(['website_id', 'email', 'type', 'note']));
            self::go('/admin/lgpdrequest/new');
        }
        Messages::addSuccess('Solicitação ' . RequestService::protocol($id) . ' registrada. O prazo para responder é de ' . RequestService::DUE_DAYS . ' dias.');
        self::go('/admin/lgpdrequest/view?id=' . $id);
    }

    public function View(): void
    {
        $id = self::requestId();
        Breadcrumb::set([['label' => 'Dashboard', 'url' => '/admin/index'], ['label' => 'Solicitações', 'url' => '/admin/lgpdrequest/index'], ['label' => RequestService::protocol($id)]]);

        echo Layout::renderAdmin('admin_lgpdrequest_view');
    }

    /** the copy of the subject's data, as a JSON file; each download goes to the operation log */
    public function Download(): void
    {
        $request = (new RequestService())->get(self::requestId());
        $customerId = $request['customer_id'] !== null ? (int) $request['customer_id'] : null;
        $data = (new PersonalDataService())->export($customerId, (string) $request['email']);
        OperationLog::record('lgpd_export', $customerId !== null ? 'customer' : null, $customerId, RequestService::protocol((int) $request['request_id']), [],
            ['Titular' => $customerId !== null ? 'Cliente #' . $customerId : 'Visitante']);
        $json = (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . RequestService::protocol((int) $request['request_id']) . '-dados.json"');
        header('Content-Length: ' . strlen($json));
        header('Cache-Control: private, no-store');
        echo $json;
        exit;
    }

    public function Close(): void
    {
        $id = (int) ($_POST['id'] ?? 0);
        try {
            (new RequestService())->close($id, (string) ($_POST['status'] ?? ''), (string) ($_POST['note'] ?? ''), (int) Auth::user()->getId());
            Messages::addSuccess('Solicitação encerrada.');
        } catch (LgpdException $e) {
            Messages::addError($e->getMessage());
        }
        self::go('/admin/lgpdrequest/view?id=' . $id);
    }

    /** runs the erase of an open "Exclusão" request of a customer; needs its own permission */
    public function Erase(): void
    {
        $id = (int) ($_POST['id'] ?? 0);
        if (!Auth::isAllowed('Iter_Lgpd::erase')) {
            Messages::addError('Seu perfil não pode excluir dados de clientes.');
            self::go('/admin/lgpdrequest/view?id=' . $id);
        }
        try {
            $request = (new RequestService())->get($id);
            if ($request['status'] !== 'open' || $request['type'] !== 'erase' || $request['customer_id'] === null) {
                throw new LgpdException('INVALID_ARGUMENT', 'Só uma solicitação de exclusão aberta, de um cliente cadastrado, pode ser executada.');
            }
            (new PersonalDataService())->erase((int) $request['customer_id'], 'admin', $id, (string) ($_POST['note'] ?? ''));
            Messages::addSuccess('Dados do cliente excluídos. O e-mail de confirmação foi para a fila de envio.');
        } catch (LgpdException $e) {
            Messages::addError($e->getMessage());
        }
        self::go('/admin/lgpdrequest/view?id=' . $id);
    }

    private static function requestId(): int
    {
        $id = (int) ($_GET['id'] ?? 0);
        try {
            (new RequestService())->get($id);
        } catch (LgpdException $e) {
            Messages::addError($e->getMessage());
            self::go('/admin/lgpdrequest/index');
        }

        return $id;
    }

    private static function go(string $url): never
    {
        header('Location: ' . $url);
        exit;
    }
}
