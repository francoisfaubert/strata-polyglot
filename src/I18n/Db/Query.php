<?php
namespace Polyglot\I18n\Db;

use Polyglot\I18n\Db\Cache;
use Polyglot\I18n\Db\Logger;

use Polyglot\I18n\Translation\Tree;
use Polyglot\I18n\Translation\TranslationEntity;

use Strata\Strata;
use Exception;

class Query {

    const WP_UNIQUE_KEY = "polyglot-plugin";
    const DB_VERSION = "0.1.1";

    protected $logger;
    private $cache;

    private $numberOfRecords = 0;
    private $cachedIds = array();
    private $pageSize = 200;
    private $defaultPageSize = 200;
    private $nbPageSaved = 0;


    function __construct()
    {
        $this->logger = new Logger();
        $this->cache = new Cache();

        $this->numberOfRecords = $this->countTranslations();

        if ($this->numberOfRecords > $this->defaultPageSize) {
            $this->pageSize =  ceil($this->numberOfRecords / 3);

            if ($this->pageSize < $this->defaultPageSize) {
                $this->pageSize = $this->defaultPageSize;
            }
        }

        $this->cachePageAtId(0);
    }

    private function countTranslations()
    {
        $this->logger->logQueryStart();

        global $wpdb;
        $count = $wpdb->get_var("SELECT count(*) FROM {$wpdb->prefix}polyglot");

        $this->logger->logQueryCompletion($wpdb->last_query);

        return (int)$count;
    }

    public function createTable()
    {
        global $wpdb;
        $tableName = $wpdb->prefix . 'polyglot';
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $tableName (
            polyglot_ID mediumint(9) NOT NULL AUTO_INCREMENT,
            obj_kind varchar(10) NOT NULL,
            obj_type tinytext NOT NULL,
            obj_id mediumint(9) NOT NULL,
            translation_of mediumint(9) NOT NULL,
            translation_locale varchar(10),
            UNIQUE KEY polyglot_ID (polyglot_ID)
        ) $charset;";

        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
        dbDelta($sql);

        Strata::app()->log("Created the Polyglot table.", "<magenta>Polyglot:Query</magenta>");

        add_option('polyglot_db_version', self::DB_VERSION);
    }

    public function unlinkTranslationFor($objectId, $objectKind)
    {
        // Trash the translations' WP_Post first
        global $wpdb;

        $this->logger->logQueryStart();

        $locales = Strata::i18n()->getLocales();
        $app = Strata::app();

        foreach ($locales as $locale) {
            if ($objectKind === "WP_Post" && $locale->isTranslationOfPost($objectId)) {
                $translation = $locale->getTranslatedPost($objectId);
                if (!is_null($translation)) {
                    wp_trash_post($translation->ID);
                }
            } elseif($objectKind === "Term" && $locale->hasTermTranslation($objectId)) {
                $translation = $locale->getTranslatedTerm($objectId);
                if (!is_null($translation)) {
                    wp_delete_term($translation->term_id);
                }
            }
        }

        // Then delete all the polyglot references
        // to that original post.

        $result = $wpdb->delete($wpdb->prefix . 'polyglot', array(
            "translation_of" => $objectId,
            "obj_kind" => $objectKind
        ));

        $this->logger->logQueryCompletion($wpdb->last_query);

        return $result;
    }

    public function unlinkTranslation($translationId, $objectKind)
    {
        // Trash the translations' WP_Post first
        global $polyglot, $wpdb;

        // Then delete all the polyglot references
        // to that original post.
        return $wpdb->delete($wpdb->prefix . 'polyglot', array(
            "obj_id" => $translationId,
            "obj_kind" => $objectKind
        ));
    }

