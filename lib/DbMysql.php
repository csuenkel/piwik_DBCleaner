<?php

/**
  *
 * DBCleaner
 *
 * Copyright (c) 2012-2013, Christian Suenkel <info@suenkel.org>
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions
 * are met:
 *
 * * Redistributions of source code must retain the above copyright
 *   notice, this list of conditions and the following disclaimer.
 *
 * * Redistributions in binary form must reproduce the above copyright
 *   notice, this list of conditions and the following disclaimer in
 *   the documentation and/or other materials provided with the
 *   distribution.
 *
 * * Neither the name of Christian Suenkel nor the names of his
 *   contributors may be used to endorse or promote products derived
 *   from this software without specific prior written permission.
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS
 * FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE
 * COPYRIGHT OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT,
 * INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING,
 * BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES;
 * LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER
 * CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT
 * LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN
 * ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
 * POSSIBILITY OF SUCH DAMAGE.
 *
 * @author Christian Suenkel <christian@suenkel.de>
 * @link http://plugin.suenkel.org
 * @copyright 2012-2013 Christian Suenkel <info@suenkel.de>
 * @license http://www.opensource.org/licenses/BSD-3-Clause The BSD 3-Clause License
 * @package Piwik_DBCleaner
 */
namespace Piwik\Plugins\DBCleaner;

use Piwik\Common;
use Piwik\Db;

require_once __DIR__ . '/DbAbstract.php';

/**
 * Convert the database to a mysqldump-file
 */
class DbMysql extends DbAbstract
{
    
    /**
     * timestamp, when the database was used the first time
     *
     * @var int
     */
    protected $startTS = 0;

    /**
     * (non-PHPdoc)
     *
     * @see DbAbstract::preamble()
     */
    public function preamble(FileDumper $fp)
    {
        $fp->put('-- ')
            ->put('--  Piwik Mysql Dump')
            ->put('--  created ' . date('c'))
            ->put('--  generated by DBCleaner Plugin http://plugin.suenkel.org/')
            ->put('--  Version 0.3.1')
            ->put('--  Piwik-Version: ' . \Piwik\Version::VERSION)
            ->put('-- ')
            ->put('')
            ->put('SET SQL_MODE="NO_AUTO_VALUE_ON_ZERO";')
            ->put('SET FOREIGN_KEY_CHECKS=0;')
            ->put('')
            ->put('/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;')
            ->put('/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;')
            ->put('/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;')
            ->put('/*!40101 SET NAMES utf8 */;')
            ->put('');
        return $this;
    }

    /**
     * (non-PHPdoc)
     *
     * @see DbAbstract::appendix()
     */
    public function appendix(FileDumper $fp)
    {
        $fp->put('')
            ->put('/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;')
            ->put('/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;')
            ->put('/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;')
            ->put('')
            ->put('-- ')
            ->put('-- Mysql Dump End ' . date('c'))
            ->put('-- ');
        return $this;
    }

    /**
     * (non-PHPdoc)
     *
     * @see DbAbstract::dump()
     */
    public function dump(FileDumper $fp, $tableName, &$rowset)
    {
        $isArchiveBlob = preg_match('/archive_blob/', $tableName);
        // map mysql-fields to sqldump formats
        foreach ($rowset as $row) {
            $keys = $values = array();
            foreach ($row as $key => $value) {
                $keys[] = "`" . $key . "`";
                if (is_null($value)) {
                    $values[] = 'NULL';
                } elseif (($isArchiveBlob && $key == 'value') || $key == 'idvisitor' ||
                         $key == 'config_id' || $key == 'location_ip') {
                    // fix well known binary fields
                    $values[] = '0x' . bin2hex($value);
                } elseif (is_int($value)) {
                    $values[] = $value;
                } else {
                    // assume string
                    // mysql_real_escape_string is not necesarry, because its all "internal"
                    $values[] = "'" . str_replace("'", "\'", $value) . "'";
                }
            }
            $sql = sprintf("INSERT INTO `%s` (%s) values (%s);", $tableName, implode(',', $keys), 
                    implode(',', $values));
            $fp->put($sql);
        }
        return $this;
    }

    /**
     * (non-PHPdoc)
     *
     * @see DbAbstract::count()
     */
    public function count()
    {
        $visitSql = sprintf('SELECT count(*) as cnt FROM %s WHERE %s', 
                Common::prefixTable('log_visit'), $this->where());
        $result = Db::get()->fetchOne($visitSql);
        return ($result) ? $result : 0;
    }

    /**
     * (non-PHPdoc)
     *
     * @see DbAbstract::execute()
     */
    public function execute(FileDumper $fp)
    {
        $siteId = $this->getConfig('idsite', 0);
        
        $cnt = $this->executeDump($fp);
        if ($cnt > 0) {
            // not yet finished, wait for the next run
            return $cnt;
        }
        
        if ($siteId == null) {
            // historical dump finished -> ready
            return 0;
        }
        
        /*
         * Website Mode, historical data dumped, now dump archive and config tables
         */
        
        // gather archive tables
        $dumpTables = array();
        $sql = "SHOW TABLES";
        $result = DB::get()->fetchAll($sql);
        foreach ($result as $tabrow) {
            $tabname = array_pop($tabrow);
            // TODO: be more precise of the candidate tablenames
            if (preg_match('/archive_/', $tabname)) {
                $dumpTables[] = $tabname;
            }
        }
        // add common idsite-relevant tables
        $dumpTables = array_merge($dumpTables, 
                array(Common::prefixTable('goal'), 
                        Common::prefixTable('site_url'), 
                        Common::prefixTable('access'), 
                        Common::prefixTable('site')));
        // dump them all
        foreach ($dumpTables as $tablename) {
            $siteSql = sprintf(' FROM `%s` WHERE idsite = %d', $tablename, $siteId);
            $sitedef = DB::get()->fetchAll('SELECT *' . $siteSql);
            $this->dump($fp, $tablename, $sitedef);
            DB::exec('DELETE' . $siteSql);
        }
        return 0;
    }

