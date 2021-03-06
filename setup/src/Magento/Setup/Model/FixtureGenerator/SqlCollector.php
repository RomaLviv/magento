<?php
/**
 * Copyright © 2013-2017 Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Setup\Model\FixtureGenerator;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Profiler;

/**
 * Collect insert queries for quick entity generation
 */
class SqlCollector
{
    /**
     * @var ResourceConnection
     */
    private $resourceConnection;

    /**
     * @var array
     */
    private $sql = [];

    /**
     * @var \Zend_Db_Profiler
     */
    private $profiler;

    /**
     * @param ResourceConnection $resourceConnection
     */
    public function __construct(ResourceConnection $resourceConnection)
    {
        $this->resourceConnection = $resourceConnection;
    }

    /**
     * @param string $sql
     * @param array $bind
     * @return void
     */
    private function addSql($sql, $bind)
    {
        preg_match('~INSERT INTO `(.*)` \((.*)\) VALUES (\(.*\))+~', $sql, $queryMatches);
        if ($queryMatches) {
            $table = $queryMatches[1];
            $fields = preg_replace('~[\s+`]+~', '', $queryMatches[2]);
            $fields = $fields ? explode(',', $fields) : [];
            $sqlBindGroupAmount = count(explode('), (', $queryMatches[3]));
            preg_match(' ~\((.*?)\)~', $queryMatches[3], $sqlBind);
            $sqlBind = preg_replace(['~,\s*~', '~\'~'], [',', ''], $sqlBind[1]);
            $sqlBind = $sqlBind ? explode(',', $sqlBind) : [];
            $binds = [];

            // process multi queries
            if ($sqlBindGroupAmount > 1) {
                $valuesCount = count($bind)/$sqlBindGroupAmount;
                for ($i = 0; $i < $sqlBindGroupAmount; $i++) {
                    $binds[] = array_combine(
                        $fields,
                        $this->handleBindValues($sqlBind, $bind, $i * $valuesCount)
                    );
                }
            } else {
                $sqlBind = $this->handleBindValues($sqlBind, $bind);
                $binds[] = array_combine($fields, $sqlBind);
            }
            $this->sql[] = [$binds, $table];
        }
    }

    /**
     * @param array $sqlBind
     * @param array $bind
     * @param int $bindPosition
     * @return array
     */
    private function handleBindValues(array $sqlBind, array $bind, $bindPosition = 0)
    {
        $bind = array_values($bind);
        foreach ($sqlBind as $i => $fieldValue) {
            if ($fieldValue === '?') {
                $sqlBind[$i] = $bind[$bindPosition];
                $bindPosition++;
            }
        }

        return $sqlBind;
    }

    /**
     * @return array
     */
    public function getSql()
    {
        return $this->sql;
    }

    /**
     * Enable sql parsing
     *
     * @return void
     */
    public function enable()
    {
        $this->sql = [];
        $this->getProfiler()->clear();
        $this->getProfiler()->setEnabled(true);
    }

    /**
     * Disable sql parsing and collect all queries from profiler
     *
     * @return void
     */
    public function disable()
    {
        $this->getProfiler()->setEnabled(false);
        $queries = $this->getProfiler()->getQueryProfiles() ?: [];
        foreach ($queries as $query) {
            if ($query->getQueryType() === Profiler::INSERT) {
                $this->addSql($query->getQuery(), $query->getQueryParams());
            }
        }
    }

    /**
     * @return \Zend_Db_Profiler
     */
    private function getProfiler()
    {
        if ($this->profiler === null) {
            $this->profiler = $this->resourceConnection->getConnection()->getProfiler();
        }

        return $this->profiler;
    }
}
