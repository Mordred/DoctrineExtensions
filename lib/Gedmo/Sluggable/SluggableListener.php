<?php

namespace Gedmo\Sluggable;

use Doctrine\Common\EventArgs;
use Gedmo\Mapping\MappedEventSubscriber;
use Gedmo\Sluggable\Mapping\Event\SluggableAdapter;
use Doctrine\Common\Persistence\ObjectManager;
use Gedmo\Tool\Wrapper\AbstractWrapper;

/**
 * The SluggableListener handles the generation of slugs
 * for documents and entities.
 *
 * This behavior can inpact the performance of your application
 * since it does some additional calculations on persisted objects.
 *
 * @author Gediminas Morkevicius <gediminas.morkevicius@gmail.com>
 * @author Klein Florian <florian.klein@free.fr>
 * @author Martin Jantosovic <jantosovic.martin@gmail.com>
 * @subpackage SluggableListener
 * @package Gedmo.Sluggable
 * @link http://www.gediminasm.org
 * @license MIT License (http://www.opensource.org/licenses/mit-license.php)
 */
class SluggableListener extends MappedEventSubscriber
{

    /**
     * Update action
     */
    const ACTION_UPDATE = 'update';

    /**
     * Remove action
     */
    const ACTION_REMOVE = 'remove';

    /**
     * The power exponent to jump
     * the slug unique number by tens.
     *
     * @var integer
     */
    private $exponent = 0;

    /**
     * Transliteration callback for slugs
     *
     * @var array
     */
    private $transliterator = array('Gedmo\Sluggable\Util\Urlizer', 'transliterate');

    /**
     * List of inserted slugs for each object class.
     * This is needed in case there are identical slug
     * composition in number of persisted objects
     *
     * @var array
     */
    private $persistedSlugs = array();

    /**
     * List of initialized slug handlers
     *
     * @var array
     */
    private $handlers = array();

    /**
     * List of filters which are manipulated when slugs are generated
     *
     * @var array
     */
    private $managedFilters = array();

    /**
     * Specifies the list of events to listen
     *
     * @return array
     */
    public function getSubscribedEvents()
    {
        return array(
            'onFlush',
            'loadClassMetadata',
            'prePersist'
        );
    }

    /**
     * Set the transliteration callable method
     * to transliterate slugs
     *
     * @param mixed $callable
     * @throws \Gedmo\Exception\InvalidArgumentException
     * @return void
     */
    public function setTransliterator($callable)
    {
        if (!is_callable($callable)) {
            throw new \Gedmo\Exception\InvalidArgumentException('Invalid transliterator callable parameter given');
        }
        $this->transliterator = $callable;
    }

    /**
     * Get currently used transliterator callable
     *
     * @return callable
     */
    public function getTransliterator()
    {
        return $this->transliterator;
    }

    /**
     * Enables or disables the given filter when slugs are generated
     *
     * @param string $name
     * @param bool $disable True by default
     */
    public function addManagedFilter($name, $disable = true)
    {
        $this->managedFilters[$name] = array('disabled' => $disable);
    }

    /**
     * Removes a filter from the managed set
     *
     * @param string $name
     */
    public function removeManagedFilter($name)
    {
        unset($this->managedFilters[$name]);
    }

    /**
     * Mapps additional metadata
     *
     * @param EventArgs $eventArgs
     * @return void
     */
    public function loadClassMetadata(EventArgs $eventArgs)
    {
        $ea = $this->getEventAdapter($eventArgs);
        $this->loadMetadataForObjectClass($ea->getObjectManager(), $eventArgs->getClassMetadata());
    }

    /**
     * Allows identifier fields to be slugged as usual
     *
     * @param EventArgs $args
     * @return void
     */
    public function prePersist(EventArgs $args)
    {
        $ea = $this->getEventAdapter($args);
        $om = $ea->getObjectManager();
        $object = $ea->getObject();
        $meta = $om->getClassMetadata(get_class($object));

        if ($config = $this->getConfiguration($om, $meta->name)) {
            foreach ($config['slugs'] as $slugField => $options) {
                if ($meta->isIdentifier($slugField)) {
                    $meta->getReflectionProperty($slugField)->setValue($object, '__id__');
                }
            }
        }
    }

