<?php

namespace JMS\JobQueueBundle\Tests\Functional\TestBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity
 * @ORM\Table(name = "wagons")
 * @ORM\ChangeTrackingPolicy("DEFERRED_EXPLICIT")
 */
class Wagon
{
    /** @ORM\Id @ORM\GeneratedValue(strategy = "AUTO") @ORM\Column(type = "integer") */
    public $id;

    /** @ORM\ManyToOne(targetEntity = "Train") */
    public $train;

    /** @ORM\Column(type = "string") */
    public $state = 'new';
}