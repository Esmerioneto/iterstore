<?php

declare(strict_types=1);

namespace Iter\Customer\Model;

use Iter\Core\Db\Connection;
use Iter\Eav\Form\FormSchemaGenerator;
use Iter\Eav\Model\Attribute;
use Iter\Eav\Model\AttributeException;
use Iter\Eav\Model\AttributeSet;
use Iter\Eav\Model\Taxvat;
use Iter\Eav\Model\ValueService;
use Iter\Store\Model\Website;

/**
 * Customers: website, group, status and secrets live in customer_entity; the registration
 * fields (Customer::FIELDS, system attributes) and everything else in EAV values, e-mail and
 * CPF/CNPJ unique per website (eav_unique_value). The fields of the other type are cleared on
 * save. Errors reuse AttributeException, as ValueService throws it too.
 */
class CustomerService
{
    /**
     * @param array{website_id: int, group_id: int, email: string, firstname: string, lastname: string, customer_type: string,
     *              taxvat?: string, company_name?: string, trade_name?: string, state_registration?: string, is_ie_exempt?: int,
     *              dob?: string, is_active?: int, attribute_set_id?: int} $data taxvat: CPF when pf, CNPJ when pj (mask optional)
     * @param array<string, mixed> $attributes code => value
     * @param array<int, array<string, mixed>>|null $addresses see AddressService::save(); null leaves them as they are
     */
    public function create(array $data, array $attributes = [], ?array $addresses = null): Customer
    {
        $set = $this->set((int) ($data['attribute_set_id'] ?? 0));
        $fields = $this->fields($data);
        $values = new ValueService();
        $rows = $this->rows($values, $fields, $this->setValues($set, $attributes));

        return Connection::get()->adapter()->transaction(function () use ($fields, $set, $values, $rows, $addresses): Customer {
            $this->assertUnique($fields);
            $model = (new Customer())->addData($fields + ['attribute_set_id' => (int) $set->getId()])->save();
            if ($addresses !== null) {
                (new AddressService())->save((int) $model->getId(), $addresses);
            }
            $values->write((int) $model->getId(), $rows, $fields['website_id']);

            return $model;
        });
    }

    /** the attribute set is chosen on creation and doesn't change */
    public function update(int $id, array $data, array $attributes = [], ?array $addresses = null): Customer
    {
        $model = $this->get($id);
        $fields = $this->fields($data, $id);
        $values = new ValueService();
        $rows = $this->rows($values, $fields, $this->setValues($this->set((int) $model->getData('attribute_set_id')), $attributes), $id);

        return Connection::get()->adapter()->transaction(function () use ($model, $fields, $values, $id, $rows, $addresses): Customer {
            $this->assertUnique($fields, $id);
            $model->addData($fields)->save();
            if ($addresses !== null) {
                (new AddressService())->save($id, $addresses);
            }
            $values->write($id, $rows, $fields['website_id']);

            return $model;
        });
    }

    /** @return array<string, mixed> code => value, without the registration fields (they're on the model) */
    public function getValues(int $id): array
    {
        return array_diff_key((new ValueService())->load(Customer::ENTITY_TYPE, $id), array_flip(Customer::FIELDS));
    }

    public function delete(int $id): void
    {
        $model = $this->get($id);
        Connection::get()->adapter()->transaction(static function () use ($model, $id): void {
            (new AddressService())->save($id, []); // address values have no FK to cascade
            $model->delete();
            (new ValueService())->deleteEntity(Customer::ENTITY_TYPE, $id);
        });
    }

    public function get(int $id): Customer
    {
        $model = (new Customer())->load($id);
        if (!$model->getId()) {
            throw new AttributeException('CUSTOMER_NOT_FOUND', 'Cliente não encontrado.');
        }

        return $model;
    }

    /** @return array<int, array<string, mixed>> the customer entity's attribute sets, by id */
    public static function sets(): array
    {
        $sets = array_filter((new AttributeSet())->getResource()->getList(), static fn ($s) => $s['entity_type'] === Customer::ENTITY_TYPE);

        return array_column($sets, null, 'attribute_set_id');
    }

    /** "12345678901" -> "123.456.789-01", 14 digits as CNPJ */
    public static function formatTaxvat(?string $digits): string
    {
        return match (strlen((string) $digits)) {
            11 => preg_replace('/^(\d{3})(\d{3})(\d{3})(\d{2})$/', '$1.$2.$3-$4', $digits),
            14 => preg_replace('/^(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})$/', '$1.$2.$3/$4-$5', $digits),
            default => (string) $digits,
        };
    }

    /** the registration fields' EAV rows, then the set's attributes */
    private function rows(ValueService $values, array $fields, array $attributes, ?int $id = null): array
    {
        return array_merge(
            $values->validate(Customer::ENTITY_TYPE, array_intersect_key($fields, array_flip(Customer::FIELDS)), $id),
            $values->validate(Customer::ENTITY_TYPE, $attributes, $id)
        );
    }

