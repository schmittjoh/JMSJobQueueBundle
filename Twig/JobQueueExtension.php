<?php

namespace JMS\JobQueueBundle\Twig;

use RuntimeException;
use Twig_Extension;

class JobQueueExtension extends Twig_Extension
{
    private $linkGenerators = [];

    public function __construct(array $generators = [])
    {
        $this->linkGenerators = $generators;
    }

    public function getTests()
    {
        return [
            new \Twig_SimpleTest('jms_job_queue_linkable', [$this, 'isLinkable'])
        ];
    }

    public function getFunctions()
    {
        return [
            new \Twig_SimpleFunction('jms_job_queue_path', [$this, 'generatePath'], ['is_safe' => ['html' => true]])
        ];
    }

    public function getFilters()
    {
        return [
            new \Twig_SimpleFilter('jms_job_queue_linkname', [$this, 'getLinkname']),
            new \Twig_SimpleFilter('jms_job_queue_args', [$this, 'formatArgs'])
        ];
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

            $str .= escapeshellarg($arg);
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

        throw new RuntimeException(sprintf('The entity "%s" has no link generator.', get_class($entity)));
    }

    public function getLinkname($entity)
    {
        foreach ($this->linkGenerators as $generator) {
            if ($generator->supports($entity)) {
                return $generator->getLinkname($entity);
            }
        }

        throw new RuntimeException(sprintf('The entity "%s" has no link generator.', get_class($entity)));
    }

    public function getName()
    {
        return 'jms_job_queue';
    }
}