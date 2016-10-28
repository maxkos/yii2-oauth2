<?php
/**
 * CypherKeyService.php
 *
 * PHP version 5.6+
 *
 * @author Philippe Gaultier <pgaultier@sweelix.net>
 * @copyright 2010-2016 Philippe Gaultier
 * @license http://www.sweelix.net/license license
 * @version XXX
 * @link http://www.sweelix.net
 * @package sweelix\oauth2\server\services\redis
 */

namespace sweelix\oauth2\server\services\redis;

use sweelix\oauth2\server\exceptions\DuplicateIndexException;
use sweelix\oauth2\server\exceptions\DuplicateKeyException;
use sweelix\oauth2\server\models\CypherKey;
use sweelix\oauth2\server\interfaces\CypherKeyServiceInterface;
use yii\db\Exception as DatabaseException;
use Yii;

/**
 * This is the cypher key service for redis
 *  database structure
 *    * oauth2:cypherKeys:<aid> : hash (CypherKey)
 *    * oauth2:cypherKeys:etags : hash <aid> -> <etag>
 *
 * @author Philippe Gaultier <pgaultier@sweelix.net>
 * @copyright 2010-2016 Philippe Gaultier
 * @license http://www.sweelix.net/license license
 * @version XXX
 * @link http://www.sweelix.net
 * @package sweelix\oauth2\server\services\redis
 * @since XXX
 */
class CypherKeyService extends BaseService implements CypherKeyServiceInterface
{

    /**
     * @param string $aid cypher key ID
     * @return string cypher key Key
     * @since XXX
     */
    protected function getCypherKeyKey($aid)
    {
        return $this->namespace . ':' . $aid;
    }

    /**
     * @return string etag index Key
     * @since XXX
     */
    protected function getEtagIndexKey()
    {
        return $this->namespace . ':etags';
    }

    /**
     * @inheritdoc
     */
    public function save(CypherKey $cypherKey, $attributes)
    {
        if ($cypherKey->getIsNewRecord()) {
            $result = $this->insert($cypherKey, $attributes);
        } else {
            $result = $this->update($cypherKey, $attributes);
        }
        return $result;
    }

    /**
     * Save Cypher Key
     * @param CypherKey $cypherKey
     * @param null|array $attributes attributes to save
     * @return bool
     * @throws DatabaseException
     * @throws DuplicateIndexException
     * @throws DuplicateKeyException
     * @since XXX
     */
    protected function insert(CypherKey $cypherKey, $attributes)
    {
        $result = false;
        if (!$cypherKey->beforeSave(true)) {
            return $result;
        }
        $cypherKeyKey = $this->getCypherKeyKey($cypherKey->id);
        $etagKey = $this->getEtagIndexKey();
        //check if record exists
        $entityStatus = (int)$this->db->executeCommand('EXISTS', [$cypherKeyKey]);
        if ($entityStatus === 1) {
            throw new DuplicateKeyException('Duplicate key "'.$cypherKeyKey.'"');
        }

        $values = $cypherKey->getDirtyAttributes($attributes);
        $redisParameters = [$cypherKeyKey];
        $this->setAttributesDefinitions($cypherKey->attributesDefinition());
        foreach ($values as $key => $value)
        {
            if ($value !== null) {
                $redisParameters[] = $key;
                $redisParameters[] = $this->convertToDatabase($key, $value);
            }
        }
        //TODO: use EXEC/MULTI to avoid errors
        $transaction = $this->db->executeCommand('MULTI');
        if ($transaction === true) {
            try {
                $this->db->executeCommand('HMSET', $redisParameters);
                $etag = $this->computeEtag($cypherKey);
                $this->db->executeCommand('HSET', [$etagKey, $cypherKey->id, $etag]);
                $this->db->executeCommand('EXEC');
            } catch (DatabaseException $e) {
                // @codeCoverageIgnoreStart
                // we have a REDIS exception, we should not discard
                Yii::trace('Error while inserting entity', __METHOD__);
                throw $e;
                // @codeCoverageIgnoreEnd
            }
        }
        $changedAttributes = array_fill_keys(array_keys($values), null);
        $cypherKey->setOldAttributes($values);
        $cypherKey->afterSave(true, $changedAttributes);
        $result = true;
        return $result;
    }


