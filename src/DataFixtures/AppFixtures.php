<?php

namespace App\DataFixtures;

use App\Entity\User;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class AppFixtures extends Fixture
{
    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        for ($i = 1; $i <= 50; $i++) {
            $login = sprintf('u%03d', $i);

            $user = (new User())
                ->setLogin($login)
                ->setPhone(sprintf('099%07d', $i))
                ->setRoles($i === 1 ? ['ROLE_ROOT'] : ['ROLE_USER']);

            $user->setPasswordHash($this->passwordHasher->hashPassword($user, $login . $login));

            $manager->persist($user);
        }

        $manager->flush();
    }
}
