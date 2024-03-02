<?php

namespace TransferMoney\Api\App\src\Handler\Http\Skeleton;

use App\Entity\Warehouse;
use App\Service\SkeletonService;
use Doctrine\ORM\EntityManager;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Zend\Diactoros\Response\JsonResponse;
use Zend\EventManager\EventManagerInterface;
use TransferMoney2\Api\src\Handler\Http\AbstractRequestHandler;

class SkeletonHandler extends AbstractRequestHandler
{
    public $skeletonService;

    public function __construct(EventManagerInterface $events, EntityManager $em, SkeletonService $skeletonService)
    {
        $this->skeletonService = $skeletonService;
        parent::__construct($events, $em);
    }

    public function handle(ServerRequestInterface $request) : ResponseInterface
    {
        $params = [
            'success' => true,
            'message' => 'Skeleton command ready'
        ];

        $this->trigger('skeleton_command', $this, $params);

        $message = $this->skeletonService->getMessage();
        $message['doctrine_is_connected'] = $this->em->getConnection()->connect();
        $message['warehouses'] = $this->em->getRepository(Warehouse::class)->fetchPairs();

        return new JsonResponse($message);
    }
}
