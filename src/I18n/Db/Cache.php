<?php
namespace Polyglot\I18n\Db;

use Polyglot\I18n\Translation\TranslationEntity;

/**
 * This cache is meant to store entities on each rendering pass.
 * It is not intended to dump the results in wp_cache or as a transient.
 * However, using transients will likely be a long term goal on some of the entities
 * stored here
 */
class Cache  {
    /**
     * The entity map cache sorts records by obj_kind and
     * translation_of, the two most frequently used sort columns.
     * This makes cache lookups quick and we can mimic simple SQL
     * behaviour transparently.
     * @var array
     */
    private $entitiesMap = array();
    private $entitiesIds = array();

    protected $translationOfKeyPrefix = "t_of_";
    protected $pIdKeyPrefix = "id_";

    public function addEntity(TranslationEntity $entity)
    {
        $id = $entity->getObjectId();
        $kind = $entity->getObjectKind();

        if (!$this->idWasCached($id, $kind)) {

            $tOfKey = $this->translationOfKeyPrefix . $entity->getOriginalObjectId();
            $idKey = $this->pIdKeyPrefix . $id;

            if (!array_key_exists($kind, $this->entitiesMap)) {
                $this->entitiesMap[$kind] = array();
            }

            if (!array_key_exists($tOfKey, $this->entitiesMap[$kind])) {
                $this->entitiesMap[$kind][$tOfKey] = array();
            }

            $this->entitiesMap[$kind][$tOfKey][] = $entity;
            $this->entitiesIds[$kind][$idKey] = $entity;
        }
    }

    public function getNumberOfCachedRecords()
    {
        $total = 0;

        foreach ($this->entitiesIds as $kind => $entries) {
            $total += count($entries);
        }

        return $total;
    }

    public function getByKind($kind)
    {
        if (array_key_exists($kind, $this->entitiesMap)) {
            return $this->entitiesMap[$kind];
        }

        return array();
    }

    public function findTranlationsOf($id, $kind)
    {
        $byKind = $this->getByKind($kind);
        $key = $this->translationOfKeyPrefix . $id;

        if (array_key_exists($key, $byKind)) {
            return $byKind[$key];
        }

        return array();
    }

    public function findDetailsById($id, $kind)
    {
        if ($this->idWasCached($id, $kind)) {
            $key = $this->pIdKeyPrefix . $id;
            return $this->entitiesIds[$kind][$key];
        }
    }

    public function findByOriginalObject($objId, $objKind)
    {
        $byKind = $this->getByKind($objKind);

        // We'll have to keep on eye on how this
        // behaves in real life
        foreach ($byKind as $translationsOfId) {
            foreach ($translationsOfId as $entity) {
                if ((int)$entity->getObjectId() === (int)$objId) {
                    return $entity;
                }
            }
        }
    }

    public function idWasCached($id, $kind)
    {
        $key = $this->pIdKeyPrefix . $id;
        return array_key_exists($kind, $this->entitiesIds) && array_key_exists($key, $this->entitiesIds[$kind]);
    }
}
