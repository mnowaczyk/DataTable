<?php
namespace Jasuwienas\DataTableBundle\Service;

use Jasuwienas\DataTableBundle\Repository\Interfaces\DataTableListInterface as EntityRepository;
use Symfony\Component\Templating\EngineInterface as Templating;
use Exception;
use Symfony\Component\HttpFoundation\ParameterBag;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Symfony\Component\Translation\TranslatorInterface;

class DataTableListService {

    const ERROR_NO_ELEMENT_RENDER_INFO = 'data_table.error.no_element_render_info';

    /**
     * @var int $draw
     */
    private $draw = 0;

    /**
     * @var int $start
     */
    private $start = 0;

    /**
     * @var int $perPage
     */
    private $perPage = 0;

    /**
     * query results
     *
     * @var array $list
     */
    protected $list = [];

    /**
     * @var int $recordsTotal
     */
    private $recordsTotal = 0;

    /**
     * @var int $recordsFiltered
     */
    private $recordsFiltered = 0;

    /**
     * @var QueryBuilder $queryBuilder
     */
    protected $queryBuilder;

    /**
     * @var array $order
     */
    private $order = [];

    /**
     * @var array $filters
     */
    protected $filters = [];

    /**
     * @var array $columns
     */
    private $columns = [];

    /**
     * @var EntityRepository $repository
     */
    private $repository;

    /**
     * @var Templating $templating
     */
    private $templating;

    public function __construct(EntityRepository $repository, Templating $templating, TranslatorInterface $translator) {
        $this->repository = $repository;
        $this->templating = $templating;
        $this->translator = $translator;
    }

    public function get(ParameterBag $query) {
        $this->initializeData($query);
        $this->runQuery();
        return $this->getResults();
    }

    /**
     * @param ParameterBag $query
     */
    private function initializeData($query) {
        $this->start = $query->get('start', 0);
        $this->perPage =  $query->get('length', 0);
        $this->draw = $query->get('draw', 0);
        $this->filters = $query->get('where', []);
        $this->order = $query->get('order', []);
        $this->columns = $query->get('columns', []);
    }

    private function runQuery() {
        $this->initializeQueryBuilder();
        $this->applyFilters();
        $this->applyOrders();
        $this->queryBuilder
            ->setFirstResult($this->start)
            ->setMaxResults($this->perPage)
        ;
        $list = new Paginator($this->queryBuilder, $fetchJoin = true);
        $queryBuilder = $this->repository->createDataTableQueryBuilder($this->getEntityName());

        try {
            $this->recordsTotal = (int)$queryBuilder->select('COUNT(' . $this->getEntityName() . ')')
                ->getQuery()
                ->getSingleScalarResult();
        } catch(Exception $e) {
            $this->recordsTotal = 0;
        }

        foreach($list as $element) {
            $this->list[] = $this->formatElement($element);
            $children = $this->getElementsChildren($element);
            foreach($children as $child) {
                $this->list[] = $this->formatElement($child);
            }
        }
        $this->recordsFiltered = $list->count();
    }

    protected function initializeQueryBuilder() {
        $this->queryBuilder = $this->repository->createDataTableQueryBuilder($this->getEntityName());
    }

    protected function applyFilters() {
        foreach($this->filters as $column => $value) {
            $this->filterBy($column, $value);
        }
    }

    protected function applyOrders() {
        $repositoryColumns = $this->repository->getDataTableColumns();
        foreach($this->order as $orderData) {
            if(!isset($orderData['column']) || !isset($orderData['dir']) || !isset($this->columns[$orderData['column']]) || !$this->columns[$orderData['column']]['orderable']) {
                continue;
            }
            $this->orderBy($repositoryColumns[$this->columns[$orderData['column']]['data']], $orderData['dir']);
        }
    }