    /**
     * Generate slug on objects being updated during flush
     * if they require changing
     *
     * @param EventArgs $args
     * @return void
     */
    public function onFlush(EventArgs $args)
    {
        $this->persistedSlugs = array();
        $ea = $this->getEventAdapter($args);
        $om = $ea->getObjectManager();
        $uow = $om->getUnitOfWork();

        $this->manageFiltersBeforeGeneration($om);

        // process all objects being inserted, using scheduled insertions instead
        // of prePersist in case if record will be changed before flushing this will
        // ensure correct result. No additional overhead is encoutered
        foreach ($ea->getScheduledObjectInsertions($uow) as $object) {
            $meta = $om->getClassMetadata(get_class($object));
            if ($config = $this->getConfiguration($om, $meta->name)) {
                // generate first to exclude this object from similar persisted slugs result
                $this->generateSlug($ea, $object);
                foreach ($config['slugs'] as $slugField => $options) {
                    $slug = $meta->getReflectionProperty($slugField)->getValue($object);
                    $this->persistedSlugs[$config['useObjectClass']][$slugField][] = $slug;
                }
            }
        }
        // we use onFlush and not preUpdate event to let other
        // event listeners be nested together
        foreach ($ea->getScheduledObjectUpdates($uow) as $object) {
            $meta = $om->getClassMetadata(get_class($object));
            if (($config = $this->getConfiguration($om, $meta->name)) && !$uow->isScheduledForInsert($object)) {
                $this->generateSlug($ea, $object);
                foreach ($config['slugs'] as $slugField => $options) {
                    $slug = $meta->getReflectionProperty($slugField)->getValue($object);
                    $this->persistedSlugs[$config['useObjectClass']][$slugField][] = $slug;
                    // Store old slug to slug history
                    $changeSet = $ea->getObjectChangeSet($uow, $object);
                    if (isset($config['slugHistory']) && $config['slugHistory']
                        && isset($changeSet[$slugField]) && $changeSet[$slugField][0])
                        $this->createSlugEntry($slugField, $changeSet[$slugField][0], $object, $ea);
                }
            }
        }
        // Delete all slug history entries
        // if the entity is deleted
        foreach ($ea->getScheduledObjectDeletions($uow) as $object) {
            $meta = $om->getClassMetadata(get_class($object));
            if (($config = $this->getConfiguration($om, $meta->name))
                    && isset($config['slugHistory']) && $config['slugHistory']) {
                foreach ($config['slugs'] as $slugField => $options) {
                    $this->deleteSlugEntries($slugField, $object, $ea);
                }
            }
        }

        $this->manageFiltersAfterGeneration($om);
    }

    /**
     * {@inheritDoc}
     */
    protected function getNamespace()
    {
        return __NAMESPACE__;
    }

    /**
     * Get the slug handler instance by $class name
     *
     * @param string $class
     * @return Gedmo\Sluggable\Handler\SlugHandlerInterface
     */
    private function getHandler($class)
    {
        if (!isset($this->handlers[$class])) {
            $this->handlers[$class] = new $class($this);
        }
        return $this->handlers[$class];
    }