    /**
     * Update Cypher Key
     * @param CypherKey $cypherKey
     * @param null|array $attributes attributes to save
     * @return bool
     * @throws DatabaseException
     * @throws DuplicateIndexException
     * @throws DuplicateKeyException
     */
    protected function update(CypherKey $cypherKey, $attributes)
    {
        if (!$cypherKey->beforeSave(false)) {
            return false;
        }

        $etagKey = $this->getEtagIndexKey();
        $values = $cypherKey->getDirtyAttributes($attributes);
        $cypherKeyId = isset($values['id']) ? $values['id'] : $cypherKey->id;
        $cypherKeyKey = $this->getCypherKeyKey($cypherKeyId);


        if (isset($values['id']) === true) {
            $newCypherKeyKey = $this->getCypherKeyKey($values['id']);
            $entityStatus = (int)$this->db->executeCommand('EXISTS', [$newCypherKeyKey]);
            if ($entityStatus === 1) {
                throw new DuplicateKeyException('Duplicate key "'.$newCypherKeyKey.'"');
            }
        }

        $this->db->executeCommand('MULTI');
        try {
            if (array_key_exists('id', $values) === true) {
                $oldId = $cypherKey->getOldAttribute('id');
                $oldCypherKeyKey = $this->getCypherKeyKey($oldId);

                $this->db->executeCommand('RENAMENX', [$oldCypherKeyKey, $cypherKeyKey]);
                $this->db->executeCommand('HDEL', [$etagKey, $oldCypherKeyKey]);
            }

            $redisUpdateParameters = [$cypherKeyKey];
            $redisDeleteParameters = [$cypherKeyKey];
            $this->setAttributesDefinitions($cypherKey->attributesDefinition());
            foreach ($values as $key => $value)
            {
                if ($value === null) {
                    $redisDeleteParameters[] = $key;
                } else {
                    $redisUpdateParameters[] = $key;
                    $redisUpdateParameters[] = $this->convertToDatabase($key, $value);
                }
            }
            if (count($redisDeleteParameters) > 1) {
                $this->db->executeCommand('HDEL', $redisDeleteParameters);
            }
            if (count($redisUpdateParameters) > 1) {
                $this->db->executeCommand('HMSET', $redisUpdateParameters);
            }

            $etag = $this->computeEtag($cypherKey);
            $this->db->executeCommand('HSET', [$etagKey, $cypherKeyId, $etag]);

            $this->db->executeCommand('EXEC');
        } catch (DatabaseException $e) {
            // @codeCoverageIgnoreStart
            // we have a REDIS exception, we should not discard
            Yii::trace('Error while updating entity', __METHOD__);
            throw $e;
            // @codeCoverageIgnoreEnd
        }

        $changedAttributes = [];
        foreach ($values as $name => $value) {
            $oldAttributes = $cypherKey->getOldAttributes();
            $changedAttributes[$name] = isset($oldAttributes[$name]) ? $oldAttributes[$name] : null;
            $cypherKey->setOldAttribute($name, $value);
        }
        $cypherKey->afterSave(false, $changedAttributes);
        return true;
    }

    /**
     * @inheritdoc
     */
    public function findOne($key)
    {
        $record = null;
        $cypherKeyKey = $this->getCypherKeyKey($key);
        $cypherKeyExists = (bool)$this->db->executeCommand('EXISTS', [$cypherKeyKey]);
        if ($cypherKeyExists === true) {
            $cypherKeyData = $this->db->executeCommand('HGETALL', [$cypherKeyKey]);
            $record = Yii::createObject(CypherKey::className());
            /** @var CypherKey $record */
            $properties = $record->attributesDefinition();
            $this->setAttributesDefinitions($properties);
            $attributes = [];
            for ($i = 0; $i < count($cypherKeyData); $i += 2) {
                if (isset($properties[$cypherKeyData[$i]]) === true) {
                    $cypherKeyData[$i + 1] = $this->convertToModel($cypherKeyData[$i], $cypherKeyData[($i + 1)]);
                    $record->setAttribute($cypherKeyData[$i], $cypherKeyData[$i + 1]);
                    $attributes[$cypherKeyData[$i]] = $cypherKeyData[$i + 1];
                // @codeCoverageIgnoreStart
                } elseif ($record->canSetProperty($cypherKeyData[$i])) {
                    // TODO: find a way to test attribute population
                    $record->{$cypherKeyData[$i]} = $cypherKeyData[$i + 1];
                }
                // @codeCoverageIgnoreEnd
            }
            if (empty($attributes) === false) {
                $record->setOldAttributes($attributes);
            }
            $record->afterFind();
        }
        return $record;
    }

    /**
     * @inheritdoc
     */
    public function delete(CypherKey $cypherKey)
    {
        $result = false;
        if ($cypherKey->beforeDelete()) {
            $etagKey = $this->getEtagIndexKey();
            $this->db->executeCommand('MULTI');
            $id = $cypherKey->getOldKey();
            $cypherKeyKey = $this->getCypherKeyKey($id);

            $this->db->executeCommand('HDEL', [$etagKey, $id]);
            $this->db->executeCommand('DEL', [$cypherKeyKey]);
            //TODO: check results to return correct information
            $queryResult = $this->db->executeCommand('EXEC');
            $cypherKey->setIsNewRecord(true);
            $cypherKey->afterDelete();
            $result = true;
        }
        return $result;
    }

    /**
     * @inheritdoc
     */
    public function getEtag($key)
    {
        return $this->db->executeCommand('HGET', [$this->getEtagIndexKey(), $key]);
    }

}