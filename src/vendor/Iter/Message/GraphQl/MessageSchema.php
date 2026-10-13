<?php

declare(strict_types=1);

namespace Iter\Message\GraphQl;

use GraphQL\Error\Error;
use GraphQL\Type\Definition\EnumType;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use Iter\Admin\Auth;
use Iter\Core\GraphQl\Schema;
use Iter\Customer\Model\CustomerAuth;
use Iter\Message\Model\MessageService;

/**
 * Messages between the store and the customer (MESSAGE-PLAN §4). The customer only ever
 * reaches the token's own conversation; the admin's fields need Iter_Message::manage.
 */
class MessageSchema
{
    /** @return array{query: array, mutation: array} */
    public static function fields(): array
    {
        $service = new MessageService();
        $customerId = static fn (): int => (int) (CustomerAuth::customer() ?? throw new Error('Faça login como cliente.', extensions: ['code' => 'UNAUTHENTICATED']))->getId();
        $senderType = new EnumType(['name' => 'MessageSender', 'values' => ['CUSTOMER' => ['value' => 'customer'], 'ADMIN' => ['value' => 'admin']]]);
        $messageType = new ObjectType([
            'name' => 'CustomerMessage',
            'fields' => [
                'id' => ['type' => Type::nonNull(Type::id()), 'resolve' => static fn (array $m) => $m['message_id']],
                'sender' => Type::nonNull($senderType),
                'body' => ['type' => Type::nonNull(Type::string()), 'description' => 'Texto puro: escape ao mostrar.'],
                'createdAt' => ['type' => Type::nonNull(Type::string()), 'resolve' => static fn (array $m) => $m['created_at']],
                'readAt' => ['type' => Type::string(), 'description' => 'Quando o outro lado leu; null = não lida.', 'resolve' => static fn (array $m) => $m['read_at']],
                'adminName' => ['type' => Type::string(), 'description' => 'Quem da loja escreveu (só nas da loja).', 'resolve' => static fn (array $m) => $m['admin_name']],
            ],
        ]);
        $threadArgs = [
            'limit' => ['type' => Type::int(), 'defaultValue' => 100, 'description' => 'Máximo 100.'],
            'beforeId' => ['type' => Type::id(), 'description' => 'Só as anteriores a esta mensagem (carregar mais).'],
        ];
        $thread = static fn (int $customer, array $args): array => $service->thread($customer, (int) $args['limit'], isset($args['beforeId']) ? (int) $args['beforeId'] : null);

        return [
            'query' => [
                'customerMessages' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($messageType))),
                    'description' => 'A conversa do cliente do token com a loja, a mais antiga primeiro. Marca as da loja como lidas.',
                    'args' => $threadArgs,
                    'resolve' => static function ($root, array $args) use ($service, $customerId, $thread): array {
                        $id = $customerId();
                        $service->markRead($id, 'customer');
                        return $thread($id, $args);
                    },
                ],
                'customerUnreadMessages' => [
                    'type' => Type::nonNull(Type::int()),
                    'description' => 'Mensagens da loja que o cliente do token ainda não leu.',
                    'resolve' => static fn () => $service->unread($customerId()),
                ],
                'adminMessageInbox' => [
                    'type' => Type::nonNull(new ObjectType([
                        'name' => 'MessageInbox',
                        'fields' => [
                            'items' => Type::nonNull(Type::listOf(Type::nonNull(new ObjectType([
                                'name' => 'MessageConversation',
                                'fields' => [
                                    'customerId' => ['type' => Type::nonNull(Type::id()), 'resolve' => static fn (array $r) => $r['customer_id']],
                                    'customerName' => ['type' => Type::nonNull(Type::string()), 'resolve' => static fn (array $r) => trim($r['firstname'] . ' ' . $r['lastname'])],
                                    'email' => Type::nonNull(Type::string()),
                                    'websiteId' => ['type' => Type::nonNull(Type::id()), 'resolve' => static fn (array $r) => $r['website_id']],
                                    'websiteName' => ['type' => Type::nonNull(Type::string()), 'resolve' => static fn (array $r) => $r['website_name']],
                                    'lastMessage' => ['type' => Type::nonNull(Type::string()), 'resolve' => static fn (array $r) => $r['last_body']],
                                    'lastSender' => ['type' => Type::nonNull($senderType), 'resolve' => static fn (array $r) => $r['last_sender']],
                                    'lastAt' => ['type' => Type::nonNull(Type::string()), 'resolve' => static fn (array $r) => $r['last_at']],
                                    'unread' => ['type' => Type::nonNull(Type::int()), 'description' => 'Do cliente, não lidas pela loja.'],
                                ],
                            ])))),
                            'total' => Type::nonNull(Type::int()),
                        ],
                    ])),
                    'description' => 'Um cliente por linha, a conversa mais recente primeiro; ' . MessageService::PAGE_SIZE . ' por página.',
                    'args' => [
                        'unreadOnly' => ['type' => Type::boolean(), 'defaultValue' => false],
                        'search' => ['type' => Type::string(), 'description' => 'Parte do nome ou do e-mail.'],
                        'websiteId' => Type::id(),
                        'page' => ['type' => Type::int(), 'defaultValue' => 1],
                    ],
                    'resolve' => Schema::guard('Iter_Message::manage', static fn ($root, array $args): array => $service->inbox([
                        'unread_only' => (bool) $args['unreadOnly'],
                        'q' => (string) ($args['search'] ?? ''),
                        'website_id' => isset($args['websiteId']) ? (int) $args['websiteId'] : null,
                    ], (int) $args['page'])),
                ],
                'adminCustomerMessages' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($messageType))),
                    'description' => 'A conversa de um cliente, a mais antiga primeiro. Marca as do cliente como lidas.',
                    'args' => ['customerId' => Type::nonNull(Type::id())] + $threadArgs,
                    'resolve' => Schema::guard('Iter_Message::manage', static function ($root, array $args) use ($service, $thread): array {
                        $service->markRead((int) $args['customerId'], 'admin');
                        return $thread((int) $args['customerId'], $args);
                    }),
                ],
            ],
            'mutation' => [
                'sendCustomerMessage' => [
                    'type' => Type::nonNull($messageType),
                    'description' => 'O cliente do token escreve para a loja (1 a ' . MessageService::MAX_LENGTH . ' caracteres). Erros: MESSAGE_EMPTY, MESSAGE_TOO_LONG.',
                    'args' => ['body' => Type::nonNull(Type::string())],
                    'resolve' => static fn ($root, array $args): array => $service->send($customerId(), 'customer', $args['body']),
                ],
                'adminSendCustomerMessage' => [
                    'type' => Type::nonNull($messageType),
                    'description' => 'A loja escreve para o cliente, mesmo sem conversa anterior. Erros: MESSAGE_EMPTY, MESSAGE_TOO_LONG, CUSTOMER_NOT_FOUND.',
                    'args' => ['customerId' => Type::nonNull(Type::id()), 'body' => Type::nonNull(Type::string())],
                    'resolve' => Schema::guard('Iter_Message::manage', static fn ($root, array $args): array => $service->send((int) $args['customerId'], 'admin', $args['body'], (int) Auth::user()->getId())),
                ],
            ],
        ];
    }
}
