<?php

namespace JMS\JobQueueBundle\Twig;

class JobQueueExtension extends \Twig_Extension
{
    private $linkGenerators = array();

    public function __construct(array $generators = array())
    {
        $this->linkGenerators = $generators;
    }

    public function getTests()
    {
        return array(
            'jms_job_queue_linkable' => new \Twig_Test_Method($this, 'isLinkable'),
        );
    }

    public function getFunctions()
    {
        return array(
            'jms_job_queue_path' => new \Twig_Function_Method($this, 'generatePath', array('is_safe' => array('html' => true))),
        );
    }

    public function getFilters()
    {
        return array(
            'jms_job_queue_linkname' => new \Twig_Filter_Method($this, 'getLinkname'),
            'jms_job_queue_args' => new \Twig_Filter_Method($this, 'formatArgs'),
        );
    }

    public function formatArgs(array $args, $maxLength = 60)
    {
        $str = '';
        $first = true;
        foreach ($args as $arg) {
            $argLength = strlen($arg);

            if ( ! $first) {
                $str .= ' ';
            }
            $first = false;

            if (strlen($str) + $argLength > $maxLength) {
                $str .= substr($arg, 0, $maxLength - strlen($str) - 4).'...';
                break;
            }

            $str .= $arg;
        }

        return $str;
    }

    public function isLinkable($entity)
    {
        foreach ($this->linkGenerators as $generator) {
            if ($generator->supports($entity)) {
                return true;
            }
        }

        return false;
    }

    public function generatePath($entity)
    {
        foreach ($this->linkGenerators as $generator) {
            if ($generator->supports($entity)) {
                return $generator->generate($entity);
            }
        }

        throw new \RuntimeException(sprintf('The entity "%s" has no link generator.', get_class($entity)));
    }

    public function getLinkname($entity)
    {
        foreach ($this->linkGenerators as $generator) {
            if ($generator->supports($entity)) {
                return $generator->getLinkname($entity);
            }
        }

        throw new \RuntimeException(sprintf('The entity "%s" has no link generator.', get_class($entity)));
    }

    public function getName()
    {
        return 'jms_job_queue';
    }
}