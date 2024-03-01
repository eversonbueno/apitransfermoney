<?php

namespace App\Handler\Http;

use Doctrine\ORM\EntityManager;
use mysql_xdevapi\Exception;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Zend\Diactoros\Response\JsonResponse;
use Zend\EventManager\EventManagerInterface;
use App\Handler\Http\AbstractRequestHandler;

class AliveHandler extends AbstractRequestHandler
{
    public function __construct(EventManagerInterface $events, EntityManager $em)
    {
        parent::__construct($events, $em);
    }

    public function handle(ServerRequestInterface $request) : ResponseInterface
    {
        $message = [
            'database_is_connected' => $this->em->getConnection()->connect()
        ];

        return new JsonResponse($message);
    }
}
