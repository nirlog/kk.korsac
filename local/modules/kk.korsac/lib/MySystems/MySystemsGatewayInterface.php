<?php

declare(strict_types=1);

namespace KK\Korsac\MySystems;

/** Read-only capability: no arbitrary filters, mutations or caller-controlled SQL. */
interface MySystemsGatewayInterface
{
    /** Canonical USER_ID selection, ordered CREATED_AT DESC/SYSTEM_ID DESC; at most limit+1 parents.
     * @return list<MySystemReadRecord> */
    public function findForUser(int $userId,MySystemsPageRequest $page): array;
}
