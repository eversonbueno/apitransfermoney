<?php

namespace App\Handler\Http;

use App\Entity\Product;
use App\Entity\ProductVolumes;
use TransferMoney2\Api\src\Handler\Http\AbstractRequestHandler;
use App\Service\ProductService;
use App\Service\ProductVolumesService;
use App\Service\WarehouseService;
use App\Service\Wms\SeniorService;
use Doctrine\ORM\EntityManager;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Zend\Console\Adapter\AdapterInterface;
use Zend\Diactoros\Response\JsonResponse;
use Zend\EventManager\EventManagerInterface;
use ZF\Console\Route;

class WmsProductsHandler extends AbstractRequestHandler
{
    /**
     * @var WarehouseService
     */
    public $warehouseService;
    /**
     * @var ProductService
     */
    public $productService;

    /**
     * @var ProductVolumesService
     */
    public $productVolumesService;

    /**
     * @var SeniorService
     */
    public $wmsService;

    /**
     * WmsProductsHandler constructor.
     * @param EventManagerInterface $events
     * @param EntityManager $em
     * @param WarehouseService $warehouseService
     * @param ProductService $productService
     * @param ProductVolumesService $productVolumesService
     * @param SeniorService $wmsService
     */
    public function __construct(
        EventManagerInterface $events,
        EntityManager $em,
        $warehouseService,
        $productService,
        $productVolumesService,
        $wmsService
    ) {
        parent::__construct($events, $em);

        $this->warehouseService      = $warehouseService;
        $this->productService        = $productService;
        $this->productVolumesService = $productVolumesService;
        $this->wmsService            = $wmsService;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        \ini_set('memory_limit', '-1');
        set_time_limit(0);
        $message = [];
        try {
            $startDate = date('H:i:s');
            $startTime = microtime(true);
            $data      = \GuzzleHttp\json_decode($request->getBody(), true);
            $limit     = !empty($data['limit']) ? $data['limit'] : 1000;
            $orderby   = !empty($data['order']) ? $data['order'] : 'ASC';
            $type      = !empty($data['type']) ? $data['type'] : 'produtos';

            $statusWms = Product::PRODUCT_STATUS_WMS_SENDED;
            if ($type === 'kit') {
                $statusWms = Product::PRODUCT_STATUS_WMS_PACKAGING_SUCCESS;
            }

            $allProducts = $this->productService->findPendingProducts($statusWms, $limit, $orderby);
            if (empty($allProducts)) {
                throw new \Exception('Nenhum produto encontrado');
            }

            $productsChunk = array_chunk($allProducts, $limit);

            foreach ($productsChunk as $page => $products) {
                $sendProducts     = [];
                $sendPackagings   = [];
                $sendMultiVolumes = [];
                $productIds       = [];
                foreach ($products as $product) {
                    $sendVolumes = $this->productVolumesService->findProductVolumes($product->getId());
                    if (empty($sendVolumes)) {
                        continue;
                    }

                    /** @var ProductVolumes $mainProductVolumes */
                    $mainProductVolumes = reset($sendVolumes);

                    // product main
                    $payloadProductMain = $this->createProduct($mainProductVolumes, $sendVolumes);

                    $sendProducts[]   = $payloadProductMain['product'];
                    $sendPackagings[] = $payloadProductMain['packaging'];
                    if (count($sendVolumes) > 1) {
                        $payloadVolumes   = $this->createProductMultiVolumes($sendVolumes);
                        $sendProducts     = array_merge($sendProducts, $payloadVolumes['product']);
                        $sendPackagings   = array_merge($sendPackagings, $payloadVolumes['packaging']);
                        $sendMultiVolumes = array_merge($sendMultiVolumes, $payloadVolumes['kit']);
                    }
                    $productIds[] = $product->getId();
                    $message[]    = count($productIds) . '/' . count($products) . ' - Produto: ' . $product->getId() . ' - Sku: ' . $product->getSku();
                }

                $statusId = '';
                if ($type != 'kit') {
                    if ($sendProducts) {
                        $message[]     = "Enviando Produtos...";
                        $resultProduct = $this->wmsService->sendProducts($sendProducts, 'create-product-api');
                        if (empty($resultProduct['protocol'])) {
                            throw new \Exception('Error send products for wms', 400);
                        }
                        $message[] = "Protocolo produtos: " . $resultProduct['protocol'];
                        $statusId  = Product::PRODUCT_STATUS_WMS_SUCCESS;
                        $this->productService->saveProtocolProducts($productIds, $resultProduct['protocol'], $statusId);
                    }

                    if ($sendPackagings) {
                        $message[]       = "Enviando Embalagens...";
                        $resultPackaging = $this->wmsService->sendProductsPackaging($sendPackagings);
                        if (empty($resultPackaging['protocol'])) {
                            throw new \Exception('Error send packaging for wms', 400);
                        }
                        $message[] = "Protocolo embalagem: " . $resultPackaging['protocol'];
                        $statusId  = empty($sendMultiVolumes) ? Product::PRODUCT_STATUS_WMS_MULTIVOLUMES_SUCCESS : Product::PRODUCT_STATUS_WMS_PACKAGING_SUCCESS;
                        $this->productService->saveProtocolProducts($productIds, $resultPackaging['protocol'],
                            $statusId);
                    }
                } else {
                    if ($sendMultiVolumes) {
                        $message[] = "Enviando Kits...";
                        $resultKit = $this->wmsService->sendMultiVolumes($sendMultiVolumes);

                        if (empty($resultKit['protocol'])) {
                            throw new \Exception('Error send Kit for wms', 400);
                        }
                        $message[] = "Protocolo Kits: " . $resultKit['protocol'];
                        $statusId  = Product::PRODUCT_STATUS_WMS_MULTIVOLUMES_SUCCESS;
                        $this->productService->saveProtocolProducts($productIds, $resultKit['protocol'], $statusId);
                    }
                }

                $message[] = "Total: " . count($allProducts) . '/' . count($products);
            }

            $endTime   = microtime(true);
            $diff      = round($endTime - $startTime);
            $minutes   = floor($diff / 60);
            $seconds   = $diff % 60;
            $checkKit  = ($statusId == Product::PRODUCT_STATUS_WMS_PACKAGING_SUCCESS) ? '' : '( TEM KIT !!!! )';
            $message[] = 'acabou! ' . $minutes . 'm ' . $seconds . "s " . $checkKit;
            return new JsonResponse(['message' => $message, 'error' => false]);
        } catch (\Exception $e) {
            return new JsonResponse(['message' => $e->getMessage(), 'error' => true]);
        }
    }

