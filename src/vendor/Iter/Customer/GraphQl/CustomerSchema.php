<?php

declare(strict_types=1);

namespace Iter\Customer\GraphQl;

use GraphQL\Error\Error;
use GraphQL\Type\Definition\EnumType;
use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use Iter\Core\Config;
use Iter\Core\GraphQl\Schema;
use Iter\Core\RateLimit;
use Iter\Customer\Model\AddressService;
use Iter\Customer\Model\Customer;
use Iter\Customer\Model\CustomerAuth;
use Iter\Customer\Model\CustomerService;
use Iter\Eav\Form\FormSchemaGenerator;
use Iter\Eav\Model\Attribute;
use Iter\Eav\Model\AttributeException;
use Iter\Store\Model\Website;

/**
 * Customer fields for Iter\Core\GraphQl\Schema. Personal data: reads and writes
 * need an admin with the Clientes resource. The storefront signs up and logs in with
 * registerCustomer / generateCustomerToken, reads its own account with `me` and edits it with
 * updateMe, changeMyPassword and the *MyAddress mutations ("Minha conta").
 * EAV values go as {code, value | values} like the product's.
 */
class CustomerSchema
{
    /** @return array{query: array, mutation: array} */
    public static function fields(): array
    {
        $service = new CustomerService();
        $addressService = new AddressService();
        $guard = static fn (callable $resolve) => Schema::guard('Iter_Customer::customer', $resolve);

        /** @return ?array value => label of an option attribute, null for the others; loaded once per request */
        $options = null;
        $optionsOf = static function (string $code) use (&$options): ?array {
            if ($options === null) {
                $resource = (new Attribute())->getResource();
                $options = [];
                foreach ($resource->getByEntityType((int) $resource->getEntityTypes()[Customer::ENTITY_TYPE]['entity_type_id']) as $row) {
                    $list = $resource->getOptions((int) $row['attribute_id']);
                    $options[$row['code']] = $list ? array_column($list, 'label', 'value') : null;
                }
            }

            return $options[$code] ?? null;
        };

        $attributeType = new ObjectType([
            'name' => 'CustomerAttribute',
            'fields' => [
                'code' => Type::nonNull(Type::string()),
                'value' => ['type' => Type::string(), 'description' => 'Valor de atributos de valor único (Sim/Não = "1"/"0").'],
                'values' => ['type' => Type::listOf(Type::nonNull(Type::string())), 'description' => 'Valores de atributos de múltipla seleção.'],
                'option_label' => ['type' => Type::string(), 'description' => 'Rótulo da opção escolhida, em atributos de opções.'],
                'option_labels' => ['type' => Type::listOf(Type::nonNull(Type::string())), 'description' => 'Rótulos das opções escolhidas (Múltipla seleção).'],
            ],
        ]);
        $addressType = new ObjectType([
            'name' => 'CustomerAddress',
            'fields' => [
                'id' => ['type' => Type::nonNull(Type::id()), 'resolve' => fn ($a) => $a['address_id']],
                'recipient' => Type::string(),
                'postcode' => ['type' => Type::nonNull(Type::string()), 'description' => 'Só os 8 dígitos.'],
                'street' => Type::nonNull(Type::string()),
                'number' => Type::nonNull(Type::string()),
                'complement' => Type::string(),
                'district' => Type::nonNull(Type::string()),
                'city' => Type::nonNull(Type::string()),
                'region' => ['type' => Type::nonNull(Type::string()), 'description' => 'UF, ex.: SP.'],
                'telephone' => Type::string(),
                'is_default_shipping' => ['type' => Type::nonNull(Type::boolean()), 'resolve' => fn ($a) => (bool) $a['is_default_shipping']],
                'is_default_billing' => ['type' => Type::nonNull(Type::boolean()), 'resolve' => fn ($a) => (bool) $a['is_default_billing']],
            ],
        ]);
        $typeEnum = new EnumType([
            'name' => 'CustomerType',
            'values' => ['PF' => ['value' => 'pf', 'description' => 'Pessoa física.'], 'PJ' => ['value' => 'pj', 'description' => 'Pessoa jurídica.']],
        ]);
        $customerType = new ObjectType([
            'name' => 'Customer',
            'fields' => [
                'id' => ['type' => Type::nonNull(Type::id()), 'resolve' => fn ($c) => $c['customer_id']],
                'websiteId' => ['type' => Type::nonNull(Type::id()), 'resolve' => fn ($c) => $c['website_id']],
                'groupId' => ['type' => Type::nonNull(Type::id()), 'resolve' => fn ($c) => $c['group_id']],
                'attribute_set_id' => Type::nonNull(Type::id()),
                'type' => ['type' => Type::nonNull($typeEnum), 'resolve' => fn ($c) => $c['customer_type']],
                'email' => Type::nonNull(Type::string()),
                'firstname' => Type::nonNull(Type::string()),
                'lastname' => Type::nonNull(Type::string()),
                'taxvat' => ['type' => Type::string(), 'description' => 'CPF (PF) ou CNPJ (PJ), só os dígitos.'],
                'dob' => ['type' => Type::string(), 'description' => 'Nascimento (PF), AAAA-MM-DD.'],
                'company_name' => ['type' => Type::string(), 'description' => 'Razão social (PJ).'],
                'trade_name' => ['type' => Type::string(), 'description' => 'Nome fantasia (PJ).'],
                'state_registration' => ['type' => Type::string(), 'description' => 'Inscrição estadual (PJ).'],
                'is_ie_exempt' => ['type' => Type::nonNull(Type::boolean()), 'resolve' => fn ($c) => (bool) $c['is_ie_exempt']],
                'is_active' => ['type' => Type::nonNull(Type::boolean()), 'resolve' => fn ($c) => (bool) $c['is_active']],
                'created_at' => Type::nonNull(Type::string()),
                'updated_at' => Type::nonNull(Type::string()),
                'attributes' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($attributeType))),
                    // ponytail: one values query per customer (N+1), capped by pageSize
                    'resolve' => static function ($c) use ($service, $optionsOf): array {
                        $rows = [];
                        foreach ($service->getValues((int) $c['customer_id']) as $code => $value) {
                            $options = $optionsOf($code);
                            $labels = $options === null ? null : array_map(static fn ($v) => $options[$v] ?? (string) $v, (array) $value);
                            $rows[] = [
                                'code' => $code,
                                'value' => is_array($value) ? null : (is_bool($value) ? ($value ? '1' : '0') : (string) $value),
                                'values' => is_array($value) ? $value : null,
                                'option_label' => $labels !== null && !is_array($value) ? $labels[0] : null,
                                'option_labels' => $labels !== null && is_array($value) ? $labels : null,
                            ];
                        }

                        return $rows;
                    },
                ],
                'addresses' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($addressType))),
                    'description' => 'Os padrões primeiro.',
                    'resolve' => static fn ($c) => $addressService->getList((int) $c['customer_id']),
                ],
            ],
        ]);
        $customerList = new ObjectType([
            'name' => 'CustomerList',
            'fields' => [
                'items' => Type::nonNull(Type::listOf(Type::nonNull($customerType))),
                'total' => Type::nonNull(Type::int()),
            ],
        ]);
        $attributeInput = new InputObjectType([
            'name' => 'CustomerAttributeInput',
            'fields' => [
                'code' => Type::nonNull(Type::string()),
                'value' => ['type' => Type::string(), 'description' => 'Valor único; vazio ou null apaga.'],
                'values' => ['type' => Type::listOf(Type::nonNull(Type::string())), 'description' => 'Para múltipla seleção; [] apaga.'],
            ],
        ]);
        $addressInput = new InputObjectType([
            'name' => 'CustomerAddressInput',
            'fields' => [
                'id' => ['type' => Type::id(), 'description' => 'Endereço existente; omitido = novo.'],
                'recipient' => Type::string(),
                'postcode' => ['type' => Type::nonNull(Type::string()), 'description' => 'Com ou sem máscara.'],
                'street' => Type::nonNull(Type::string()),
                'number' => Type::nonNull(Type::string()),
                'complement' => Type::string(),
                'district' => Type::nonNull(Type::string()),
                'city' => Type::nonNull(Type::string()),
                'region' => ['type' => Type::nonNull(Type::string()), 'description' => 'UF, ex.: SP.'],
                'telephone' => Type::string(),
                'isDefaultShipping' => ['type' => Type::boolean(), 'defaultValue' => false],
                'isDefaultBilling' => ['type' => Type::boolean(), 'defaultValue' => false],
            ],
        ]);
        // shared by create and update; on update every argument is optional
        $customerArgs = static fn (bool $create): array => [
            'websiteId' => ['type' => Type::id(), 'description' => $create ? 'Omitido = website padrão.' : null],
            'groupId' => $create ? Type::nonNull(Type::id()) : Type::id(),
            'email' => $create ? Type::nonNull(Type::string()) : Type::string(),
            'firstname' => $create ? Type::nonNull(Type::string()) : Type::string(),
            'lastname' => $create ? Type::nonNull(Type::string()) : Type::string(),
            'type' => $create ? Type::nonNull($typeEnum) : $typeEnum,
            'taxvat' => ['type' => $create ? Type::nonNull(Type::string()) : Type::string(), 'description' => 'CPF (PF) ou CNPJ (PJ), com ou sem máscara.'],
            'dob' => ['type' => Type::string(), 'description' => 'AAAA-MM-DD, só PF.'],
            'companyName' => ['type' => Type::string(), 'description' => 'Obrigatória para PJ.'],
            'tradeName' => Type::string(),
            'stateRegistration' => ['type' => Type::string(), 'description' => 'Para PJ: a inscrição estadual ou isIeExempt.'],
            'isIeExempt' => Type::boolean(),
            'isActive' => $create ? ['type' => Type::boolean(), 'defaultValue' => true] : Type::boolean(),
            'attributes' => Type::listOf(Type::nonNull($attributeInput)),
            'addresses' => ['type' => Type::listOf(Type::nonNull($addressInput)), 'description' => $create ? null : 'A lista enviada é a lista final: endereço ausente é removido. Omitido = sem mudança.'],
        ];
        $data = static fn (array $args, array $current = []): array => [
            'website_id' => $args['websiteId'] ?? $current['website_id'] ?? 0,
            'group_id' => $args['groupId'] ?? $current['group_id'] ?? 0,
            'email' => $args['email'] ?? $current['email'] ?? '',
            'firstname' => $args['firstname'] ?? $current['firstname'] ?? '',
            'lastname' => $args['lastname'] ?? $current['lastname'] ?? '',
            'customer_type' => $args['type'] ?? $current['customer_type'] ?? '',
            'taxvat' => $args['taxvat'] ?? $current['taxvat'] ?? '',
            'dob' => $args['dob'] ?? $current['dob'] ?? '',
            'company_name' => $args['companyName'] ?? $current['company_name'] ?? '',
            'trade_name' => $args['tradeName'] ?? $current['trade_name'] ?? '',
            'state_registration' => $args['stateRegistration'] ?? $current['state_registration'] ?? '',
            'is_ie_exempt' => (int) ($args['isIeExempt'] ?? $current['is_ie_exempt'] ?? 0),
            'is_active' => (int) ($args['isActive'] ?? $current['is_active'] ?? 1),
        ];
        // as the product's: a code outside the set is a client error, not ignored
        $attributes = static function (array $args, int $setId): array {
            $values = array_column(array_map(static fn ($a) => ['code' => $a['code'], 'value' => $a['values'] ?? $a['value'] ?? ''], $args['attributes'] ?? []), 'value', 'code');
            $set = CustomerService::sets()[$setId] ?? null;
            if ($values && $set) { // invalid set: CustomerService reports it
                $unknown = array_diff(array_keys($values), array_column((new FormSchemaGenerator())->generate(Customer::ENTITY_TYPE, $set['code'])['fields'], 'name'));
                if ($unknown) {
                    throw new AttributeException('ATTRIBUTE_NOT_FOUND', 'Atributo fora do conjunto do cliente: ' . implode(', ', $unknown) . '.');
                }
            }

            return $values;
        };
        // sign-up and login share a per-IP limit against password guessing and spam accounts
        $limit = static function (): void {
            if (!RateLimit::hit('customer_login:' . RateLimit::ip(), (int) Config::get('customer/security/api_login_per_minute'))) {
                http_response_code(429);
                throw new Error('Muitas tentativas. Aguarde um minuto e tente de novo.', extensions: ['code' => 'TOO_MANY_REQUESTS']);
            }
        };
        $sessionType = new ObjectType([
            'name' => 'CustomerSession',
            'fields' => [
                'token' => ['type' => Type::string(), 'description' => 'Envie em "Authorization: Bearer <token>". null no cadastro que aguarda a confirmação do e-mail.'],
                'expires_at' => ['type' => Type::string(), 'description' => 'Configurações → Clientes → validade do token. O app renova com refreshCustomerToken.'],
                'confirmationRequired' => ['type' => Type::nonNull(Type::boolean()), 'description' => 'true: o cliente precisa abrir o link do e-mail (confirmCustomerEmail) antes de entrar.'],
                'customer' => Type::nonNull($customerType),
            ],
        ]);
        $session = static fn (Customer $c): array => CustomerAuth::issueToken($c) + ['confirmationRequired' => false, 'customer' => $c->getData()];
        $addressRow = static fn (array $a): array => [
            'address_id' => $a['id'] ?? null,
            'is_default_shipping' => $a['isDefaultShipping'] ? 1 : 0,
            'is_default_billing' => $a['isDefaultBilling'] ? 1 : 0,
        ] + $a;
        $addresses = static fn (array $args): ?array => isset($args['addresses']) ? array_map($addressRow, $args['addresses']) : null;
        // partial update: CustomerService saves the whole set (missing = empty), so it starts from the
        // stored values, minus options removed since they were chosen
        $update = static function (int $id, array $args) use ($service, $data, $attributes, $addresses, $optionsOf): array {
            $current = $service->get($id)->getData();
            $stored = $service->getValues($id);
            foreach ($stored as $code => $value) {
                $options = $optionsOf($code);
                if ($options !== null) {
                    $stored[$code] = is_array($value) ? array_values(array_filter($value, static fn ($v) => isset($options[$v]))) : (isset($options[$value]) ? $value : '');
                }
            }

            return $service->update($id, $data($args, $current), $attributes($args, (int) $current['attribute_set_id']) + $stored, $addresses($args))->getData();
        };
        $me = static fn (): Customer => CustomerAuth::customer() ?? throw new Error('Faça login como cliente.', extensions: ['code' => 'UNAUTHENTICATED']);
        // "Minha conta" addresses: AddressService::save() takes the whole list, so each mutation edits the stored one
        $editMyAddresses = static function (callable $edit) use ($me, $addressService): array {
            $id = (int) $me()->getId();
            $list = array_column($addressService->getList($id), null, 'address_id');
            $addressService->save($id, $edit($list));

            return $addressService->getList($id);
        };
        $myAddress = static function (array $list, string $id): array {
            return $list[(int) $id] ?? throw new AttributeException('ADDRESS_NOT_FOUND', "O endereço {$id} não é deste cliente.");
        };
        // a default set here wins over the one stored (AddressService keeps the first marked)
        $withDefaults = static function (array $list, array $row): array {
            foreach (['is_default_shipping', 'is_default_billing'] as $flag) {
                if ($row[$flag]) {
                    $list = array_map(static fn ($a) => [$flag => 0] + $a, $list);
                }
            }

            return $list;
        };

        return [
            'query' => [
                'customer' => [
                    'type' => $customerType,
                    'description' => 'Por id ou por e-mail; informe um dos dois. O e-mail é único por website.',
                    'args' => ['id' => Type::id(), 'email' => Type::string(), 'websiteId' => ['type' => Type::id(), 'description' => 'Website do e-mail; omitido = website padrão.']],
                    'resolve' => $guard(static function ($root, array $args): ?array {
                        if (isset($args['id']) === isset($args['email'])) {
                            throw new Error('Informe id ou email.', extensions: ['code' => 'INVALID_ARGUMENT']);
                        }
                        $resource = (new Customer())->getResource();
                        $id = $args['id'] ?? $resource->findIdByEmail($args['email'], (int) ($args['websiteId'] ?? (new Website())->getResource()->getDefaultId()));
                        $customer = (new Customer())->load((int) $id);

                        return $customer->getId() ? $customer->getData() : null;
                    }),
                ],
                'me' => [
                    'type' => $customerType,
                    'description' => 'O cliente do token; null sem login de cliente.',
                    'resolve' => static fn () => CustomerAuth::customer()?->getData(),
                ],
                'customers' => [
                    'type' => Type::nonNull($customerList),
                    'args' => [
                        'search' => ['type' => Type::string(), 'description' => 'Parte do nome, razão social, e-mail ou CPF/CNPJ.'],
                        'type' => $typeEnum,
                        'groupId' => Type::id(),
                        'websiteId' => Type::id(),
                        'isActive' => Type::boolean(),
                        'page' => ['type' => Type::int(), 'defaultValue' => 1],
                        'pageSize' => ['type' => Type::int(), 'defaultValue' => 20, 'description' => 'Máximo 100.'],
                    ],
                    'resolve' => $guard(static fn ($root, array $args) => (new Customer())->getResource()->search([
                        'q' => (string) ($args['search'] ?? ''),
                        'customer_type' => (string) ($args['type'] ?? ''),
                        'group_id' => (string) ($args['groupId'] ?? ''),
                        'website_id' => (string) ($args['websiteId'] ?? ''),
                        'is_active' => isset($args['isActive']) ? ($args['isActive'] ? '1' : '0') : '',
                    ], max(1, $args['page']), min(100, max(1, $args['pageSize'])))),
                ],
            ],
            'mutation' => [
                'registerCustomer' => [
                    'type' => Type::nonNull($sessionType),
                    'description' => 'Cadastro pela loja: a conta entra no grupo Geral, ativa. Com a confirmação de e-mail ligada, não traz token até confirmCustomerEmail.',
                    'args' => array_intersect_key($customerArgs(true), array_flip(['websiteId', 'email', 'firstname', 'lastname', 'type', 'taxvat', 'dob', 'companyName', 'tradeName', 'stateRegistration', 'isIeExempt', 'attributes']))
                        + ['password' => Type::nonNull(Type::string())],
                    'resolve' => static function ($root, array $args) use ($limit, $data, $attributes, $session): array {
                        $limit();
                        $args['websiteId'] ??= (new Website())->getResource()->getDefaultId();
                        $setId = (int) key(array_filter(CustomerService::sets(), static fn ($s) => $s['is_default']));
                        $values = $attributes($args, $setId);
                        $result = CustomerAuth::register(['attribute_set_id' => $setId, 'attributes' => $values] + $data($args), $args['password']);

                        return $result['confirmation_required']
                            ? ['token' => null, 'expires_at' => null, 'confirmationRequired' => true, 'customer' => $result['customer']->getData()]
                            : $session($result['customer']);
                    },
                ],
                'confirmCustomerEmail' => [
                    'type' => Type::nonNull($sessionType),
                    'description' => 'O token do link do e-mail de confirmação. Confirma e já entra.',
                    'args' => ['token' => Type::nonNull(Type::string())],
                    'resolve' => static function ($root, array $args) use ($session): array {
                        $customer = CustomerAuth::confirm($args['token']) ?? throw new Error('Link de confirmação inválido ou já usado.', extensions: ['code' => 'TOKEN_INVALID']);
                        return $session($customer);
                    },
                ],
                'generateCustomerToken' => [
                    'type' => Type::nonNull($sessionType),
                    'description' => 'Login do cliente pela loja.',
                    'args' => ['email' => Type::nonNull(Type::string()), 'password' => Type::nonNull(Type::string()), 'websiteId' => ['type' => Type::id(), 'description' => 'Omitido = website padrão.']],
                    'resolve' => static function ($root, array $args) use ($limit, $session): array {
                        $limit();
                        return $session(CustomerAuth::checkCredentials($args['email'], $args['password'], (int) ($args['websiteId'] ?? (new Website())->getResource()->getDefaultId())));
                    },
                ],
                'refreshCustomerToken' => [
                    'type' => Type::nonNull($sessionType),
                    'description' => 'Troca o token enviado por um novo, com a validade renovada (app mobile).',
                    'resolve' => static function () use ($session): array {
                        $customer = CustomerAuth::customer() ?? throw new Error('Faça login como cliente.', extensions: ['code' => 'UNAUTHENTICATED']);
                        CustomerAuth::revokeToken();
                        return $session($customer);
                    },
                ],
                'revokeCustomerToken' => [
                    'type' => Type::nonNull(Type::boolean()),
                    'description' => 'Logout: o token enviado deixa de valer. false sem token de cliente.',
                    'resolve' => static fn () => CustomerAuth::revokeToken(),
                ],
                'updateMe' => [
                    'type' => Type::nonNull($customerType),
                    'description' => 'O cliente do token muda os próprios dados; só muda o que for enviado. Trocar e-mail ou CPF/CNPJ pede currentPassword.',
                    'args' => array_intersect_key($customerArgs(false), array_flip(['email', 'firstname', 'lastname', 'type', 'taxvat', 'dob', 'companyName', 'tradeName', 'stateRegistration', 'isIeExempt', 'attributes']))
                        + ['currentPassword' => ['type' => Type::string(), 'description' => 'Obrigatória para trocar e-mail ou CPF/CNPJ.']],
                    'resolve' => static function ($root, array $args) use ($me, $update, $limit): array {
                        $customer = $me();
                        $changes = (isset($args['email']) && mb_strtolower(trim($args['email'])) !== $customer->getData('email'))
                            || (isset($args['taxvat']) && preg_replace('/\D/', '', $args['taxvat']) !== $customer->getData('taxvat'));
                        if ($changes) {
                            $limit(); // the password check can't be used to guess it
                            CustomerAuth::verifyPassword($customer, (string) ($args['currentPassword'] ?? ''));
                        }

                        return $update((int) $customer->getId(), $args);
                    },
                ],
                'changeMyPassword' => [
                    'type' => Type::nonNull(Type::boolean()),
                    'description' => 'Troca a senha do cliente do token. Os outros logins (tokens) deixam de valer; o enviado continua.',
                    'args' => ['currentPassword' => Type::nonNull(Type::string()), 'newPassword' => Type::nonNull(Type::string())],
                    'resolve' => static function ($root, array $args) use ($me, $limit): bool {
                        $customer = $me();
                        $limit();
                        CustomerAuth::changePassword($customer, $args['currentPassword'], $args['newPassword']);
                        return true;
                    },
                ],
                'createMyAddress' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($addressType))),
                    'description' => 'Novo endereço do cliente do token. Devolve a lista, os padrões primeiro. O primeiro endereço vira o padrão.',
                    'args' => ['address' => Type::nonNull($addressInput)],
                    'resolve' => static fn ($root, array $args) => $editMyAddresses(static function (array $list) use ($args, $addressRow, $withDefaults): array {
                        $row = $addressRow(['id' => null] + $args['address']);
                        return [...$withDefaults($list, $row), $row];
                    }),
                ],
                'updateMyAddress' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($addressType))),
                    'description' => 'Troca o endereço inteiro (o id vem em address.id). Marcar um padrão tira o de outro endereço; desmarcar não muda o padrão (use setMyDefaultAddress em outro).',
                    'args' => ['address' => Type::nonNull($addressInput)],
                    'resolve' => static fn ($root, array $args) => $editMyAddresses(static function (array $list) use ($args, $addressRow, $withDefaults, $myAddress): array {
                        $id = (string) ($args['address']['id'] ?? throw new Error('Informe address.id.', extensions: ['code' => 'INVALID_ARGUMENT']));
                        $current = $myAddress($list, $id);
                        $row = $addressRow($args['address']);
                        foreach (['is_default_shipping', 'is_default_billing'] as $flag) {
                            $row[$flag] = $row[$flag] ?: (int) $current[$flag]; // unmarking moves nothing
                        }
                        $list = $withDefaults($list, $row);
                        $list[(int) $id] = $row;
                        return $list;
                    }),
                ],
                'setMyDefaultAddress' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($addressType))),
                    'description' => 'Torna o endereço o padrão de entrega e/ou de cobrança.',
                    'args' => ['id' => Type::nonNull(Type::id()), 'shipping' => ['type' => Type::boolean(), 'defaultValue' => true], 'billing' => ['type' => Type::boolean(), 'defaultValue' => true]],
                    'resolve' => static fn ($root, array $args) => $editMyAddresses(static function (array $list) use ($args, $withDefaults, $myAddress): array {
                        $current = $myAddress($list, (string) $args['id']);
                        $row = ['is_default_shipping' => $args['shipping'] ? 1 : (int) $current['is_default_shipping'], 'is_default_billing' => $args['billing'] ? 1 : (int) $current['is_default_billing']] + $current;
                        $list = $withDefaults($list, $row);
                        $list[(int) $args['id']] = $row;
                        return $list;
                    }),
                ],
                'deleteMyAddress' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($addressType))),
                    'description' => 'Remove o endereço. Se era o padrão, o primeiro que sobrar vira o padrão.',
                    'args' => ['id' => Type::nonNull(Type::id())],
                    'resolve' => static fn ($root, array $args) => $editMyAddresses(static function (array $list) use ($args, $myAddress): array {
                        $myAddress($list, (string) $args['id']);
                        unset($list[(int) $args['id']]);
                        return $list;
                    }),
                ],
                'createCustomer' => [
                    'type' => Type::nonNull($customerType),
                    'args' => $customerArgs(true) + ['attributeSetId' => ['type' => Type::id(), 'description' => 'Omitido = conjunto padrão de cliente.']],
                    'resolve' => $guard(static function ($root, array $args) use ($service, $data, $attributes, $addresses): array {
                        $args['websiteId'] ??= (new Website())->getResource()->getDefaultId();
                        $setId = (int) ($args['attributeSetId'] ?? key(array_filter(CustomerService::sets(), static fn ($s) => $s['is_default'])));

                        return $service->create($data($args) + ['attribute_set_id' => $setId], $attributes($args, $setId), $addresses($args))->getData();
                    }),
                ],
                'updateCustomer' => [
                    'type' => Type::nonNull($customerType),
                    'description' => 'Só muda o que for enviado; atributos omitidos mantêm o valor. O conjunto não muda.',
                    'args' => ['id' => Type::nonNull(Type::id())] + $customerArgs(false),
                    'resolve' => $guard(static fn ($root, array $args) => $update((int) $args['id'], $args)),
                ],
                'deleteCustomer' => [
                    'type' => Type::nonNull(Type::boolean()),
                    'description' => 'Apaga também os endereços e os atributos.',
                    'args' => ['id' => Type::nonNull(Type::id())],
                    'resolve' => $guard(static function ($root, array $args) use ($service): bool {
                        $service->delete((int) $args['id']);
                        return true;
                    }),
                ],
            ],
        ];
    }
}
