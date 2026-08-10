<?php

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

abstract class TransactionalDomainActivityEvent extends DomainActivityEvent implements ShouldDispatchAfterCommit
{
}