    public function addTranslation($originalId, $originalType, $originalKind, $targetLocale, $associationId)
    {
        global $wpdb;

        if ((int)$associationId > 0) {

            $row = array(
                'obj_kind' => $originalKind,
                'obj_type' => $originalType,
                'obj_id'    => $associationId,
                'translation_of' => $originalId,
                'translation_locale' => $targetLocale
            );

            if ($wpdb->insert("{$wpdb->prefix}polyglot", $row)) {
                // this seems like overkill
                $this->cache = new Cache();

                return $associationId;
            }

            throw new Exception("Could not save translation data.");
        }

        throw new Exception("Could not duplicate post.");
    }

    public function findTranlationsOf(TranslationEntity $object)
    {
        return $this->findTranlationsOfId($object->getObjectId(), $object->getObjectKind());
    }

    public function findTranlationsOfId($id, $kind = "WP_Post")
    {
        $data = array();

        // Lookup in the cache beforehand
        $alreadyPresent = $this->cache->findTranlationsOf($id, $kind);
        if (is_array($alreadyPresent)) {
            foreach ($alreadyPresent as $entity) {
                $data[(int)$entity->getId()] = $entity;
            }
        }

        // Bail when we know they aren't any other results.
        if($this->cacheIsComplete()) {
            return array_values($data);
        }

        $notIn = count($data) ? 'AND polyglot_ID NOT IN ('.implode(',', array_keys($data)).')' : '';

        global $wpdb;
        $this->logger->logQueryStart();
        $results = $wpdb->get_results($wpdb->prepare("
            SELECT *
            FROM {$wpdb->prefix}polyglot
            WHERE translation_of = %d
            AND obj_kind = %s",
            $id,
            $kind
        ));
        $this->logger->logQueryCompletion($wpdb->last_query);

        // Because we're starting to hit records that aren't cached,
        // cache a page around this id.
        $this->cachePageAtId($this->pageSize * $this->nbPageSaved);

        if (!is_null($results) && count($results)) {
            foreach ($this->rowsToEntities($results) as $entity) {
                $data[(int)$entity->getId()] = $entity;
            }
        }

        return array_values($data);
    }

    /**
     * Queries for the translation details of an object.
     * @param  mixed $object
     * @return TranslationEntity
     */
    public function findDetails(TranslationEntity $object)
    {
        return $this->findDetailsById($object->getObjectId(), $object->getObjectKind());
    }

    /**
     * Queries for the translation details of an object.
     * @param  int $id
     * @param string $kind The type of the object (default "WP_Post")
     * @return TranslationEntity
     */
    public function findDetailsById($id, $kind = "WP_Post")
    {
        if ($this->cacheIsComplete() || $this->cache->idWasCached($id, $kind)) {
            return $this->cache->findDetailsById($id, $kind);
        }

        global $wpdb;
        $this->logger->logQueryStart();
        $result = $wpdb->get_row($wpdb->prepare("
            SELECT *
            FROM {$wpdb->prefix}polyglot
                WHERE obj_id = %d
                AND obj_kind = %s
            LIMIT 1",
            $id,
            $kind
        ));
        $this->logger->logQueryCompletion($wpdb->last_query);

        // Because we're starting to hit records that aren't cached,
        // cache a page around this id.
        $this->cachePageAtId($this->pageSize * $this->nbPageSaved);

        if (!is_null($result) && $result != '') {
            // Because we're starting to hit records that aren't cached,
            // cache a page around this id.
            $this->cachePageAtId($result->polyglot_ID);
            $entity = TranslationEntity::factory($result);
            $this->cache->addEntity($entity);
            return $entity;
        }
    }

    public function findDetailsByIds(array $ids, $kind = "WP_Post")
    {
        $details = array();
        $missingIds = array();

        // Lookup in the cache beforehand
        foreach ($ids as $id) {
            if ($this->cache->idWasCached($id, $kind)) {
                $details[(int)$id] = $this->cache->findDetailsById($id, $kind);
            } else {
                $missingIds[] = $id;
            }
        }

        // Bail when we know they aren't any other results.
        if($this->cacheIsComplete() || !count($missingIds)) {
            return $details;
        }

        global $wpdb;
        $this->logger->logQueryStart();
        $results = $wpdb->get_results($wpdb->prepare("
            SELECT *
            FROM {$wpdb->prefix}polyglot
                WHERE obj_id IN (%s)
                AND obj_kind = %s",
            $missingIds,
            $kind
        ));
        $this->logger->logQueryCompletion($wpdb->last_query);

        if (!is_null($results)) {
            $entities = $this->rowsToEntities($results);
            foreach ($entities as $entity) {
                $this->cache->addEntity($entity);
            }

            return $details + $entities;
        }
    }


