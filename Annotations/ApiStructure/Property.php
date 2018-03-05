<?php

namespace EasternColor\NgXBundle\Annotations\ApiStructure;

use Doctrine\Common\Annotations\Annotation;
use Doctrine\Common\Annotations\Target;

/**
 * @Annotation
 * @Target({"ANNOTATION"})
 */
class Property
{
    public $name = '';
    public $type = '';
}
