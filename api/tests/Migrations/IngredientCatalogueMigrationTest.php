<?php

namespace App\Tests\Migrations;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\AbortMigration;
use Doctrine\ORM\EntityManagerInterface;
use DoctrineMigrations\Version20261115150000;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

require_once dirname(__DIR__, 2).'/migrations/Version20261115150000.php';

final class IngredientCatalogueMigrationTest extends KernelTestCase
{
    private ?Connection $db = null;

    protected function setUp(): void
    {
        parent::setUp();
        static::bootKernel();
        $this->db = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
        self::assertSame('culinary_compass_test', $this->db->fetchOne('SELECT current_database()'));
        // PostgreSQL DDL is transactional: restore the original schema and data
        // after every scenario, including failed assertions or aborted migrations.
        $this->db->beginTransaction();
        $this->execute($this->migration(), 'down');
    }

    protected function tearDown(): void
    {
        try {
            if ($this->db?->isTransactionActive()) {
                $this->db->rollBack();
            }
        } finally {
            parent::tearDown();
        }
    }

    public function testBackfillAndRollbackPreserveIngredientIdsAndDisplayNames(): void
    {
        $suffix = bin2hex(random_bytes(8));
        $name = "  Épinards\t Rice ".$suffix.'  ';
        $id = $this->db->fetchOne('INSERT INTO ingredient (name) VALUES (?) RETURNING id', [$name]);
        $this->execute($this->migration(), 'up');
        $row = $this->db->fetchAssociative('SELECT id, name, canonical_name FROM ingredient WHERE id = ?', [$id]);
        self::assertSame((int) $id, $row['id']);
        self::assertSame($name, $row['name']);
        self::assertSame('épinards rice '.$suffix, $row['canonical_name']);
        $this->execute($this->migration(), 'down');
        self::assertSame($name, $this->db->fetchOne('SELECT name FROM ingredient WHERE id = ?', [$id]));
        self::assertArrayNotHasKey('canonical_name', $this->db->createSchemaManager()->listTableColumns('ingredient'));
        $this->execute($this->migration(), 'up');
        self::assertSame($name, $this->db->fetchOne('SELECT name FROM ingredient WHERE id = ?', [$id]));
    }

    public function testDuplicatePreflightAbortsBeforeSchemaOrDataChanges(): void
    {
        $suffix = bin2hex(random_bytes(8));
        $this->db->insert('ingredient', ['name' => 'Rice '.$suffix]);
        $this->db->insert('ingredient', ['name' => " RICE\t ".$suffix.' ']);
        $before = $this->db->fetchAllAssociative('SELECT id, name FROM ingredient ORDER BY id');
        $migration = $this->migration();
        try {
            $migration->up(new Schema());
            self::fail('Duplicate existing names should block the migration');
        } catch (AbortMigration $exception) {
            self::assertStringContainsString('Duplicate ingredient names', $exception->getMessage());
        }
        self::assertSame([], $migration->getSql());
        self::assertSame($before, $this->db->fetchAllAssociative('SELECT id, name FROM ingredient ORDER BY id'));
    }

    public function testInvalidExistingNameBlocksTheMigration(): void
    {
        $this->db->insert('ingredient', ['name' => ' ']);
        $migration = $this->migration();
        $this->expectException(AbortMigration::class);
        $migration->up(new Schema());
    }

    private function migration(): Version20261115150000
    {
        return new Version20261115150000($this->db, new NullLogger());
    }

    private function execute(AbstractMigration $migration, string $direction): void
    {
        $migration->$direction(new Schema());
        foreach ($migration->getSql() as $query) {
            $this->db->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }
}
