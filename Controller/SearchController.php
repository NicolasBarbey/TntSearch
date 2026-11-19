<?php

namespace TntSearch\Controller;

use Propel\Runtime\ActiveQuery\Criteria;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Thelia\Controller\Admin\BaseAdminController;
use Thelia\Core\HttpFoundation\Request;
use Thelia\Domain\Search\Document\SearchQuery;
use Thelia\Domain\Search\SearchService;
use Thelia\Model\BrandI18nQuery;
use Thelia\Model\BrandQuery;
use Thelia\Model\CategoryI18nQuery;
use Thelia\Model\CategoryQuery;
use Thelia\Model\ContentI18nQuery;
use Thelia\Model\ContentQuery;
use Thelia\Model\CustomerQuery;
use Thelia\Model\FolderI18nQuery;
use Thelia\Model\FolderQuery;
use Thelia\Model\OrderQuery;
use Thelia\Model\ProductI18nQuery;
use Thelia\Model\ProductQuery;
use Twig\Environment;
use TntSearch\Service\Provider\IndexationProvider;
use TntSearch\Service\Search;

#[Route("/admin/search", name: "front_search_")]
class SearchController extends BaseAdminController
{
    private const array CATALOGUE_FAMILIES = ['product', 'category', 'content', 'folder', 'brand'];

    private const int ROWS = 25;

    public function __construct(private readonly Environment $twig)
    {
    }

    #[Route("", name: "front_search_loop", methods: ["GET"])]
    public function searchAdminAction(
        Request $request,
        Search $searchService,
        IndexationProvider $indexationProvider,
        SearchService $catalogueSearch,
    ): Response {
        $searchTerm = (string) $request->query->get('search_term', '');
        $locale = $request->hasSession()
            ? $request->getSession()->getAdminLang()->getLocale()
            : (\Thelia\Model\LangQuery::create()->findOneByByDefault(true)?->getLocale() ?? 'en_US');

        $idsByType = [];

        if ('' !== $searchTerm) {
            $indexes = array_keys($indexationProvider->getIndexes());
            $results = $searchService->search($searchTerm, $indexes, $locale, 0, 100);

            foreach ($results as $type => $hits) {
                $ids = array_map(static fn ($hit) => $hit['id'] ?? $hit, $hits);
                $idsByType[strtolower((string) $type)] = array_values(array_filter($ids));
            }

            foreach (self::CATALOGUE_FAMILIES as $family) {
                $result = $catalogueSearch->search(new SearchQuery($searchTerm, $locale, [$family], visibleOnly: false, limit: self::ROWS));
                $idsByType[$family] = null === $result ? $this->titleMatches($family, $searchTerm, $locale) : $result->idsOf($family);
            }
        }

        return new Response(
            $this->twig->render('@TntSearchModule/backOffice/default-twig/TntSearch/search.html.twig', [
                'searchTerm' => $searchTerm,
                'customers' => $this->loadCustomers($idsByType['customer'] ?? []),
                'orders' => $this->loadOrders($idsByType['order'] ?? []),
                'categories' => $this->loadCategories($idsByType['category'] ?? [], $locale),
                'products' => $this->loadProducts($idsByType['product'] ?? [], $locale),
                'folders' => $this->loadFolders($idsByType['folder'] ?? [], $locale),
                'contents' => $this->loadContents($idsByType['content'] ?? [], $locale),
                'brands' => $this->loadBrands($idsByType['brand'] ?? [], $locale),
            ])
        );
    }

    /**
     * @return list<int>
     */
    private function titleMatches(string $family, string $term, string $locale): array
    {
        $query = match ($family) {
            'product' => ProductI18nQuery::create(),
            'category' => CategoryI18nQuery::create(),
            'content' => ContentI18nQuery::create(),
            'folder' => FolderI18nQuery::create(),
            'brand' => BrandI18nQuery::create(),
        };

        /** @var list<int|string> $ids */
        $ids = $query
            ->filterByLocale($locale)
            ->filterByTitle('%'.addcslashes($term, '%_\\').'%', Criteria::LIKE)
            ->limit(self::ROWS)
            ->select(['Id'])
            ->find()
            ->getData();

        return array_map(intval(...), $ids);
    }

    private function loadCustomers(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        $rows = [];
        foreach (CustomerQuery::create()->filterById($ids)->limit(25)->find() as $customer) {
            $rows[] = [
                'id' => $customer->getId(),
                'ref' => $customer->getRef(),
                'company' => $customer->getDefaultAddress()?->getCompany(),
                'firstname' => $customer->getFirstname(),
                'lastname' => $customer->getLastname(),
                'email' => $customer->getEmail(),
            ];
        }

        return $rows;
    }

    private function loadOrders(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        $rows = [];
        foreach (OrderQuery::create()->filterById($ids)->limit(25)->find() as $order) {
            $rows[] = [
                'id' => $order->getId(),
                'ref' => $order->getRef(),
                'invoice_ref' => $order->getInvoiceRef(),
                'delivery_ref' => $order->getDeliveryRef(),
                'transaction_ref' => $order->getTransactionRef(),
                'customer_id' => $order->getCustomerId(),
                'create_date' => $order->getCreatedAt(),
            ];
        }

        return $rows;
    }

    private function loadCategories(array $ids, string $locale): array
    {
        if ([] === $ids) {
            return [];
        }

        $rows = [];
        foreach (CategoryQuery::create()->filterById($ids)->limit(25)->find() as $category) {
            $category->setLocale($locale);
            $rows[] = [
                'id' => $category->getId(),
                'title' => $category->getTitle(),
                'visible' => $category->getVisible(),
            ];
        }

        return $rows;
    }

    private function loadProducts(array $ids, string $locale): array
    {
        if ([] === $ids) {
            return [];
        }

        $rows = [];
        foreach (ProductQuery::create()->filterById($ids)->limit(25)->find() as $product) {
            $product->setLocale($locale);
            $rows[] = [
                'id' => $product->getId(),
                'ref' => $product->getRef(),
                'title' => $product->getTitle(),
                'visible' => $product->getVisible(),
                'virtual' => $product->getVirtual(),
            ];
        }

        return $rows;
    }

    private function loadFolders(array $ids, string $locale): array
    {
        if ([] === $ids) {
            return [];
        }

        $rows = [];
        foreach (FolderQuery::create()->filterById($ids)->limit(25)->find() as $folder) {
            $folder->setLocale($locale);
            $rows[] = [
                'id' => $folder->getId(),
                'title' => $folder->getTitle(),
                'visible' => $folder->getVisible(),
            ];
        }

        return $rows;
    }

    private function loadContents(array $ids, string $locale): array
    {
        if ([] === $ids) {
            return [];
        }

        $rows = [];
        foreach (ContentQuery::create()->filterById($ids)->limit(25)->find() as $content) {
            $content->setLocale($locale);
            $rows[] = [
                'id' => $content->getId(),
                'title' => $content->getTitle(),
                'visible' => $content->getVisible(),
            ];
        }

        return $rows;
    }

    private function loadBrands(array $ids, string $locale): array
    {
        if ([] === $ids) {
            return [];
        }

        $rows = [];
        foreach (BrandQuery::create()->filterById($ids)->limit(25)->find() as $brand) {
            $brand->setLocale($locale);
            $rows[] = [
                'id' => $brand->getId(),
                'title' => $brand->getTitle(),
                'visible' => $brand->getVisible(),
            ];
        }

        return $rows;
    }
}