    /**
     * Creates the slug for object being flushed
     *
     * @param SluggableAdapter $ea
     * @param object $object
     * @throws UnexpectedValueException - if parameters are missing
     *      or invalid
     * @return void
     */
    private function generateSlug(SluggableAdapter $ea, $object)
    {
        $om = $ea->getObjectManager();
        $meta = $om->getClassMetadata(get_class($object));
        $uow = $om->getUnitOfWork();
        $changeSet = $ea->getObjectChangeSet($uow, $object);
        $isInsert = $uow->isScheduledForInsert($object);
        $config = $this->getConfiguration($om, $meta->name);
        foreach ($config['slugs'] as $slugField => $options) {
            $hasHandlers = count($options['handlers']);
            $options['useObjectClass'] = $config['useObjectClass'];
            // collect the slug from fields
            $slug = $meta->getReflectionProperty($slugField)->getValue($object);
            // if slug should not be updated, skip it
            if (!$options['updatable'] && !$isInsert && (!isset($changeSet[$slugField]) || $slug === '__id__')) {
                continue;
            }
            $needToChangeSlug = false;
            // if slug is null or set to empty, regenerate it, or needs an update
            if (empty($slug) || $slug === '__id__' || !isset($changeSet[$slugField])) {
                $slug = '';
                foreach ($options['fields'] as $sluggableField) {
                    if (isset($changeSet[$sluggableField]) || isset($changeSet[$slugField])) {
                        $needToChangeSlug = true;
                    }
                    $slug .= $meta->getReflectionProperty($sluggableField)->getValue($object) . ' ';
                }
            } else {
                // slug was set manually
                $needToChangeSlug = true;
            }
            $slug = trim($slug);

            // notify slug handlers --> onChangeDecision
            if ($hasHandlers) {
                foreach ($options['handlers'] as $class => $handlerOptions) {
                    $this->getHandler($class)->onChangeDecision($ea, $options, $object, $slug, $needToChangeSlug);
                }
            }
            // if slug is changed, do further processing
            if ($needToChangeSlug) {
                $mapping = $meta->getFieldMapping($slugField);
                if (!strlen(trim($slug)) && (!isset($mapping['nullable']) || !$mapping['nullable'])) {
                    throw new \Gedmo\Exception\UnexpectedValueException("Unable to find any non empty sluggable fields for slug [{$slugField}] , make sure they have something at least.");
                }

                // notify slug handlers --> postSlugBuild
                $urlized = false;
                if ($hasHandlers) {
                    foreach ($options['handlers'] as $class => $handlerOptions) {
                        $this->getHandler($class)->postSlugBuild($ea, $options, $object, $slug);
                        if ($this->getHandler($class)->handlesUrlization()) {
                            $urlized = true;
                        }
                    }
                }

                // build the slug
                // Step 1: transliteration, changing 北京 to 'Bei Jing'
                $slug = call_user_func_array(
                    $this->transliterator,
                    array($slug, $options['separator'], $object)
                );
                // Step 2: urlization (replace spaces by '-' etc...)
                if(!$urlized){
                    $slug = Util\Urlizer::urlize($slug, $options['separator'], $options['allowed']);
                }
                // stylize the slug
                switch ($options['style']) {
                    case 'camel':
                        $slug = preg_replace_callback('/^[a-z]|' . $options['separator'] . '[a-z]/smi', function ($m) {
                            return strtoupper($m[0]);
                        }, $slug);
                        break;

                    case 'lower':
                        if (function_exists('mb_strtolower')) {
                            $slug = mb_strtolower($slug);
                        } else {
                            $slug = strtolower($slug);
                        }
                        break;

                    case 'upper':
                        if (function_exists('mb_strtoupper')) {
                            $slug = mb_strtoupper($slug);
                        } else {
                            $slug = strtoupper($slug);
                        }
                        break;

                    default:
                        // leave it as is
                        break;
                }

                // cut slug if exceeded in length
                if (isset($mapping['length']) && strlen($slug) > $mapping['length']) {
                    $slug = substr($slug, 0, $mapping['length']);
                }

                if (isset($mapping['nullable']) && $mapping['nullable'] && !$slug) {
                    $slug = null;
                }
                // make unique slug if requested
                if ($options['unique'] && !is_null($slug)) {
                    $this->exponent = 0;
                    $slug = $this->makeUniqueSlug($ea, $object, $slug, false, $options);
                }
                // notify slug handlers --> onSlugCompletion
                if ($hasHandlers) {
                    foreach ($options['handlers'] as $class => $handlerOptions) {
                        $this->getHandler($class)->onSlugCompletion($ea, $options, $object, $slug, isset($config['slugHistory']) ? $config['slugHistory'] : FALSE);
                    }
                }
                // set the final slug
                $meta->getReflectionProperty($slugField)->setValue($object, $slug);
                // recompute changeset
                $ea->recomputeSingleObjectChangeSet($uow, $meta, $object);
            }
        }
    }

