<?php

namespace JMS\JobQueueBundle\Tests\Functional\TestBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity
 * @ORM\Table(name = "trains")
 * @ORM\ChangeTrackingPolicy("DEFERRED_EXPLICIT")
 */
class Train
{
    /** @ORM\Id @ORM\GeneratedValue(strategy = "AUTO") @ORM\Column(type = "integer") */
    public $id;
}