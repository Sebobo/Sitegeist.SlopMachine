<?php

declare(strict_types=1);

namespace Neos\Flow\Persistence\Doctrine\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260714101424 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds / rremoves the database schema for agent assignments';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            $this->connection->getDatabasePlatform()->getName() !== 'mysql',
            'Migration can only be executed safely on MySQL / MariaDB.'
        );

        $this->addSql('CREATE TABLE sitegeist_slopmachine_domain_model_agentassignment (agentid VARCHAR(255) NOT NULL, user VARCHAR(40) DEFAULT NULL, INDEX IDX_D8527DD48D93D649 (user), PRIMARY KEY(agentid)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE sitegeist_slopmachine_domain_model_agentassignment ADD CONSTRAINT FK_D8527DD48D93D649 FOREIGN KEY (user) REFERENCES neos_neos_domain_model_user (persistence_object_identifier)');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            $this->connection->getDatabasePlatform()->getName() !== 'mysql',
            'Migration can only be executed safely on MySQL / MariaDB.'
        );
        $this->addSql('ALTER TABLE sitegeist_slopmachine_domain_model_agentassignment DROP FOREIGN KEY FK_D8527DD48D93D649');
        $this->addSql('DROP TABLE sitegeist_slopmachine_domain_model_agentassignment');
    }
}
