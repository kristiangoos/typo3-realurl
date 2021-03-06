<?php
namespace DmitryDulepov\Realurl;
/***************************************************************
*  Copyright notice
*
*  (c) 2016 Dmitry Dulepov <dmitry.dulepov@gmail.com>
*  All rights reserved
*
*  This script is part of the TYPO3 project. The TYPO3 project is
*  free software; you can redistribute it and/or modify
*  it under the terms of the GNU General Public License as published by
*  the Free Software Foundation; either version 2 of the License, or
*  (at your option) any later version.
*
*  The GNU General Public License can be found at
*  http://www.gnu.org/copyleft/gpl.html.
*
*  This script is distributed in the hope that it will be useful,
*  but WITHOUT ANY WARRANTY; without even the implied warranty of
*  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
*  GNU General Public License for more details.
*
*  This copyright notice MUST APPEAR in all copies of the script!
***************************************************************/
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * This class updates realurl from version 1.x to 2.x.
 *
 * @author Dmitry Dulepov <dmitry.dulepov@gmail.com>
 */
class ext_update {

	/** @var \TYPO3\CMS\Core\Database\DatabaseConnection */
	protected $databaseConnection;

	/**
	 * Creates the instance of the class.
	 */
	public function __construct() {
		$this->databaseConnection = $GLOBALS['TYPO3_DB'];
	}

	/**
	 * Runs the update.
	 */
	public function main() {
		$locker = $this->getLocker();
		try {
			if ($locker) {
				$locker->acquire();
			}
		}
		catch (\Exception $e) {
			// Nothing
		}

		$this->checkAndRenameTables();
		$this->checkAndUpdatePathCachePrimaryKey();
		$this->updateRealurlTableStructure();
		$this->removeUrlDataEntriesWithIgnoredParameters();

		if ($locker && (method_exists($locker, 'isAcquired') && $locker->isAcquired() || method_exists($locker, 'getLockStatus') && $locker->getLockStatus())) {
			$locker->release();
		}
	}

	/**
	 * Checks if the script should execute. We check for everything except table
	 * structure.
	 *
	 * @return bool
	 */
	public function access() {
		return $this->hasOldCacheTables() || $this->pathCacheNeedsUpdates();
	}

	/**
	 * Checks and renames *cache tables to *data tables.
	 */
	protected function checkAndRenameTables() {
		$tableMap = array(
			'tx_realurl_pathcache' => 'tx_realurl_pathdata',
			'tx_realurl_urlcache' => 'tx_realurl_urldata',
		);

		$tables = $this->databaseConnection->admin_get_tables();
		foreach ($tableMap as $oldTableName => $newTableName) {
			if (isset($tables[$oldTableName])) {
				if (!isset($tables[$newTableName])) {
					$this->databaseConnection->sql_query('ALTER TABLE ' . $oldTableName . ' RENAME TO ' . $newTableName);
				}
				else {
					if ((int)$tables[$newTableName]['Rows'] === 0) {
						$this->databaseConnection->sql_query('DROP TABLE ' . $newTableName);
						$this->databaseConnection->sql_query('CREATE TABLE ' . $newTableName . ' LIKE ' . $oldTableName);
						$this->databaseConnection->sql_query('INSERT INTO ' . $newTableName . ' SELECT * FROM ' . $oldTableName);
					}
					$this->databaseConnection->sql_query('DROP TABLE ' . $oldTableName);
				}
			}
		}
	}

	/**
	 * Checks if the primary key needs updates (this is something that TYPO3
	 * sql parser fails to do for years) and does necessary changes.
	 *
	 * @return void
	 */
	protected function checkAndUpdatePathCachePrimaryKey() {
		if ($this->pathCacheNeedsUpdates()) {
			$this->databaseConnection->sql_query('ALTER TABLE tx_realurl_pathdata CHANGE cache_id uid int(11) NOT NULL');
			$this->databaseConnection->sql_query('ALTER TABLE tx_realurl_pathdata DROP PRIMARY KEY');
			$this->databaseConnection->sql_query('ALTER TABLE tx_realurl_pathdata MODIFY uid int(11) NOT NULL auto_increment PRIMARY KEY');
		}
	}

	/**
	 * Obtains the locker depending on the TYPO3 version.
	 *
	 * @return \TYPO3\CMS\Core\Locking\Locker|\TYPO3\CMS\Core\Locking\LockingStrategyInterface
	 */
	protected function getLocker() {
		if (class_exists('\\TYPO3\\CMS\\Core\\Locking\\LockFactory')) {
			$locker = GeneralUtility::makeInstance('TYPO3\\CMS\\Core\\Locking\\LockFactory')->createLocker('tx_realurl_update');
		}
		elseif (class_exists('\\TYPO3\\CMS\\Core\\Locking\\Locker')) {
			$locker = GeneralUtility::makeInstance('TYPO3\\CMS\\Core\\Locking\\Locker', 'tx_realurl_update');
		}
		else {
			$locker = null;
		}

		return $locker;
	}

	protected function hasOldCacheTables() {
		$tables = $this->databaseConnection->admin_get_tables();
		return isset($tables['tx_realurl_pathcache']) || isset($tables['tx_realurl_urlcache']);
	}

	/**
	 * Checks if path cache table is ok.
	 *
	 * @return bool
	 */
	protected function pathCacheNeedsUpdates() {
		$fields = $this->databaseConnection->admin_get_fields('tx_realurl_pathdata');

		return isset($fields['cache_id']) || !isset($fields['uid']) || stripos($fields['uid']['Extra'], 'auto_increment') === false;
	}

	/**
	 * Removes entries with parameters that should be ignored.
	 */
	protected function removeUrlDataEntriesWithIgnoredParameters() {
		$this->databaseConnection->exec_DELETEquery('tx_realurl_urlcache', 'original_url RLIKE \'(^|&)(utm_[a-z]+|pk_campaign|pk_kwd)=\'');
	}

	/**
	 * Updates realurl table structure. The code is copied almost 1:1 from
	 * ExtensionManagerTables class.
	 *
	 * We ignore any errors because nothing can be done about those really. The
	 * client will have to do database update anyway, so he will see all failed
	 * queries.
	 *
	 * @return void
	 */
	protected function updateRealurlTableStructure() {
		$updateStatements = array();
		
		// Get all necessary statements for ext_tables.sql file
		$rawDefinitions = file_get_contents(ExtensionManagementUtility::extPath('realurl', 'ext_tables.sql'));
		$sqlParser = GeneralUtility::makeInstance('TYPO3\\CMS\\Install\\Service\\SqlSchemaMigrationService');
		$fieldDefinitionsFromFile = $sqlParser->getFieldDefinitions_fileContent($rawDefinitions);
		if (count($fieldDefinitionsFromFile)) {
			$fieldDefinitionsFromCurrentDatabase = $sqlParser->getFieldDefinitions_database();
			$diff = $sqlParser->getDatabaseExtra($fieldDefinitionsFromFile, $fieldDefinitionsFromCurrentDatabase);
			$updateStatements = $sqlParser->getUpdateSuggestions($diff);
		}

		foreach ((array)$updateStatements['add'] as $string) {
			$this->databaseConnection->admin_query($string);
		}
		foreach ((array)$updateStatements['change'] as $string) {
			$this->databaseConnection->admin_query($string);
		}
		foreach ((array)$updateStatements['create_table'] as $string) {
			$this->databaseConnection->admin_query($string);
		}
	}

}
