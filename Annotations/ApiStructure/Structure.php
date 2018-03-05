<?php

namespace EasternColor\NgXBundle\Annotations\ApiStructure;

use Doctrine\Common\Annotations\Annotation;
use Doctrine\Common\Annotations\Target;

/**
 * @Annotation
 * @Target({"METHOD"})
 */
class Structure
{
    /** @var int */
    public $version = 1;
    /** @var \EasternColor\NgXBundle\Annotations\ApiStructure\Property[] */
    public $fields = [];
}
