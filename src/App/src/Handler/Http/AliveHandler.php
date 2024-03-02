<?php

namespace TransferMoney2\Api\src\Handler\Http;

use App\Handler\Http\AbstractRequestHandler;
use Doctrine\ORM\EntityManager;
//use mysql_xdevapi\Exception;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Zend\Diactoros\Response\JsonResponse;
use Zend\EventManager\EventManagerInterface;

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
