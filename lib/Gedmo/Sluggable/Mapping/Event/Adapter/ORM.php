<?php

namespace Gedmo\Sluggable\Mapping\Event\Adapter;

use Doctrine\ORM\Query;
use Gedmo\Mapping\Event\Adapter\ORM as BaseAdapterORM;
use Gedmo\Sluggable\Mapping\Event\SluggableAdapter;
use Gedmo\Tool\Wrapper\AbstractWrapper;

/**
 * Doctrine event adapter for ORM adapted
 * for sluggable behavior
 *
 * @author Gediminas Morkevicius <gediminas.morkevicius@gmail.com>
 * @package Gedmo\Sluggable\Mapping\Event\Adapter
 * @subpackage ORM
 * @link http://www.gediminasm.org
 * @license MIT License (http://www.opensource.org/licenses/mit-license.php)
 */
final class ORM extends BaseAdapterORM implements SluggableAdapter
{
    /**
     * {@inheritDoc}
     */
    public function getDefaultSlugEntryClass()
    {
        return 'Gedmo\\Sluggable\\Entity\\SlugEntry';
    }

	/**
     * {@inheritDoc}
     */
    public function getSimilarSlugs($object, $meta, array $config, $slug)
    {
        $em = $this->getObjectManager();
        $wrapped = AbstractWrapper::wrap($object, $em);
        $qb = $em->createQueryBuilder();
        $qb->select('rec.' . $config['slug'])
            ->where($qb->expr()->like(
                'rec.' . $config['slug'],
                $qb->expr()->literal($slug . '%'))
            )
        ;
        // include identifiers
        foreach ((array)$wrapped->getIdentifier(false) as $id => $value) {
            if (!$meta->isIdentifier($config['slug'])) {
                $qb->andWhere($qb->expr()->neq('rec.' . $id, ':' . $id));
                $qb->setParameter($id, $value);
            }
        }

        $this->addUniqueGroupsToQueryBuilder($qb, $object, $config);

        $qb->from($config['useObjectClass'], 'rec');

        $q = $qb->getQuery();
        $q->setHydrationMode(Query::HYDRATE_ARRAY);
        return $q->execute();
    }

    /**
     * {@inheritDoc}
     */
    public function replaceRelative($object, array $config, $target, $replacement)
    {
        $em = $this->getObjectManager();
        $qb = $em->createQueryBuilder();
        $qb->update($config['useObjectClass'], 'rec')
            ->set('rec.'.$config['slug'], $qb->expr()->concat(
                $qb->expr()->literal($replacement),
                $qb->expr()->substring('rec.'.$config['slug'], strlen($target))
            ))
            ->where($qb->expr()->like(
                'rec.'.$config['slug'],
                $qb->expr()->literal($target . '%'))
            )
        ;

        $this->addUniqueGroupsToQueryBuilder($qb, $object, $config);

        // update in memory
        $q = $qb->getQuery();
        return $q->execute();
    }

    /**
    * {@inheritDoc}
     */
    public function replaceInverseRelative($object, array $config, $target, $replacement)
    {
        $em = $this->getObjectManager();
        $qb = $em->createQueryBuilder();
        $qb->update($config['useObjectClass'], 'rec')
            ->set('rec.'.$config['slug'], $qb->expr()->concat(
               $qb->expr()->literal($target),
                $qb->expr()->substring('rec.'.$config['slug'], strlen($replacement)+1)
            ))
            ->where('rec.'.$config['mappedBy'].' = :object')
        ;

        $this->addUniqueGroupsToQueryBuilder($qb, $object, $config);

        $q = $qb->getQuery();
        $q->setParameters(compact('object'));
        return $q->execute();
    }

    private function addUniqueGroupsToQueryBuilder($qb, $object, &$config) {
        $meta = $this->getObjectManager()->getClassMetadata(get_class($object));

        // unique groups
        if ($config['unique'] && $config['uniqueGroups']) {
            foreach ($config['uniqueGroups'] as $group) {
                if ($meta->discriminatorColumn && $meta->discriminatorColumn['name'] == $group) {
                    $config['useObjectClass'] = $meta->name;
                } else {
                    $qb->andWhere($qb->expr()->eq("rec.{$group}", ":group_{$group}"));
                    $qb->setParameter("group_{$group}", $meta->getReflectionProperty($group)->getValue($object));
                }
            }
        }
    }

}