    /**
     * Generates the unique slug
     *
     * @param SluggableAdapter $ea
     * @param object $object
     * @param string $preferedSlug
     * @param boolean $recursing
     * @param array $config[$slugField]
     * @return string - unique slug
     */
    private function makeUniqueSlug(SluggableAdapter $ea, $object, $preferedSlug, $recursing = false, $config = array())
    {
        $om = $ea->getObjectManager();
        $meta = $om->getClassMetadata(get_class($object));
        // load similar slugs
        $result = array_merge(
            (array) $ea->getSimilarSlugs($object, $meta, $config, $preferedSlug),
            (array) $this->getSimilarPersistedSlugs($config['useObjectClass'], $preferedSlug, $config['slug'])
        );
        // leave only right slugs
        if (!$recursing) {
            $this->filterSimilarSlugs($result, $config, $preferedSlug);
        }

        if ($result) {
            $generatedSlug = $preferedSlug;
            $sameSlugs = array();
            foreach ((array)$result as $list) {
                $sameSlugs[] = $list[$config['slug']];
            }

            $i = pow(10, $this->exponent);
            do {
                $generatedSlug = $preferedSlug . $config['separator'] . $i++;
            } while (in_array($generatedSlug, $sameSlugs));

            $mapping = $meta->getFieldMapping($config['slug']);
            if (isset($mapping['length']) && strlen($generatedSlug) > $mapping['length']) {
                $generatedSlug = substr(
                    $generatedSlug,
                    0,
                    $mapping['length'] - (strlen($i) + strlen($config['separator']))
                );
                $this->exponent = strlen($i) - 1;
                if(substr($generatedSlug,-strlen($config['separator'])) == $config['separator']) {
                    $generatedSlug = substr($generatedSlug,0,strlen($generatedSlug) - strlen($config['separator']));
                }
                $generatedSlug = $this->makeUniqueSlug($ea, $object, $generatedSlug, true, $config);
            }
            $preferedSlug = $generatedSlug;
        }
        return $preferedSlug;
    }

    /**
     * In case if any number of records are persisted instantly
     * and they contain same slugs. This method will filter those
     * identical slugs specialy for persisted objects. Returns
     * array of similar slugs found
     *
     * @param string $class
     * @param string $preferedSlug
     * @param string $slugField
     * @return array
     */
    private function getSimilarPersistedSlugs($class, $preferedSlug, $slugField)
    {
        $result = array();
        if (isset($this->persistedSlugs[$class][$slugField])) {
            array_walk($this->persistedSlugs[$class][$slugField], function($val) use ($preferedSlug, &$result, $slugField) {
                if (preg_match("@^{$preferedSlug}.*@smi", $val)) {
                    $result[] = array($slugField => $val);
                }
            });
        }
        return $result;
    }

    /**
     * Filters $slugs which are matched as prefix but are
     * simply shorter slugs
     *
     * @param array $slugs
     * @param array $config
     * @param string $prefered
     */
    private function filterSimilarSlugs(array &$slugs, array &$config, $prefered)
    {
        foreach ($slugs as $key => $similar) {
            if (!preg_match("@{$prefered}($|{$config['separator']}[\d]+$)@smi", $similar[$config['slug']])) {
                unset($slugs[$key]);
            }
        }
    }