    /**
     * convert the database of the log_* tables in "limit"-chunks to the dumpfile
     *
     * @param FileDumper $fp            
     * @return number
     */
    protected function executeDump(FileDumper $fp)
    {
        $this->reconnectDatabase();
        
        // STEP1: Fetch a chunk of visits to be dumped
        $limit = $this->getConfig('limit', 500);
        $whereSql = $this->where();
        $visitSql = sprintf('SELECT * FROM %s WHERE %s LIMIT %d', Common::prefixTable('log_visit'), 
                $whereSql, $limit);
        
        $visits = DB::get()->fetchAll($visitSql, array());
        if (empty($visits)) {
            return 0;
        }
        $visitIds = array();
        foreach ($visits as $v) {
            $visitIds[] = $v['idvisit'];
        }
        
        // STEP2: dump all constraint-tables
        $chunkSize = 40;
        for($t = 0; $t < count($visitIds); $t += $chunkSize) {
            $vslice = array_slice($visitIds, $t, $chunkSize);
            $this->dumpSubTable($fp, 'log_link_visit_action', $vslice)
                ->throwExceptionIfLackOfResources()
                ->dumpSubTable($fp, 'log_conversion', $vslice)
                ->throwExceptionIfLackOfResources()
                ->dumpSubTable($fp, 'log_conversion_item', $vslice)
                ->throwExceptionIfLackOfResources();
        }
        // STEP3: store the visits to the sql-file
        $this->dump($fp, Common::prefixTable('log_visit'), $visits);
        unset($visits);
        // STEP4: delete the visits from database
        $chunkSize = 50;
        for($t = 0; $t < count($visitIds); $t += $chunkSize) {
            $vslice = array_slice($visitIds, $t, $chunkSize);
            $deleteSQL = sprintf('DELETE from %s where idvisit in (%s)', 
                    Common::prefixTable('log_visit'), implode(',', $vslice));
            DB::exec($deleteSQL);
        }
        return count($visitIds);
    }

    /**
     * generate the WHERE part to be used on selection based on the configuration
     * siteId -> website dumpmode
     * until -> dump only historical logs
     *
     * @return string
     */
    protected function where()
    {
        $siteId = $this->getConfig('idsite', 0);
        $until = $this->getConfig('until', 0);
        if ($siteId > 0) {
            // website cleanup "mode"
            return 'idsite = ' . intVal($siteId);
        }
        // historical data cleanup
        return sprintf("visit_last_action_time < '%s'", date('Y-m-d H:i:s', $until));
    }

    /**
     * get a list of candidates to be optimized
     *
     * @return array - list of tables
     */
    public function getTablesToOptimize()
    {
        $this->reconnectDatabase();
        $tables = array();
        $sql = "SHOW TABLES";
        $result = DB::get()->fetchAll($sql);
        foreach ($result as $tabrow) {
            $tabname = array_pop($tabrow);
            // TODO: be more precise of the candidate tablenames
            if (preg_match('/archive_/', $tabname)) {
                $tables[] = $tabname;
            }
        }
        // add common idsite-relevant tables
        $tables = array_merge($tables, 
                array(Common::prefixTable('goal'), 
                        Common::prefixTable('site_url'), 
                        Common::prefixTable('access'), 
                        Common::prefixTable('site'), 
                        Common::prefixTable('log_conversion'), 
                        Common::prefixTable('log_conversion_item'), 
                        Common::prefixTable('log_visit'), 
                        Common::prefixTable('log_link_visit_action')));
        return $tables;
    }

    /**
     * optimize a table
     *
     * @param string $tablename            
     * @return DbMysql
     */
    public function optimizeTable($tablename)
    {
        $this->reconnectDatabase();
        DB::exec('OPTIMIZE TABLE `' . $tablename . "`");
        return $this;
    }

    /**
     * Dump a constraint table
     * dump the entries to the given visitIds
     *
     * @param FileDumper $fp            
     * @param string $tableName
     *            - tablename to process
     * @param array $visitIds
     *            - list of visitIds
     * @throws InfoMemory_Exception - if low memory
     * @return DbMysql
     */
    protected function dumpSubTable(FileDumper $fp, $tableName, &$visitIds)
    {
        if (empty($visitIds)) {
            return $this;
        }
        $subSql = sprintf(' FROM %s WHERE idvisit IN (%s)', Common::prefixTable($tableName), 
                implode(',', $visitIds));
        
        $count = $limit = intVal(min(200, max(5, $this->getConfig('limit', 200) / 10))); // fence fetch limit: <200 and >5
        $page = 0;
        while ($count >= $limit) {
            InfoMemory::getInstance()->throwExceptionIfExceeds();
            $sql = sprintf('SELECT * %s LIMIT %d, %d', $subSql, $page * $limit, $limit);
            $subs = DB::get()->fetchAll($sql);
            $count = count($subs);
            $this->dump($fp, Common::prefixTable($tableName), $subs);
            unset($subs);
            $page++;
        }
        DB::exec('DELETE' . $subSql);
        return $this;
    }

    /**
     * store the first time of database useage and reconnect after 300 sec
     * to avoid mysql-timeouts
     *
     * @return DbMysql
     */
    protected function reconnectDatabase()
    {
        /*
         * with Piwik 2.0 there is no "convenient" function available to reconnect the Database
         */
        return $this;
    }
}