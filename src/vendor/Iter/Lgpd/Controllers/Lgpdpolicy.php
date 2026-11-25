<?php

declare(strict_types=1);

namespace Iter\Lgpd\Controllers;

use Iter\Admin\Auth;
use Iter\Admin\Breadcrumb;
use Iter\Core\Config;
use Iter\Core\Messages;
use Iter\Core\View\Layout;
use Iter\Lgpd\Model\LgpdException;
use Iter\Lgpd\Model\PolicyService;
use Iter\Log\Model\OperationLog;

/** LGPD → Política de privacidade: the draft per website, publishing a new version, reading the old ones */
class Lgpdpolicy
{
    public const ADMIN_RESOURCE = 'Iter_Lgpd::policy';

    public function Index(): void
    {
        Breadcrumb::set([['label' => 'Dashboard', 'url' => '/admin/index'], ['label' => 'LGPD'], ['label' => 'Política de privacidade']]);

        echo Layout::renderAdmin('admin_lgpdpolicy_index');
    }

    public function View(): void
    {
        try {
            $policy = (new PolicyService())->get((int) ($_GET['id'] ?? 0));
        } catch (LgpdException $e) {
            Messages::addError($e->getMessage());
            self::go('/admin/lgpdpolicy/index');
        }
        Breadcrumb::set([['label' => 'Dashboard', 'url' => '/admin/index'], ['label' => 'Política de privacidade', 'url' => '/admin/lgpdpolicy/index?website_id=' . (int) $policy['website_id']],
            ['label' => 'Versão ' . (int) $policy['version']]]);

        echo Layout::renderAdmin('admin_lgpdpolicy_view');
    }

    /** "Salvar rascunho" or "Publicar nova versão" (action=publish) */
    public function Save(): void
    {
        $websiteId = (int) ($_POST['website_id'] ?? 0);
        $content = (string) ($_POST['content'] ?? '');
        $back = '/admin/lgpdpolicy/index?website_id=' . $websiteId;
        if (!Config::websiteExists($websiteId)) {
            Messages::addError('Website não encontrado.');
            self::go('/admin/lgpdpolicy/index');
        }
        $service = new PolicyService();
        $adminId = (int) Auth::user()->getId();
        try {
            if (($_POST['action'] ?? '') === 'publish') {
                $version = $service->publish($websiteId, $content, $adminId);
                OperationLog::record('lgpd_policy', null, null, 'Política de privacidade', [], ['Website' => (string) $websiteId, 'Versão' => (string) $version]);
                Messages::addSuccess("Versão {$version} publicada. O app e a loja web pedem o aceite de novo aos clientes que entrarem.");
            } else {
                $service->saveDraft($websiteId, $content, $adminId);
                Messages::addSuccess('Rascunho salvo. Os clientes continuam vendo a versão publicada até você publicar.');
            }
        } catch (LgpdException $e) {
            Messages::addError($e->getMessage());
            // the text typed isn't lost: kept as the draft
            $e->errorCode !== 'POLICY_TOO_LONG' && $service->saveDraft($websiteId, $content, $adminId);
        }
        self::go($back);
    }

    private static function go(string $url): never
    {
        header('Location: ' . $url);
        exit;
    }
}
