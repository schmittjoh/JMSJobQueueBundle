<?php

namespace JMS\JobQueueBundle\Entity\Type;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\ObjectType;

class SafeObjectType extends ObjectType
{
    public function getSQLDeclaration(array $fieldDeclaration, AbstractPlatform $platform)
    {
        return $platform->getBlobTypeDeclarationSQL($fieldDeclaration);
    }

    public function getName()
    {
        return 'jms_job_safe_object';
    }
}