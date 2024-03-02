<?php

namespace App\Handler\Http;

use Doctrine\ORM\EntityManager;
use Psr\Http\Server\RequestHandlerInterface;
use Zend\EventManager\EventManagerInterface;

abstract class AbstractRequestHandler implements RequestHandlerInterface
{
    public $events;
    public $em;

    /**
     * AbstractRequestHandler constructor.
     * @param EventManagerInterface $events
     * @param EntityManager $em
     */
    public function __construct(EventManagerInterface $events, EntityManager $em)
    {
        $this->events = $events;
        $this->em = $em;
    }

    /**
     * @param $eventName
     * @param null $target
     * @param array $argv
     */
    public function trigger($eventName, $target = null, $argv = [])
    {
        $this->events->trigger($eventName, $target, $argv);
    }
}
