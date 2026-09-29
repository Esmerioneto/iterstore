<?php
declare(strict_types=1);

namespace Iter\Traits;

use Iter\Core\ErrorPage;
use Iter\Core\Log;

trait Router{

    /**
     * System routes, shared across all instances for the lifetime of the request
     * @var array
     */
    private static $routes = [];

    /**
     * addRoute
     * @param string $path
     * @param string $controller
     * @param string $action
     * @param string $method
     * @return void
     */
    public function addRoute(string $path, string $controller, string $action, string $method = 'GET'): void
    {
        self::$routes[$path] = [
            'controller' => $controller,
            'action' => $action,
            'method' => $method,
        ];
    }

    /**
     * Get System Routes
     * @return array
     */
    public function getRoutes(): array
    {
        return self::$routes;
    }

    /**
     * execute
     * @return void
     */
    public function execute() {
        $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $method = $_SERVER['REQUEST_METHOD'];

        foreach (self::$routes as $path => $route) {
            if ($uri === $path && in_array($method, explode('|', $route['method']), true)) {
                $controller = $route['controller'];
                $action = $route['action'];

                if (!class_exists($controller) || !method_exists($controller, $action)) {
                    Log::error("Rota {$uri} aponta para {$controller}::{$action}, que não existe");
                    ErrorPage::render(500, 'Ops, algo deu errado', 'Esta página não está disponível no momento.');
                    return;
                }

                // Instantiate the controller and call the action
                try {
                    if (!\Iter\Admin\Auth::authorize($controller, $uri)) {
                        return;
                    }

                    $controllerInstance = new $controller();
                    $controllerInstance->$action();
                } catch (\Throwable $e) {
                    $this->renderError($e);
                }
                return;
            }
        }

        // No matching route found
        ErrorPage::render(404, 'Página não encontrada', 'O endereço acessado não existe ou foi removido.', ['Início' => '/']);
    }

    /**
     * Renders a user-friendly error page instead of letting a fatal
     * error/stack trace reach the browser.
     */
    private function renderError(\Throwable $e): void
    {
        Log::error(get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString());

        $isDbError = $e instanceof \RuntimeException
            && str_contains($e->getMessage(), 'banco de dados');

        $message = $isDbError
            ? 'Não foi possível conectar ao banco de dados, entre em contato com a infra para saber o que ocorreu'
            : 'Ocorreu um erro inesperado. Tente novamente em instantes.';

        ErrorPage::render(503, 'Ops, algo deu errado', $message);
    }
}

