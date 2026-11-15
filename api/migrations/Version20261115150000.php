<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261115150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add case/whitespace-normalized ingredient uniqueness without changing IDs or display names';
    }

    public function up(Schema $schema): void
    {
        // Preflight before scheduling any schema changes. Never silently merge
        // ingredients: different IDs may already be referenced by private recipes.
        $keys = [];
        $updates = [];
        foreach ($this->connection->fetchAllAssociative('SELECT id, name FROM ingredient ORDER BY id') as $row) {
            // Frozen normalization algorithm, independent of future entity changes.
            $name = trim(preg_replace('/\s+/u', ' ', $row['name']) ?? '', ' ');
            $this->abortIf($name === '' || mb_strlen($name) > 150 || (bool) preg_match('/[\p{Cc}\p{Cf}]/u', $name),
                'Invalid existing ingredient name at ID '.$row['id'].'; resolve manually before migration.');
            $key = mb_strtolower($name, 'UTF-8');
            $this->abortIf(isset($keys[$key]), 'Duplicate ingredient names; resolve conflicting IDs manually before migration: '.($keys[$key] ?? '').', '.$row['id']);
            $keys[$key] = $row['id'];
            $updates[] = [$key, $row['id']];
        }
        $this->addSql('ALTER TABLE ingredient ADD canonical_name VARCHAR(450) DEFAULT NULL');
        foreach ($updates as $parameters) {
            $this->addSql('UPDATE ingredient SET canonical_name = ? WHERE id = ?', $parameters);
        }
        $this->addSql('ALTER TABLE ingredient ALTER COLUMN canonical_name SET NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_INGREDIENT_CANONICAL_NAME ON ingredient (canonical_name)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_INGREDIENT_CANONICAL_NAME');
        $this->addSql('ALTER TABLE ingredient DROP canonical_name');
    }
}
