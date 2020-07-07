<?php

namespace JMS\JobQueueBundle\Entity\Type;

use Doctrine\DBAL\Types\ObjectType;

class SafeObjectType extends ObjectType
{
    public function getSQLDeclaration(array $fieldDeclaration, \Doctrine\DBAL\Platforms\AbstractPlatform $platform)
    {
        return $platform->getBlobTypeDeclarationSQL($fieldDeclaration);
    }
    
    public function getBindingType()
    {
        return \PDO::PARAM_LOB;
    }

    public function getName()
    {
        return 'jms_job_safe_object';
    }
}