    /**
     * Inside the save's transaction: the lock queues saves of the same website, so a concurrent
     * sign-up with the same e-mail waits and gets EMAIL_DUPLICATE here instead of a raw 23505.
     */
    private function assertUnique(array $fields, ?int $id = null): void
    {
        $resource = (new Customer())->getResource();
        $resource->getConnection()->fetchOne('SELECT pg_advisory_xact_lock(hashtext(?), ?)', ['customer_unique', $fields['website_id']]);
        if ($resource->exists('email', $fields['email'], $fields['website_id'], $id)) {
            throw new AttributeException('EMAIL_DUPLICATE', 'Já existe um cliente com este e-mail neste website.');
        }
        if ($resource->exists('taxvat', $fields['taxvat'], $fields['website_id'], $id)) {
            throw new AttributeException('TAXVAT_DUPLICATE', 'Já existe um cliente com este ' . ($fields['customer_type'] === 'pf' ? 'CPF' : 'CNPJ') . ' neste website.');
        }
    }

    private function set(int $setId): AttributeSet
    {
        $sets = self::sets();
        $setId = isset($sets[$setId]) ? $setId : (int) key(array_filter($sets, static fn ($s) => $s['is_default']));
        $set = (new AttributeSet())->load($setId);
        if (!$set->getId()) {
            throw new AttributeException('ATTRIBUTE_SET_NOT_FOUND', 'Conjunto de atributos inválido.');
        }

        return $set;
    }

    /** posted values limited to the fields the set's form shows, missing ones as empty */
    private function setValues(AttributeSet $set, array $attributes): array
    {
        $schema = (new FormSchemaGenerator())->generate(Customer::ENTITY_TYPE, $set->getData('code'));
        $values = [];
        foreach ($schema['fields'] as $field) {
            $values[$field['name']] = $attributes[$field['name']] ?? '';
        }

        return $values;
    }

    private function fields(array $data, ?int $id = null): array
    {
        $error = static fn (string $code, string $message) => throw new AttributeException($code, $message);

        $websiteId = (int) ($data['website_id'] ?? 0);
        if ($websiteId <= 0 || !(new Website())->load($websiteId)->getId()) {
            $error('WEBSITE_INVALID', 'Selecione um website.');
        }
        $groupId = (int) ($data['group_id'] ?? 0);
        if ($groupId <= 0 || !(new Group())->load($groupId)->getId()) {
            $error('GROUP_INVALID', 'Selecione um grupo de clientes.');
        }
        $email = mb_strtolower(trim((string) ($data['email'] ?? '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255) {
            $error('EMAIL_INVALID', 'Informe um e-mail válido.');
        }
        $names = [];
        foreach (['firstname' => 'Nome', 'lastname' => 'Sobrenome'] as $key => $label) {
            $names[$key] = trim((string) ($data[$key] ?? ''));
            if ($names[$key] === '' || mb_strlen($names[$key]) > 255) {
                $error('NAME_REQUIRED', "{$label} é obrigatório (máx. 255 caracteres).");
            }
        }

        $type = (string) ($data['customer_type'] ?? '');
        if (!isset(Customer::TYPES[$type])) {
            $error('TYPE_INVALID', 'Tipo de cliente inválido.');
        }
        $taxvat = preg_replace('/\D/', '', (string) ($data['taxvat'] ?? ''));
        // pf: CPF and birth date; pj: CNPJ, company names and state registration. The other type's fields are cleared
        $fields = ['company_name' => null, 'trade_name' => null, 'state_registration' => null, 'is_ie_exempt' => 0, 'dob' => null];
        if ($type === 'pf') {
            if (!Taxvat::validCpf($taxvat)) {
                $error('TAXVAT_INVALID', 'Informe um CPF válido.');
            }
            $dob = trim((string) ($data['dob'] ?? ''));
            if ($dob !== '') {
                $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $dob);
                if (!$date || $date->format('Y-m-d') !== $dob || $date > new \DateTimeImmutable() || $date->format('Y') < 1900) {
                    $error('DOB_INVALID', 'Data de nascimento inválida.');
                }
                $fields['dob'] = $dob;
            }
        } else {
            if (!Taxvat::validCnpj($taxvat)) {
                $error('TAXVAT_INVALID', 'Informe um CNPJ válido.');
            }
            $fields['company_name'] = trim((string) ($data['company_name'] ?? ''));
            if ($fields['company_name'] === '' || mb_strlen($fields['company_name']) > 255) {
                $error('COMPANY_REQUIRED', 'Razão social é obrigatória (máx. 255 caracteres).');
            }
            $fields['trade_name'] = mb_substr(trim((string) ($data['trade_name'] ?? '')), 0, 255) ?: null;
            $fields['is_ie_exempt'] = empty($data['is_ie_exempt']) ? 0 : 1;
            $ie = strtoupper(trim((string) ($data['state_registration'] ?? '')));
            if (!$fields['is_ie_exempt']) {
                if (!preg_match('#^[0-9A-Z./-]{2,20}$#', $ie)) {
                    $error('IE_REQUIRED', 'Informe a inscrição estadual ou marque "Isento".');
                }
                $fields['state_registration'] = $ie;
            }
        }

        return [
            'website_id' => $websiteId,
            'group_id' => $groupId,
            'email' => $email,
            'customer_type' => $type,
            'taxvat' => $taxvat,
            'is_active' => empty($data['is_active']) ? 0 : 1,
        ] + $names + $fields;
    }
}
