<?php

declare(strict_types=1);

namespace Iter\Lgpd\Model;

/**
 * A module's own share of a data subject's personal data (LGPD-PLAN §3). Named by "handler" in the module's
 * etc/lgpd.json; Iter_Lgpd calls it without knowing the module's tables, so turning the module off takes its
 * part along.
 *
 * A handler may also have purge(array $retention): int, the retention routine (bin/lgpd-clean.php): it applies
 * the module's periods (Configurações → LGPD → Prazos de guarda, PersonalDataService::retention()) and returns how
 * many records it anonymized or deleted. Optional, so it isn't in the interface.
 *
 * $email is the subject's e-mail as it was before anything was erased: it finds what is kept by e-mail
 * (a visitor's orders, newsletter, e-mails sent), and handlers run in any order.
 */
interface PersonalDataInterface
{
    /**
     * What this module keeps about the subject, for the copy they ask for (art. 18, II). $customerId null:
     * a visitor, found only by $email. An empty array leaves the module out of the copy.
     */
    public function export(?int $customerId, string $email): array;

    /**
     * Why the customer's data can't be erased now (an order still running, …), one sentence each; empty
     * when nothing stops it. Checked for every module before any erase() runs.
     *
     * @return list<string>
     */
    public function blockers(int $customerId): array;

    /**
     * Anonymizes or deletes what this module keeps about the customer (art. 18, VI), inside the caller's
     * transaction. What the law requires to keep (orders, consent proof) stays until its retention ends.
     */
    public function erase(int $customerId, string $email): void;
}