    /**
     * @param ProductVolumes $sendVolumesProduct
     * @param array $sendVolumes
     * @param bool $newRegister
     * @return mixed
     * @throws \Exception
     */
    public function createProduct(ProductVolumes $sendVolumesProduct, array $sendVolumes)
    {
        /** @var Product $sendProduct */
        $sendProduct = $sendVolumesProduct->getProduct();

        $result = [
            'product'   => $this->productService->formatPayloadProduct($sendProduct, $sendVolumes),
            'packaging' => $this->productVolumesService->formatPayloadProductPackaging($sendVolumesProduct),
        ];

        return $result;
    }

    /**
     * @param array $sendVolumes
     * @return array
     * @throws \Exception
     */
    public function createProductMultiVolumes(array $sendVolumes)
    {
        $productWms   = [];
        $packagingWms = [];
        $kitWms       = [];
        /** @var ProductVolumes $volumes */
        foreach ($sendVolumes as $volumes) {
            /** @var Product $sendProduct */
            $sendProduct = $volumes->getProduct();

            if ($volumes->getCode() != $volumes->getProduct()->getSku()) {
                $productWms[]   = $this->productService->formatPayloadProductMultiVolumes($sendProduct, $volumes);
                $packagingWms[] = $this->productVolumesService->formatPayloadProductPackaging($volumes);
                $kitWms[]       = $this->productVolumesService->formatPayloadProductMultiVolumes($volumes);
            }
        }
        return [
            'product'   => $productWms,
            'packaging' => $packagingWms,
            'kit'       => $kitWms,
        ];
    }
}
