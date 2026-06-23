<?php

namespace App\Repository;

use App\Entity\ApiToken;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ApiToken>
 */
class ApiTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ApiToken::class);
    }

    public function findOneActiveByPlainToken(string $plainToken): ?ApiToken
    {
        $tokenHash = hash('sha256', $plainToken);
        $now = new \DateTimeImmutable();

        return $this->createQueryBuilder('apiToken')
            ->andWhere('apiToken.tokenHash = :tokenHash')
            ->andWhere('apiToken.revokedAt IS NULL')
            ->andWhere('apiToken.expiresAt IS NULL OR apiToken.expiresAt > :now')
            ->setParameter('tokenHash', $tokenHash)
            ->setParameter('now', $now)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
