<?php

declare(strict_types=1);

namespace Iter\Admin\GraphQl;

use GraphQL\Error\Error;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use Iter\Admin\Auth;
use Iter\Admin\Model\AdminRole;

/**
 * Admin login through the API, for apps outside the admin panel: generateAdminToken
 * gives a token to send as "Authorization: Bearer <token>"; with it, the request
 * has the same permissions (ACL role) as that user in the panel.
 */
class AdminSchema
{
    /** @return array{query: array, mutation: array} */
    public static function fields(): array
    {
        $adminUser = new ObjectType([
            'name' => 'AdminUser',
            'fields' => [
                'id' => ['type' => Type::nonNull(Type::id()), 'resolve' => static fn ($u) => $u->getId()],
                'username' => ['type' => Type::nonNull(Type::string()), 'resolve' => static fn ($u) => $u->getData('username')],
                'firstname' => ['type' => Type::string(), 'resolve' => static fn ($u) => $u->getData('firstname')],
                'lastname' => ['type' => Type::string(), 'resolve' => static fn ($u) => $u->getData('lastname')],
                'email' => ['type' => Type::string(), 'resolve' => static fn ($u) => $u->getData('email')],
                'role' => [
                    'type' => Type::string(),
                    'description' => 'Nome do perfil de acesso.',
                    'resolve' => static fn ($u) => $u->getData('role_id') === null ? null : (new AdminRole())->load((int) $u->getData('role_id'))->getData('role_name'),
                ],
            ],
        ]);

        return [
            'query' => [
                'currentAdmin' => [
                    'type' => $adminUser,
                    'description' => 'O usuário do token (ou da sessão do painel); null sem login.',
                    'resolve' => static fn () => Auth::user(),
                ],
            ],
            'mutation' => [
                'generateAdminToken' => [
                    'type' => Type::nonNull(new ObjectType([
                        'name' => 'AdminToken',
                        'fields' => [
                            'token' => ['type' => Type::nonNull(Type::string()), 'description' => 'Envie em "Authorization: Bearer <token>".'],
                            'expires_at' => ['type' => Type::nonNull(Type::string()), 'description' => 'Configurações → Segurança do admin → validade do token.'],
                            'user' => Type::nonNull($adminUser),
                        ],
                    ])),
                    'description' => 'Login do admin pela API. Erra junto com o painel: tentativas contam para o bloqueio.',
                    'args' => ['username' => Type::nonNull(Type::string()), 'password' => Type::nonNull(Type::string())],
                    'resolve' => static function ($root, array $args): array {
                        $user = Auth::checkCredentials($args['username'], $args['password']);
                        if ($user === null) { // one message for wrong, inactive or locked, as in the login form
                            throw new Error('Usuário ou senha inválidos, ou conta bloqueada temporariamente.', extensions: ['code' => 'INVALID_CREDENTIALS']);
                        }
                        if ($user->isPasswordExpired()) {
                            throw new Error('Sua senha expirou. Defina uma nova senha no painel para continuar.', extensions: ['code' => 'PASSWORD_EXPIRED']);
                        }

                        return Auth::issueToken($user) + ['user' => $user];
                    },
                ],
                'revokeAdminToken' => [
                    'type' => Type::nonNull(Type::boolean()),
                    'description' => 'Logout: o token enviado deixa de valer. false sem token.',
                    'resolve' => static fn () => Auth::revokeToken(),
                ],
            ],
        ];
    }
}
