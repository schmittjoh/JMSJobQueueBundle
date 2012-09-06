<?php

namespace JMS\JobQueueBundle\Tests\Functional;

use Doctrine\ORM\Tools\SchemaValidator;

class SchemaTest extends BaseTestCase
{
    public function testSchemaIsValid()
    {
        $this->createClient();
        
        $validator = new SchemaValidator(self::$kernel->getContainer()->get('doctrine.orm.entity_manager'));
        $errors = $validator->validateMapping();

        $this->assertEmpty($errors, "Validation errors found: \n\n".var_export($errors, true));
    }
}