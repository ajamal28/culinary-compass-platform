<?php

namespace App\Repository;

use App\Entity\Ingredient;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Ingredient>
 */
class IngredientRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Ingredient::class);
    }

    /** @return list<array{id: int, name: string}> */
    public function search(string $query, int $limit): array
    {
        // Literal substring matching: user % and _ are not SQL wildcards.
        $term = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($query, 'UTF-8'));

        return $this->createQueryBuilder('i')
            ->select('i.id', 'i.name')
            ->where("i.canonicalName LIKE :term ESCAPE '!'")
            ->setParameter('term', '%'.$term.'%')
            ->orderBy('i.canonicalName', 'ASC')
            ->addOrderBy('i.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getArrayResult();
    }
}
