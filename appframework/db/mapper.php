<?php
/**
 * ownCloud
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Alessandro Cosentino <cosenal@gmail.com>
 * @author Bernhard Posselt <dev@bernhard-posselt.com>
 * @copyright Alessandro Cosentino 2012
 * @copyright Bernhard Posselt 2012, 2014
 */

namespace OCA\Music\AppFramework\Db;

use \OCA\Music\AppFramework\Core\Db;


/**
 * Simple parent class for inheriting your data access layer from. This class
 * may be subject to change in the future
 */
abstract class Mapper {

	protected $tableName;
	protected $entityClass;
	private $db;

	/**
	 * @param Db $db Instance of the Db abstraction layer
	 * @param string $tableName the name of the table. set this to allow entity
	 * @param string $entityClass the name of the entity that the sql should be
	 * mapped to queries without using sql
	 */
	public function __construct(Db $db, $tableName, $entityClass=null){
		$this->db = $db;
		$this->tableName = '*PREFIX*' . $tableName;

		// if not given set the entity name to the class without the mapper part
		// cache it here for later use since reflection is slow
		if($entityClass === null) {
			$this->entityClass = str_replace('Mapper', '', get_class($this));
		} else {
			$this->entityClass = $entityClass;
		}
	}


	/**
	 * @return string the table name
	 */
	public function getTableName(){
		return $this->tableName;
	}


	/**
	 * Deletes an entity from the table
	 * @param Entity $entity the entity that should be deleted
	 */
	public function delete(Entity $entity){
		$sql = 'DELETE FROM `' . $this->tableName . '` WHERE `id` = ?';
		$this->execute($sql, array($entity->getId()));
	}


	/**
	 * Creates a new entry in the db from an entity
	 * @param Entity $entity the entity that should be created
	 * @return Entity saved entity with the set id
	 */
	public function insert(Entity $entity){
		// get updated fields to save, fields have to be set using a setter to
		// be saved
		$properties = $entity->getUpdatedFields();
		$values = '';
		$columns = '';
		$params = array();

		// build the fields
		$i = 0;
		foreach($properties as $property => $updated) {
			$column = $entity->propertyToColumn($property);
			$getter = 'get' . ucfirst($property);

			$columns .= '`' . $column . '`';
			$values .= '?';

			// only append colon if there are more entries
			if($i < count($properties)-1){
				$columns .= ',';
				$values .= ',';
			}

			array_push($params, $entity->$getter());
			$i++;

		}

		$sql = 'INSERT INTO `' . $this->tableName . '`(' .
				$columns . ') VALUES(' . $values . ')';

		$this->execute($sql, $params);

		$entity->setId((int) $this->db->getInsertId($this->tableName));
		return $entity;
	}



	/**
	 * Updates an entry in the db from an entity
	 * @throws \InvalidArgumentException if entity has no id
	 * @param Entity $entity the entity that should be created
	 */
	public function update(Entity $entity){
		// entity needs an id
		$id = $entity->getId();
		if($id === null){
			throw new \InvalidArgumentException(
				'Entity which should be updated has no id');
		}

		// get updated fields to save, fields have to be set using a setter to
		// be saved
		$properties = $entity->getUpdatedFields();
		// dont update the id field
		unset($properties['id']);

		$columns = '';
		$params = array();

		// build the fields
		$i = 0;
		foreach($properties as $property => $updated) {

			$column = $entity->propertyToColumn($property);
			$getter = 'get' . ucfirst($property);

			$columns .= '`' . $column . '` = ?';

			// only append colon if there are more entries
			if($i < count($properties)-1){
				$columns .= ',';
			}

			array_push($params, $entity->$getter());
			$i++;
		}

		$sql = 'UPDATE `' . $this->tableName . '` SET ' .
				$columns . ' WHERE `id` = ?';
		array_push($params, $id);

		$this->execute($sql, $params);
	}


	/**
	 * Runs an sql query
	 * @param string $sql the prepare string
	 * @param array $params the params which should replace the ? in the sql query
	 * @param int $limit the maximum number of rows
	 * @param int $offset from which row we want to start
	 * @return \PDOStatement the database query result
	 */
	protected function execute($sql, array $params=array(), $limit=null, $offset=null){
		$query = $this->db->prepareQuery($sql, $limit, $offset);

		$index = 1;  // bindParam is 1 indexed
		foreach($params as $param) {

			switch (gettype($param)) {
				case 'integer':
					$pdoConstant = \PDO::PARAM_INT;
					break;

				case 'boolean':
					$pdoConstant = \PDO::PARAM_BOOL;
					break;

				default:
					$pdoConstant = \PDO::PARAM_STR;
					break;
			}

			$query->bindValue($index, $param, $pdoConstant);

			$index++;
		}

		return $query->execute();
	}


	/**
	 * Returns an db result and throws exceptions when there are more or less
	 * results
	 * @see findEntity
	 * @param string $sql the sql query
	 * @param array $params the parameters of the sql query
	 * @param int $limit the maximum number of rows
	 * @param int $offset from which row we want to start
	 * @throws DoesNotExistException if the item does not exist
	 * @throws MultipleObjectsReturnedException if more than one item exist
	 * @return array the result as row
	 */
	protected function findOneQuery($sql, array $params=array(), $limit=null, $offset=null){
		$result = $this->execute($sql, $params, $limit, $offset);
		$row = $result->fetchRow();

		if($row === false || $row === null){
			throw new DoesNotExistException('No matching entry found');
		}
		$row2 = $result->fetchRow();
		//MDB2 returns null, PDO and doctrine false when no row is available
		if( ! ($row2 === false || $row2 === null )) {
			throw new MultipleObjectsReturnedException('More than one result');
		} else {
			return $row;
		}
	}


	/**
	 * Creates an entity from a row. Automatically determines the entity class
	 * from the current mapper name (MyEntityMapper -> MyEntity)
	 * @param array $row the row which should be converted to an entity
	 * @return Entity the entity
	 */
	protected function mapRowToEntity($row) {
		return call_user_func($this->entityClass .'::fromRow', $row);
	}


	/**
	 * Runs a sql query and returns an array of entities
	 * @param string $sql the prepare string
	 * @param array $params the params which should replace the ? in the sql query
	 * @param int $limit the maximum number of rows
	 * @param int $offset from which row we want to start
	 * @return array all fetched entities
	 */
	protected function findEntities($sql, array $params=array(), $limit=null, $offset=null) {
		$result = $this->execute($sql, $params, $limit, $offset);

		$entities = array();

		while($row = $result->fetchRow()){
			$entities[] = $this->mapRowToEntity($row);
		}

		return $entities;
	}


	/**
	 * Returns an db result and throws exceptions when there are more or less
	 * results
	 * @param string $sql the sql query
	 * @param array $params the parameters of the sql query
	 * @param int $limit the maximum number of rows
	 * @param int $offset from which row we want to start
	 * @throws DoesNotExistException if the item does not exist
	 * @throws MultipleObjectsReturnedException if more than one item exist
	 * @return Entity the entity
	 */
	protected function findEntity($sql, array $params=array(), $limit=null, $offset=null){
		return $this->mapRowToEntity($this->findOneQuery($sql, $params, $limit, $offset));
	}


}
