<?php

/**
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; under version 2
 * of the License (non-upgradable).
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
 *
 * Copyright (c) 2023  (original work) Open Assessment Technologies SA;
 */

declare(strict_types=1);

namespace oat\taoQtiTest\migrations;

use common_ext_Extension as Extension;
use common_ext_ExtensionException as ExtensionException;
use common_ext_ExtensionsManager as ExtensionsManager;
use Doctrine\DBAL\Schema\Schema;
use oat\tao\scripts\tools\migrations\AbstractMigration;

final class Version202311291155452260_taoQtiTest extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Configure default `xmlEditor` value for the new config format.';
    }

    public function up(Schema $schema): void
    {
        $oldXmlConfigKey = 'XmlEditor';
        $newXmlConfigKey = 'xmlEditor';
        $extension = $this->getExtension();
        $extension->unsetConfig($oldXmlConfigKey);
        $extension->setConfig($newXmlConfigKey, ['is_locked' => true]);
    }

    public function down(Schema $schema): void
    {
        $oldXmlConfigKey = 'XmlEditor';
        $newXmlConfigKey = 'xmlEditor';
        $extension = $this->getExtension();
        $extension->unsetConfig($newXmlConfigKey);
        $extension->setConfig($oldXmlConfigKey, ['is_locked' => false]);
    }

    private function getExtension(): Extension
    {
        return $this->getServiceLocator()
            ->getContainer()
            ->get(ExtensionsManager::SERVICE_ID)
            ->getExtensionById('taoQtiTest');
    }
}