    protected function filterBy($columnName, $value) {
        $column = $this->parseToQueryBuilderFormat($columnName);
        $rootColumn = explode('.', $columnName)[0];
        $rootEntity = $this->queryBuilder->getRootEntities()[0];
        $metadata = $this->queryBuilder->getEntityManager()->getClassMetadata($rootEntity);
        if (empty($metadata->fieldMappings[$rootColumn])){
            return;
        }
        $paramName = str_replace('.', '_', $column);
        if(is_array($value)) {
            if(!array_key_exists('value', $value)  || !isset($value['comparision'])) {
                return;
            }
            if($value['comparision'] === 'like') {
                $this->queryBuilder->andWhere($column .' like :'.$paramName);
                $this->queryBuilder->setParameter($paramName, '%'.$value['value'].'%');
                return;
            }
            if($value['comparision'] === 'not') {

                if($value['value'] === null) {
                    $this->queryBuilder->andWhere($column .' is not null');
                } else {
                    $this->queryBuilder->andWhere($column .' != :'.$paramName);
                    $this->queryBuilder->setParameter($paramName, '%'.$value['value'].'%');
                }

                return;
            }
        }
        if($value === null) {
            $this->queryBuilder->andWhere($column .' IS NULL');
            return;
        }
        $this->queryBuilder->andWhere($column .' = :'.$paramName);
        $this->queryBuilder->setParameter($paramName, $value);
    }

    protected function orderBy($columnName, $direction) {
        if($columnName === 'edit') {
            $this->queryBuilder->addOrderBy( $this->getEntityName() .'.'.'id', $direction);
            return;
        }
        $this->queryBuilder->addOrderBy($this->parseToQueryBuilderFormat($columnName), $direction);
    }

    protected function parseToQueryBuilderFormat($columnName) {
        if($columnName === 'select') {
            $columnName = 'id';
        }
        $nameArray = explode('.', $columnName);
        $entityName = isset($nameArray[count($nameArray) - 2]) ? $nameArray[count($nameArray) - 2] : $this->getEntityName();
        $columnName = isset($nameArray[count($nameArray) - 1]) ? $nameArray[count($nameArray) - 1] : $columnName;
        return $entityName . '.' . $columnName;
    }

    /**
     * @param $element
     * @return array
     */
    protected function getElementsChildren($element) {
        return (method_exists($element, 'getDataTableChildren')) ? $element->getDataTableChildren() : [];
    }

    /**
     * @param $element
     * @return array|string
     */
    protected function formatElement($element) {
        $result = [];
        foreach($this->repository->getDataTableColumns() as $column) {
            $result[] = $this->formatColumn($element, $column);
        }
        return $result;
    }

    /**
     * @param $element
     * @param $columnName
     * @return string
     * @throws Exception
     */
    private function formatColumn($element, $columnName) {
        $nameArray = explode('.', $columnName);
        $columnName = $nameArray[0];
        $view = $this->repository->getTemplatesPath() . $columnName . '.html.twig';
        if($this->templating->exists($view)) {
            return $this->templating->render($view, ['element' => $element]);
        }
        if(method_exists($element, $columnName)) {
            return $element->$columnName();
        }
        $getter = 'get'. ucfirst($columnName);
        if(!method_exists($element, $getter)) {
            throw new Exception($this->translator->trans(self::ERROR_NO_ELEMENT_RENDER_INFO). ': '.$columnName);
        }
        $result = $element->$getter();
        if(!isset($nameArray[1])) {
            if($result instanceof \DateTime) {
                return $result->format('d.m.Y');
            }
            return  $result;
        }
        unset($nameArray[0]);
        return $result ? $this->formatColumn($result, implode('.',$nameArray)) : '';
    }

    private function handleError($errorMessage) {
        return [ 'error' => $errorMessage ] + $this->getResults();
    }

    private function getResults() {
        return [
            'data' => $this->list,
            'recordsFiltered' => $this->recordsFiltered,
            'recordsTotal' => $this->recordsTotal,
            'draw' => $this->draw
        ];
    }

    /**
     * @return string
     */
    public function getEntityName() {
        return 'entity';
    }

    /**
     * @return EntityRepository
     */
    public function getRepository() {
        return $this->repository;
    }

    /**
     * @return array
     */
    public function getColumns() {
        return $this->repository->getDataTableColumns();
    }
}
