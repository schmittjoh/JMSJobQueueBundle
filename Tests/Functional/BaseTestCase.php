<?php

namespace JMS\JobQueueBundle\Tests\Functional;

use Doctrine\ORM\EntityManager;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class BaseTestCase extends WebTestCase
{
    static protected function createKernel(array $options = array())
    {
        $config = isset($options['config']) ? $options['config'] : 'default.yml';

        return new AppKernel($config);
    }

    protected final function importDatabaseSchema()
    {
        foreach (self::$kernel->getContainer()->get('doctrine')->getManagers() as $em) {
            $this->importSchemaForEm($em);
        }
    }

    private function importSchemaForEm(EntityManager $em)
    {
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        if (!empty($metadata)) {
            $schemaTool = new \Doctrine\ORM\Tools\SchemaTool($em);
            $schemaTool->createSchema($metadata);
        }
    }
}