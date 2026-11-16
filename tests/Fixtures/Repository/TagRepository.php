<?php

namespace Omnibridge\Eloquent\Tests\Fixtures\Repository;

use Base\Database\Repository\Finders;
use Base\Database\Repository\Repositories;
use Base\Database\Repository\ServiceEntityRepository;
use Omnibridge\Persistence\UnitOfWork;

/**
 * A repository that takes what the core owns beside the Finders - the repositories, the unit of work - as omnibase/blog's
 * PostRepository does: EntityRepositories builds it with them, whatever the host's container holds.
 *
 * @extends ServiceEntityRepository<object>
 */
class TagRepository extends ServiceEntityRepository
{
    public function __construct(Finders $finders, public readonly Repositories $repositories, public readonly ?UnitOfWork $unitOfWork = null)
    {
        parent::__construct($finders);
    }
}
