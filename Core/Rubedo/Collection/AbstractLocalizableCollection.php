<?php
/**
 * Rubedo -- ECM solution
 * Copyright (c) 2013, WebTales (http://www.webtales.fr/).
 * All rights reserved.
 * licensing@webtales.fr
 *
 * Open Source License
 * ------------------------------------------------------------------------------------------
 * Rubedo is licensed under the terms of the Open Source GPL 3.0 license.
 *
 * @category   Rubedo
 * @package    Rubedo
 * @copyright  Copyright (c) 2012-2013 WebTales (http://www.webtales.fr)
 * @license    http://www.gnu.org/licenses/gpl.html Open Source GPL 3.0 license
 */
namespace Rubedo\Collection;

use Rubedo\Interfaces\Collection\IAbstractCollection, Rubedo\Services\Manager, WebTales\MongoFilters\Filter;

/**
 * Class implementing the API to MongoDB for localizable collections
 *
 * @author jbourdin
 * @category Rubedo
 * @package Rubedo
 */
abstract class AbstractLocalizableCollection extends AbstractCollection
{

    protected static $defaultLocale = 'en';

    /**
     * Contain common fields
     */
    protected static $globalNonLocalizableFields = array(
        '_id',
        'id',
        'idLabel',
        'createTime',
        'createUser',
        'lastUpdateTime',
        'lastUpdateUser',
        'lastPendingTime',
        'lastPendingUser',
        'version',
        'online',
        'text',
        'nativeLanguage',
        'i18n',
        'workspace',
        'orderValue',
        'parentId'
    );
    
    protected static $nonLocalizableFields = array();

    /**
     * Current service locale
     *
     * @var string null
     */
    protected static $workingLocale = null;

    protected static $includeI18n = true;

    /**
     * Do a find request on the current collection
     *
     * @param array $filters
     *            filter the list with mongo syntax
     * @param array $sort
     *            sort the list with mongo syntax
     * @return array
     */
    public function getList(\WebTales\MongoFilters\IFilter $filters = null, $sort = null, $start = null, $limit = null)
    {
        $dataValues = parent::getList($filters, $sort, $start, $limit);
        if ($dataValues && is_array($dataValues)) {
            foreach ($dataValues['data'] as &$obj) {
                $obj = $this->localizeOutput($obj);
            }
        }
        
        return $dataValues;
    }

    /**
     * Find an item given by its literral ID
     *
     * @param string $contentId            
     * @param boolean $forceReload
     *            should we ensure reading up-to-date content
     * @return array
     */
    public function findById($contentId, $forceReload = false)
    {
        $obj = parent::findById($contentId, $forceReload);
        return $this->localizeOutput($obj);
    }

    /**
     * Find an item given by its name (find only one if many)
     *
     * @param string $name            
     * @return array
     */
    public function findByName($name)
    {
        $obj = parent::findByName($name);
        return $this->localizeOutput($obj);
    }

    /**
     * Do a findone request
     *
     * @param \WebTales\MongoFilters\IFilter $value
     *            search condition
     * @return array
     */
    public function findOne(\WebTales\MongoFilters\IFilter $value)
    {
        $obj = parent::findOne($value);
        return $this->localizeOutput($obj);
    }

    /**
     * Create an objet in the current collection
     *
     * @see \Rubedo\Interfaces\IDataAccess::create
     * @param array $obj
     *            data object
     * @param array $options            
     * @return array
     */
    public function create(array $obj, $options = array())
    {
        $this->_filterInputData($obj);
        
        unset($obj['readOnly']);
        return $this->_dataService->create($obj, $options);
    }

    /**
     * Update an objet in the current collection
     *
     * @see \Rubedo\Interfaces\IDataAccess::update
     * @param array $obj
     *            data object
     * @param array $options            
     * @return array
     */
    public function update(array $obj, $options = array())
    {
        unset($obj['readOnly']);
        $obj = $this->localizeInput($obj);
        return $this->_dataService->update($obj, $options);
    }
    
    /*
     * (non-PHPdoc) @see \Rubedo\Interfaces\Collection\IAbstractCollection::count()
     */
    public function count(\WebTales\MongoFilters\IFilter $filters = null)
    {
        return $this->_dataService->count($filters);
    }

    /**
     * Find child of a node tree
     *
     * @param string $parentId
     *            id of the parent node
     * @param \WebTales\MongoFilters\IFilter $filters
     *            array of data filters (mongo syntax)
     * @param array $sort
     *            array of data sorts (mongo syntax)
     * @return array children array
     */
    public function readChild($parentId, \WebTales\MongoFilters\IFilter $filters = null, $sort = null)
    {
        $result = parent::readChild($parentId, $filters, $sort);
        if ($result && is_array($result)) {
            foreach ($result as &$obj) {
                $obj = $this->localizeOutput($obj);
            }
        }
        return $result;
    }

