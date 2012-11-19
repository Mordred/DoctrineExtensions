<?php

namespace Gedmo\Sluggable\Entity;

use Doctrine\ORM\Mapping\Table;
use Doctrine\ORM\Mapping\Index;
use Doctrine\ORM\Mapping\Entity;

/**
 * Gedmo\Sluggable\Entity\SlugEntry
 *
 * @Table(
 *     name="ext_slug_entries",
 *  indexes={
 *      @index(name="log_class_lookup_idx", columns={"slug", "object_class"}),
 *      @index(name="log_date_lookup_idx", columns={"created"})
 *  }
 * )
 * @Entity(repositoryClass="Gedmo\Sluggable\Entity\Repository\SlugEntryRepository")
 *
 * @author Martin Jantosovic <jantosovic.martin@gmail.com>
 * @package Gedmo\Sluggable\Entity
 * @subpackage SlugEntry
 * @license MIT License (http://www.opensource.org/licenses/mit-license.php)
 */
class SlugEntry extends MappedSuperclass\AbstractSlugEntry {
    /**
     * All required columns are mapped through inherited superclass
     */
}
