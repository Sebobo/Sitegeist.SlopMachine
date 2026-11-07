<?php

declare(strict_types=1);

namespace Neos\Flow\Persistence\Doctrine\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261107120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds the database schema for the instructions the administrators write for the agents';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            $this->connection->getDatabasePlatform()->getName() !== 'mysql',
            'Migration can only be executed safely on MySQL / MariaDB.'
        );

        $this->addSql('CREATE TABLE sitegeist_slopmachine_domain_model_instruction (persistence_object_identifier VARCHAR(40) NOT NULL, title VARCHAR(255) NOT NULL, instructions LONGTEXT NOT NULL, position INTEGER NOT NULL, PRIMARY KEY(persistence_object_identifier)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            $this->connection->getDatabasePlatform()->getName() !== 'mysql',
            'Migration can only be executed safely on MySQL / MariaDB.'
        );

        $this->addSql('DROP TABLE sitegeist_slopmachine_domain_model_instruction');
    }
}