    /**
     * Fetches all the known translation details of an object's original parent.
     * Best for cases where you have a translation and need to build the list of
     * all locale possibilities.
     * @param  mixed $object
     * @return TranslationEntity
     */
    public function findOriginalTranslationsOf($object)
    {
        return $this->findTranslationsOfId($object->getObjectId(), $object->getObjectKind());
    }

    /**
     * Fetches all the known translation details of an object's original parent.
     * Best for cases where you have a translation and need to build the list of
     * all locale possibilities.
     * @param  int $id
     * @param string $kind The type of the object (default "WP_Post")
     * @return TranslationEntity
     */
    public function findOriginalTranslationsOfId($id, $kind = "WP_Post")
    {
        $translated = $this->findDetailsById($id, $kind);
        return $this->findTranlationsOfId($translated->translation_of, $translated->obj_kind);
    }


    public function findLocaleTranslations($locale, $kind = "WP_Post", $type = null)
    {
        $data = array();
        $localeCode = $locale->getCode();

        // Lookup in the cache beforehand
        foreach ($this->cache->getByKind($kind) as $translationOf => $entities) {
            foreach ($entities as $entity) {
                if (!is_null($type) && $type === $entity->getObjectType() || is_null($type)) {
                    if ($entity->getTranslationLocaleCode() === $localeCode) {
                        $data[(int)$entity->getId()] = $entity;
                    }
                }
            }
        }

        // Bail when we know they aren't any other results.
        if($this->cacheIsComplete()) {
            return array_values($data);
        }

        $notIn = count($data) ? 'AND polyglot_ID NOT IN ('.implode(',', array_keys($data)).')' : '';

        $andType = "";
        if (!is_null($type)) {
            $andType = $wpdb->prepare(" AND obj_type = %s ", $type);
        }

        global $wpdb;
        $this->logger->logQueryStart();
        $results = $wpdb->get_results($wpdb->prepare("
            SELECT *
            FROM {$wpdb->prefix}polyglot
            WHERE translation_locale = %s
            $notIn
            $andType
            AND obj_kind = %s",
            $localeCode,
            $kind
        ));
        $this->logger->logQueryCompletion($wpdb->last_query);

        if (!is_null($results) && count($results)) {
            $entities = $this->rowsToEntities($results);
            foreach ($entities as $entity) {
                $this->cache->addEntity($entity);
                $data[(int)$entity->getId()] = $entity;
            }
        }

        return array_values($data);
    }

    /**
     * @todo This does not use the caching mechanism. It should. Just confirm the unique use case in
     * queryRewriter really needs this beforhand.
     * @param  [type] $locale [description]
     * @param  string $kind   [description]
     * @return [type]         [description]
     */
    public function findTranslationIdsNotInLocale($locale, $kind = "WP_Post")
    {
        global $wpdb;

        $this->logger->logQueryStart();
        $results = $wpdb->get_results($wpdb->prepare("
            SELECT * FROM
                ( SELECT obj_id
                    FROM {$wpdb->prefix}polyglot
                    WHERE translation_locale != %s
                    AND  obj_kind = %s) as tbl1,
                (SELECT translation_of as obj_id
                    FROM {$wpdb->prefix}polyglot
                    WHERE translation_locale = %s
                    AND  obj_kind = %s
                    ) as tbl2",
            $locale->getCode(),
            $kind,
            $locale->getCode(),
            $kind
        ));
        $this->logger->logQueryCompletion($wpdb->last_query);

        if (!is_null($results) && count($results)) {
            return $results;
        }
    }


