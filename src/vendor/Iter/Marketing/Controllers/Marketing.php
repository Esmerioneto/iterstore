<?php

declare(strict_types=1);

namespace Iter\Marketing\Controllers;

use Iter\Admin\Auth;
use Iter\Admin\Breadcrumb;
use Iter\Core\Messages;
use Iter\Core\View\Layout;
use Iter\Log\Model\OperationLog;
use Iter\Marketing\Model\MarketingService;

/** Marketing → Sugestões: the suggestions waiting for approval; send or discard, one or many */
class Marketing
{
    public const ADMIN_RESOURCE = 'Iter_Marketing::review';

    public function Index(): void
    {
        Breadcrumb::set([['label' => 'Dashboard', 'url' => '/admin/index'], ['label' => 'Marketing'], ['label' => 'Sugestões']]);

        echo Layout::renderAdmin('admin_marketing_index');
    }

    public function View(): void
    {
        if (!(new MarketingService())->find((int) ($_GET['id'] ?? 0))) {
            Messages::addError('Sugestão não encontrada.');
            header('Location: /admin/marketing/index');
            exit;
        }
        Breadcrumb::set([['label' => 'Dashboard', 'url' => '/admin/index'], ['label' => 'Sugestões', 'url' => '/admin/marketing/index'], ['label' => 'Sugestão']]);

        echo Layout::renderAdmin('admin_marketing_view');
    }

    public function Decide(): void
    {
        $ids = array_map('intval', (array) ($_POST['ids'] ?? []));
        $action = (string) ($_POST['action'] ?? '');
        $result = (new MarketingService())->decide($ids, $action, (int) Auth::user()->getId());
        if (!$result['changed']) {
            Messages::addError('Nenhuma sugestão aguardando aprovação foi escolhida.');
        } elseif ($action === 'discard') {
            Messages::addSuccess($result['changed'] . ' sugestão(ões) descartada(s).');
        } else {
            Messages::addSuccess(implode(' ', array_filter([
                $result['sent'] ? $result['sent'] . ' e-mail(s) na fila de envio.' : null,
                $result['waiting'] ? $result['waiting'] . ' aprovada(s), saem no horário de envio (Configurações → Marketing).' : null,
                $result['skipped'] ? $result['skipped'] . ' não enviada(s): veja o motivo no Histórico.' : null,
            ])));
        }
        $result['changed'] && OperationLog::record($action === 'send' ? 'marketing_send' : 'marketing_discard', 'marketing', null, 'Sugestões de marketing', [],
            ['Sugestões' => (string) $result['changed']] + ($action === 'send' ? ['Enviadas' => (string) $result['sent'], 'No horário' => (string) $result['waiting'], 'Não enviadas' => (string) $result['skipped']] : []));
        $back = (string) ($_POST['back'] ?? '');
        header('Location: ' . (str_starts_with($back, '/admin/marketing/index') ? $back : '/admin/marketing/index'));
        exit;
    }
}
