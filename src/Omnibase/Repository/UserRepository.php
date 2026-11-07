<?php

namespace Omnibridge\Eloquent\Omnibase\Repository;

use Base\Contract\SecurityUserLoader;
use Base\Contract\SecurityUserProvider;
use Base\Database\Repository\Finder\Group;
use Base\Entity\User;
use Base\Repository\Users;
use Omnibridge\Eloquent\Omnibase\EloquentEntities;

/**
 * omnibridge/doctrine's UserRepository on Eloquent (plan "omnibase agnostic", step F7b): the same finders, compiled by
 * EloquentFinder. Security's user loader, user provider and password upgrader too (step F7d), as Doctrine's declares
 * them through Base\Contract\SecurityUserLoader and SecurityUserProvider (Symfony's interfaces where its security and
 * doctrine-bridge are installed, empty ones elsewhere): what omnibridge/symfony's UserProvider and the entity user
 * provider of a site's security.yaml ask, the same answers; the password written by the unit of work's flush.
 *
 * @extends EloquentEntities<User>
 */
class UserRepository extends EloquentEntities implements SecurityUserLoader, SecurityUserProvider, Users
{
    public function supportsClass(string $class): bool
    {
        return is_a($class, \App\Entity\User::class, true);
    }

    /** @return \Symfony\Component\Security\Core\User\UserInterface */
    public function loadUserByIdentifier(string $identifier): \Symfony\Component\Security\Core\User\UserInterface
    {
        $user = $this->findOneByEmail($identifier);
        if (null === $user) {
            throw new \Symfony\Component\Security\Core\Exception\UserNotFoundException();
        }

        return $user;
    }

    public function refreshUser(\Symfony\Component\Security\Core\User\UserInterface $user): \Symfony\Component\Security\Core\User\UserInterface
    {
        $user = $this->cacheOneByEmail($user->getEmail());
        if (!$user instanceof \App\Entity\User) {
            throw new \Symfony\Component\Security\Core\Exception\UnsupportedUserException(sprintf('Invalid user class "%s".', get_debug_type($user)));
        }

        if ($user->isKicked()) {
            throw new \Symfony\Component\Security\Core\Exception\UnsupportedUserException('You have been kicked..');
        }

        return $user;
    }

    public function upgradePassword(\Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        $user->setPassword($newHashedPassword);
        $this->unitOfWork()->flush();
    }

    public function oneByUsername(string $username, bool $cached = true): ?User
    {
        return $cached ? $this->cacheOneByUsername($username) : $this->findOneByUsername($username);
    }

    public function oneByEmail(?string $email): ?User
    {
        return $this->findOneByEmail($email);
    }

    public function byIds(array $ids): array
    {
        return $this->cacheById($ids)->getResult();
    }

    public function activeWithin(int $seconds, ?int $exceptId = null): array
    {
        return (null !== $exceptId ? $this->findByIdNotEqualToAndActiveAtYoungerThan($exceptId, $seconds) : $this->findByActiveAtYoungerThan($seconds))->getResult();
    }

    public function oneById(int $id): ?User
    {
        return $this->cacheOneById($id);
    }

    public function byRoles(array|string $roles): array
    {
        return $this->cacheByRoles($roles)->getResult();
    }

    public function byUsernameContaining(string $term, int $limit = 50): array
    {
        if (!isset($this->entityMap()->entity->fields['username'])) {
            return [];
        }

        // LOWER(username) LIKE %term%, the term's own % and _ taken literally.
        return $this->findByInsensitivePartialUsername('%'.addcslashes(mb_strtolower($term), '%_').'%', [], ['username' => 'ASC'], $limit)->getResult();
    }

    public function findActiveAdmins(int $onlineDelay, ?int $excludeId = null): array
    {
        $threshold = new \DateTime($onlineDelay.' seconds ago');

        // activeAt > threshold AND (roles LIKE %ROLE_ADMIN% OR roles LIKE %ROLE_SUPERADMIN%) [AND id != excludeId]
        $criteria = [Group::by('PartialRolesOrPartialRoles', '%ROLE_ADMIN%', '%ROLE_SUPERADMIN%')];

        return (null === $excludeId
            ? $this->findByActiveAtGreaterThan($threshold, $criteria, ['activeAt' => 'DESC'])
            : $this->findByActiveAtGreaterThanAndIdNotEqualTo($threshold, $excludeId, $criteria, ['activeAt' => 'DESC'])
        )->getResult();
    }

    public function cacheByInsensitiveIdentifier($identifier, array $fields = [])
    {
        return $this->findByInsensitiveIdentifier($identifier, $fields, true);
    }

    public function findByInsensitiveIdentifier($identifier, array $fields = [], $cacheable = false)
    {
        if (empty($fields)) {
            $fields[] = $this->getClassName()::getUserIdentifierField();
        }

        // LOWER(<field>) LIKE %identifier% on any of the fields: a finder of the language, one criterion per field.
        $identifier = '%'.strtolower(preg_replace('/%$/', '', $identifier)).'%';
        $finder = ($cacheable ? 'cacheBy' : 'findBy').implode('Or', array_map(static fn (string $field) => 'InsensitivePartial'.ucfirst($field), $fields));

        return $this->{$finder}(...array_fill(0, \count($fields), $identifier));
    }
}