    public function listTranslatedEntitiesIds($kind = "WP_Post")
    {
        $entities = array();

        // Lookup in the cache beforehand
        foreach ($this->cache->getByKind($kind) as $translationOf => $cachedEntities) {
            foreach ($cachedEntities as $entity) {
                if (!array_key_exists((int)$entity->getObjectId(), $entities)) {
                    $entities[(int)$entity->getObjectId()] = $entity->getObjectId();
                }
            }
        }

        // Bail when we know they aren't any other results.
        if($this->cacheIsComplete()) {
            return array_values($entities);
        }

        $notIn = count($entities) ? 'AND obj_id NOT IN ('.implode(',', array_keys($entities)).')' : '';

        global $wpdb;

        $this->logger->logQueryStart();
        $results = $wpdb->get_results($wpdb->prepare("
            SELECT *
            FROM {$wpdb->prefix}polyglot
            WHERE obj_kind = %s
            $notIn",
            $kind
        ));
        $this->logger->logQueryCompletion($wpdb->last_query);

        if (!is_null($results) && count($results)) {
            foreach ($this->rowsToEntities($results) as $entity) {
                $this->cache->addEntity($entity);
                $entities[(int)$entity->getObjectId()] = $entity->getObjectId();
            }
        }

        return array_values($entities);
    }


    /**
     * Returns the original locale of a translated object.
     * @param  mixed $translatedObj
     * @return Locale
     */
    public function findObjectLocale($translatedObj)
    {
        $details = $this->findDetails($translatedObj);

        if (is_null($details)) {
            return Strata::i18n()->getDefaultLocale();
        }

        return Strata::i18n()->getLocaleByCode($details->getTranslationLocaleCode());
    }

    /**
     * Returns the default homepage ID
     * @return Integer The page ID or -1 if there are none
     */
    public function getDefaultHomepageId()
    {
        if ($this->hasHomePage()) {
            return $this->pageOnFront();
        }

        return -1;
    }

    /**
     * @see https://codex.wordpress.org/Option_Reference
     * @return Boolean True if the current app has a page for home page
     */
    private function hasHomePage()
    {
        return get_option('show_on_front') == "page";
    }

    /**
     * Returns a cached reference to Wordpress' page_on_front option
     * @see https://codex.wordpress.org/Option_Reference
     * @return Integer The page ID
     */
    private function pageOnFront()
    {
        return (int)get_option('page_on_front');
    }

    /**
     * Converts DB rows into translation entities
     * @param  array $rows
     * @return array
     */
    protected function rowsToEntities($rows)
    {
        $results = array();
        foreach ($rows as $row) {
            $results[] = TranslationEntity::factory($row);
        }
        return $results;
    }

    protected function cachePageAtId($id)
    {
        global $wpdb;

        $this->logger->logQueryStart();

        $app = Strata::app();
        $configValue = (int)$app->getConfig("i18n.cache_page_size");
        $cachePageSize = $configValue > 0 ? $configValue : $this->pageSize;

        // Don't reload the same ids, if we have already cached rows.
        $count = count($this->cachedIds);
        $notIn = $count > 0 ?  " AND polyglot_ID NOT IN (".implode(",", $this->cachedIds) .")" : "";

        $records = $wpdb->get_results($wpdb->prepare("
            SELECT *
            FROM {$wpdb->prefix}polyglot
            WHERE polyglot_ID > %d
            $notIn
            ORDER BY polyglot_ID
            LIMIT $cachePageSize",
            $id
        ));

        $this->logger->logQueryCompletion($wpdb->last_query);

        foreach ($records as $record) {
            $this->cache->addEntity(TranslationEntity::factory($record));
        }

        $this->nbPageSaved++;
    }

    private function cacheIsComplete()
    {
        return $this->cache->getNumberOfCachedRecords() >= $this->numberOfRecords;
    }
}
