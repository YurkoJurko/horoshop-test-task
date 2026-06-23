<?php

namespace App\Tests\Entity;

use App\Entity\ApiToken;
use App\Entity\User;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

class UserTest extends TestCase
{
    public function testUserImplementsSecurityInterfaces(): void
    {
        $user = new User();

        self::assertInstanceOf(UserInterface::class, $user);
        self::assertInstanceOf(PasswordAuthenticatedUserInterface::class, $user);
    }

    public function testStoresUserFields(): void
    {
        $user = (new User())
            ->setLogin('u001')
            ->setPhone('0990000001')
            ->setPasswordHash('hashed-password');

        self::assertSame('u001', $user->getLogin());
        self::assertSame('0990000001', $user->getPhone());
        self::assertSame('hashed-password', $user->getPasswordHash());
        self::assertSame('hashed-password', $user->getPassword());
        self::assertSame('u001', $user->getUserIdentifier());
    }

    public function testRolesAlwaysIncludeUserRole(): void
    {
        $user = new User();

        self::assertSame(['ROLE_USER'], $user->getRoles());
    }

    public function testNewUserStoresUserRoleByDefault(): void
    {
        $user = new User();
        $roles = new \ReflectionProperty(User::class, 'roles');

        self::assertSame(['ROLE_USER'], $roles->getValue($user));
    }

    public function testRolesAreUniqueAndPreserveRootRole(): void
    {
        $user = (new User())->setRoles(['ROLE_ROOT', 'ROLE_USER', 'ROLE_ROOT']);

        self::assertSame(['ROLE_ROOT', 'ROLE_USER'], $user->getRoles());
    }

    public function testApiTokensCanBeAddedAndRemoved(): void
    {
        $user = new User();
        $apiToken = new ApiToken();

        $user->addApiToken($apiToken);
        $user->addApiToken($apiToken);

        self::assertCount(1, $user->getApiTokens());
        self::assertTrue($user->getApiTokens()->contains($apiToken));
        self::assertSame($user, $apiToken->getUser());

        $user->removeApiToken($apiToken);

        self::assertCount(0, $user->getApiTokens());
    }
}