    /**
     * Create a new Slug history instance
     *
     * @param object $object
     * @param LoggableAdapter $ea
     * @return void
     */
    public function createSlugEntry($field, $slug, $object, SluggableAdapter $ea)
    {
        $om = $ea->getObjectManager();
        $wrapped = AbstractWrapper::wrap($object, $om);
        $meta = $wrapped->getMetadata();
        $uow = $om->getUnitOfWork();

        $slugEntryClass = $this->getSlugEntryClass($ea, $meta->name);
        $slugEntryMeta = $om->getClassMetadata($slugEntryClass);

        $repository = $om->getRepository($slugEntryClass);
        $slugEntry = $repository->findOneBy(array(
            'slugField' => $field,
            'slugValue' => $slug,
            'objectClass' => $meta->name
        ));

        if ($slugEntry) { // Redefine slug
            $slugEntry->setObjectId($wrapped->getIdentifier());
            $slugEntry->setCreated();
        } else { // Slug is not in the history
            $slugEntry = $slugEntryMeta->newInstance();

            $slugEntry->setObjectClass($meta->name);
            $slugEntry->setCreated();
            $slugEntry->setObjectId($wrapped->getIdentifier());
            $slugEntry->setSlugField($field);
            $slugEntry->setSlugValue($slug);
        }

        $om->persist($slugEntry);
        $uow->computeChangeSet($slugEntryMeta, $slugEntry);
    }

    /**
     * Delete a Slug history instance
     *
     * @param object $object
     * @param LoggableAdapter $ea
     * @return void
     */
    protected function deleteSlugEntries($field, $object, SluggableAdapter $ea)
    {
        $om = $ea->getObjectManager();
        $wrapped = AbstractWrapper::wrap($object, $om);
        $meta = $wrapped->getMetadata();
        if ($config = $this->getConfiguration($om, $meta->name)) {

            $slugEntryClass = $this->getSlugEntryClass($ea, $meta->name);
            $slugEntryMeta = $om->getClassMetadata($slugEntryClass);

            $repository = $om->getRepository($slugEntryClass);
            $slugEntries = $repository->findBy(array(
                'objectClass' => $meta->name,
                'objectId' => $wrapped->getIdentifier()
            ));

            foreach ($slugEntries as $slugEntry) {
                $om->remove($slugEntry);
            }
        }
    }

    /**
     * @param \Doctrine\Common\Persistence\ObjectManager $om
     */
    private function manageFiltersBeforeGeneration(ObjectManager $om)
    {
        $collection = $this->getFilterCollectionFromObjectManager($om);

        $enabledFilters = array_keys($collection->getEnabledFilters());

        // set each managed filter to desired status
        foreach ($this->managedFilters as $name => &$config) {
            $enabled = in_array($name, $enabledFilters);
            $config['previouslyEnabled'] = $enabled;

            if ($config['disabled']) {
                $collection->disable($name);
            } else {
                $collection->enable($name);
            }
        }
    }

    /**
     * @param \Doctrine\Common\Persistence\ObjectManager $om
     */
    private function manageFiltersAfterGeneration(ObjectManager $om)
    {
        $collection = $this->getFilterCollectionFromObjectManager($om);

        // Restore managed filters to their original status
        foreach ($this->managedFilters as $name => &$config) {
            if ($config['previouslyEnabled'] === true) {
                $collection->enable($name);
            }

            unset($config['previouslyEnabled']);
        }
    }

    /**
     * Get the SlugEntry class
     *
     * @param LoggableAdapter $ea
     * @param string $class
     * @return string
     */
    protected function getSlugEntryClass(SluggableAdapter $ea, $class)
    {
        return isset(self::$configurations[$this->name][$class]['slugEntryClass']) ?
            self::$configurations[$this->name][$class]['slugEntryClass'] :
            $ea->getDefaultSlugEntryClass();
    }

    /**
     * Retrieves a FilterCollection instance from the given ObjectManager.
     *
     * @param \Doctrine\Common\Persistence\ObjectManager $om
     * @throws \Gedmo\Exception\InvalidArgumentException
     * @return mixed
     */
    private function getFilterCollectionFromObjectManager(ObjectManager $om)
    {
        if (is_callable(array($om, 'getFilters'))) {
            return $om->getFilters();
        } else if (is_callable(array($om, 'getFilterCollection'))) {
            return $om->getFilterCollection();
        }

        throw new \Gedmo\Exception\InvalidArgumentException("ObjectManager does not support filters");
    }

}