    /**
     * (non-PHPdoc)
     *
     * @see \Rubedo\Collection\AbstractCollection::readTree()
     * @todo add parse for localization
     */
    public function readTree(\WebTales\MongoFilters\IFilter $filters = null)
    {
        // ...
        $tree = $this->_dataService->readTree($filters);
        return $tree['children'];
    }

    /**
     *
     * @param array $obj
     *            collection item
     * @return array collection item localized
     */
    protected function localizeOutput($obj)
    {
        if ($obj === null) {
            return $obj;
        }
        if (! isset($obj['i18n'])) {
            return $obj;
        }
        if (static::$workingLocale === null) {
            if (! isset($obj['nativeLanguage'])) {
                return $obj;
            } else {
                $locale = $obj['nativeLanguage'];
            }
        } else {
            $locale = static::$workingLocale;
        }
        
        if (! isset($obj['i18n'][$locale])) {
            if (! isset($obj['nativeLanguage'])) {
                throw new Rubedo\Exceptions\Server('No defined native language for this item');
            }
            $locale = $obj['nativeLanguage'];
        }
        
        if (! isset($obj['i18n'][$locale])) {
            throw new Rubedo\Exceptions\Server('No localized data are available for this item');
        }
        
        $obj = $this->merge($obj, $obj['i18n'][$locale]);
        
        if (! static::$includeI18n) {
            unset($obj['i18n']);
        }
        
        return $obj;
    }

    /**
     * Custom array_merge
     *
     * Do a recursive array merge except that numeric array are overriden
     *
     * @param array $array1            
     * @param array $array2            
     * @return array
     */
    protected function merge($array1, $array2)
    {
        foreach ($array2 as $key => $value) {
            if (isset($array1[$key]) && is_array($value) && ! $this->isNumericArray($value)) {
                $array1[$key] = $this->merge($array1[$key], $array2[$key]);
            } else {
                $array1[$key] = $value;
            }
        }
        return $array1;
    }

    /**
     * return true for array
     *
     * @param array $array            
     * @return boolean
     */
    protected function isNumericArray($array)
    {
        return $array === array_values($array);
    }

    /**
     *
     * @param array $obj
     *            collection item
     * @return array collection item localized
     */
    protected function localizeInput($obj)
    {
        
        
        foreach ($obj as $key => $field) {
            if (! in_array($key, $this->metaDataFields)) {
                unset($obj[$key]);
            }
        }
        return $obj;
    }

    public function addlocalization($obj)
    {
        if (isset($obj['nativeLanguage'])) {
            return $obj;
        }
        $nativeContent = $obj;
        
        foreach ($this->metaDataFields as $metaField) {
            unset($nativeContent[$metaField]);
            $nativeContent['locale'] = static::$defaultLocale;
        }
        foreach ($obj as $key => $field) {
            if (! in_array($key, $this->metaDataFields)) {
                unset($obj[$key]);
            }
        }
        $obj['nativeLanguage'] = static::$defaultLocale;
        $obj['i18n'] = array(
            static::$defaultLocale => $nativeContent
        );
        return $obj;
    }

    public static function addLocalizationForCollection()
    {
        $wasFiltered = parent::disableUserFilter();
        $service = new static();
        $items = $service->getList();
        
        foreach ($items['data'] as $item) {
            
            $item = $service->addlocalization($item);
            $service->customUpdate($item, Filter::factory('Uid')->setValue($item['id']));
            $service->update($item);
        }
        parent::disableUserFilter($wasFiltered);
    }

    /**
     *
     * @return the $defaultLocal
     */
    public static function getDefaultLocale()
    {
        return AbstractLocalizableCollection::$defaultLocale;
    }

    /**
     *
     * @param string $defaultLocal            
     */
    public static function setDefaultLocale($defaultLocal)
    {
        AbstractLocalizableCollection::$defaultLocale = $defaultLocal;
    }

    /**
     *
     * @return the $includeI18n
     */
    public static function getIncludeI18n()
    {
        return AbstractLocalizableCollection::$includeI18n;
    }

    /**
     *
     * @param boolean $includeI18n            
     */
    public static function setIncludeI18n($includeI18n)
    {
        AbstractLocalizableCollection::$includeI18n = $includeI18n;
    }

    /**
     *
     * @return the $workingLocale
     */
    public static function getWorkingLocale()
    {
        return AbstractLocalizableCollection::$workingLocale;
    }

    /**
     *
     * @param string $workingLocale            
     */
    public static function setWorkingLocale($workingLocale)
    {
        AbstractLocalizableCollection::$workingLocale = $workingLocale;
    }
    
    protected function getMetaDataFields(){
        if(!isset($this->metaDataFields)){
            $this->metaDataFields = array_merge(self::$globalNonLocalizableFields,static::$nonLocalizableFields);
        }
        return $this->metaDataFields;
    }
}
	