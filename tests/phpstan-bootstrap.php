<?php

/*
 * PHPStan's view of the App\ classes omnibase's contracts name (App\Entity\User in
 * Base\Repository\User\Notifications...): this package alone is no application and defines
 * none of them, so each is the Base\ class under another name, as omnibase's runtime aliases
 * it (Base\Service\Model\ClassAliases) - the core's tests/phpstan-bootstrap.php does the same.
 */

Base\Service\Model\ClassAliases::register();
