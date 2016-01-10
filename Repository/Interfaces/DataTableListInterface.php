<?php
namespace Jasuwienas\DataTableBundle\Repository\Interfaces;

use Doctrine\ORM\QueryBuilder;

interface DataTableListInterface {

    /**
     * @return array with columns, for example ['id', 'name', 'code']
     */
    public function getDataTableColumns();

    /**
     * Should return query builder for datatable. For example:
     *      return $this->createQueryBuilder($alias)
     *
     * @param string $alias
     *
     * @return QueryBuilder
     */
    public function createDataTableQueryBuilder($alias);

    /**
     * @return string - path to dataTable template
     */
    public function getTemplatesPath();

}