<?php

declare(strict_types=1);

namespace Iter\Mail\Controllers;

use Iter\Admin\Auth;
use Iter\Admin\Breadcrumb;
use Iter\Core\Config;
use Iter\Core\Messages;
use Iter\Core\View\Layout;
use Iter\Mail\Model\MailException;
use Iter\Mail\Model\Template;

/** Templates registered by the modules; the admin overrides subject and body */
class Mailtemplate
{
    public const ADMIN_RESOURCE = 'Iter_Mail::template';

    public function Index(): void
    {
        Breadcrumb::set([
            ['label' => 'Dashboard', 'url' => '/admin/index'],
            ['label' => 'Modelos de e-mail'],
        ]);

        echo Layout::renderAdmin('admin_mailtemplate_index');
    }

    public function Edit(): void
    {
        if (!isset(Template::all()[(string) ($_GET['code'] ?? '')])) {
            Messages::addError('Modelo de e-mail não encontrado.');
            header('Location: /admin/mailtemplate/index');
            exit;
        }
        Breadcrumb::set([
            ['label' => 'Dashboard', 'url' => '/admin/index'],
            ['label' => 'Modelos de e-mail', 'url' => '/admin/mailtemplate/index'],
            ['label' => 'Editar'],
        ]);

        echo Layout::renderAdmin('admin_mailtemplate_form');
    }

    public function Save(): void
    {
        $code = (string) ($_POST['code'] ?? '');
        try {
            Template::save($code, (string) ($_POST['subject'] ?? ''), (string) ($_POST['body_html'] ?? ''), (string) ($_POST['body_text'] ?? ''), (int) Auth::user()->getId());
            Messages::addSuccess('Modelo salvo.');
        } catch (MailException $e) {
            Messages::addError($e->getMessage());
            $_SESSION['iter_old_input'] = array_intersect_key($_POST, array_flip(['subject', 'body_html', 'body_text']));
        }
        header('Location: /admin/mailtemplate/edit?code=' . rawurlencode($code));
        exit;
    }

    public function Restore(): void
    {
        $code = (string) ($_POST['code'] ?? '');
        Template::restore($code);
        Messages::addSuccess('Modelo padrão restaurado.');
        header('Location: /admin/mailtemplate/edit?code=' . rawurlencode($code));
        exit;
    }

    /** The form's content (not saved yet) with the example values, shown in the sandboxed preview frame */
    public function Preview(): void
    {
        try {
            $template = Template::get((string) ($_POST['code'] ?? ''));
            $mail = Template::render([
                'subject' => (string) ($_POST['subject'] ?? ''),
                'body_html' => (string) ($_POST['body_html'] ?? ''),
                'body_text' => (string) ($_POST['body_text'] ?? ''),
            ] + $template, $template['vars'] + ['store_name' => (string) Config::get('mail/from_name')]);
        } catch (MailException $e) {
            http_response_code(404);
            exit;
        }
        // the template's HTML can't run scripts or reach the admin
        header("Content-Security-Policy: sandbox; default-src 'none'; img-src * data:; style-src 'unsafe-inline'");
        header('Content-Type: text/html; charset=utf-8');
        echo $template['code'] === Template::LAYOUT ? $mail['html'] : '<p style="font:14px sans-serif"><strong>Assunto:</strong> ' . htmlspecialchars($mail['subject']) . '</p>' . $mail['html'];
    }
}
