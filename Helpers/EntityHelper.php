<?php

namespace EasternColor\NgXBundle\Helpers;

use Exception;
use JMS\Serializer\SerializationContext;
use JMS\Serializer\Serializer;
use ReflectionClass;
use Stringy\Stringy;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Finder\Finder;

class EntityHelper
{
    protected static $finders = [];

    protected static $usedOtherBundleEntities = [];

    public static function getUsedOtherBundleEntities()
    {
        return static::$usedOtherBundleEntities;
    }

    public static function getFinder($srcPath)
    {
        if (!isset(static::$finders[$srcPath])) {
            static::$finders[$srcPath] = (new Finder())->path('Entity')->notPath('Traits')->name('*.php')->contains('JMS\Serializer\Annotation')->in($srcPath);
        }

        return static::$finders[$srcPath];
    }

    public static function getEntities(SymfonyStyle $io, Finder $finder, Serializer $serializer, $doctrine, $jmsGroups, $srcPath)
    {
        $entities = [];
        foreach ($finder as $file) {
            $entityFqcn = str_replace([$srcPath, '/', '.php'], '', $file->getPathname());
            if (!class_exists($entityFqcn) or strstr($file->getContents(), 'abstract class')) {
                $io->warning('Skipped: '.$file->getPathname());
                continue;
            } else {
                $bundleNs = static::getPrefixByEntityFqcn($entityFqcn);
                $objects = $doctrine->getRepository($entityFqcn)->findBy([], [], 10);

                if (count($objects) > 0) {
                    $temp = [];

                    // $io->text('foreach objects @'.__LINE__);
                    foreach ($objects as $index => $object) {
                        try {
                            $result = $serializer->toArray($object, SerializationContext::create()->setGroups($jmsGroups));
                            if (0 === $index) {
                                $io->text($bundleNs.': '.$entityFqcn);
                            }

                            // $io->text('foreach result @'.__LINE__.'  '.(is_array($result) ? 'y' : 'n'));
                            foreach ($result as $name => $value) {
                                if (!isset($temp[$name]) or 'any' === $temp[$name] or 'Array<any>' === $temp[$name]) {
                                    $temp[$name] = static::tsTypeMapping($object, $bundleNs, $name, $value);
                                }
                            }
                        } catch (Exception $ex) {
                            // $io->warning($ex->getTraceAsString());
                        }
                    }
                    // dump($entityFqcn, $temp);
                    $entities[$bundleNs][$bundleNs.$file->getBasename('.php')] = $temp;
                } else {
                    $io->warning(sprintf("Can't generate ApiStructure for %s as there are no entity in DB.", $entityFqcn));
                }
            }
        }

        return $entities;
    }

    public static function getJmsGroups(SymfonyStyle $io, Finder $finder)
    {
        $jmsGroups = [];
        /* @var $file SplFileInfo */
        foreach ($finder as $file) {
            if (preg_match('|@(JMS\\\\)*Groups\(\{"(?<name>.*)"\}\)|', $file->getContents(), $matches)) {
                $values = explode(',', $matches['name']);
                foreach ($values as $value) {
                    $jmsGroups[] = trim($value, ' "');
                }
            }
        }
        $jmsGroups = array_unique($jmsGroups);

        return $jmsGroups;
    }

    public static function tsTypeMapping($object, $bundleNs, $name, $value)
    {
        $type = gettype($value);
        $getterName = 'get'.(new Stringy($name))->upperCamelize();
        if ('json_dynamic_options' === $name) {
            return 'JsonDynamicOptions';
        }
        switch ($type) {
            case 'integer':
            case 'double':
                return 'number';
            case 'string':
                return $type;
            case 'array':
                if (static::isAssoc($value)) {
                    if ('json_trans' === $name and isset($value['en']) and static::isAssoc($value['en'])) {
                        return 'JsonTrans';
                    } elseif (is_object($object) and is_object($object->{$getterName}())) {
                        $reflectionClass = new ReflectionClass($object->{$getterName}());
                        $objectBundleNs = static::getPrefixByEntityFqcn($reflectionClass->getName());
                        if ($bundleNs !== $objectBundleNs) {
                            static::$usedOtherBundleEntities[$objectBundleNs][] = static::getTsEntityName($reflectionClass);
                        }

                        return 'Entity'.static::getTsEntityName($reflectionClass);
                    }
                } elseif (is_object($object) and $object->{$getterName}() instanceof PersistentCollection) {
                    $reflectionClass = $object->{$getterName}()->getTypeClass()->getReflectionClass();
                    $objectBundleNs = static::getPrefixByEntityFqcn($reflectionClass->getName());
                    if ($bundleNs !== $objectBundleNs) {
                        static::$usedOtherBundleEntities[$objectBundleNs][] = static::getTsEntityName($reflectionClass);
                    }

                    return 'Array<Entity'.static::getTsEntityName($reflectionClass).'>';
                } elseif (count($value) > 0) {
                    if ('string' === gettype($value[0])) {
                        return 'Array<string>';
                    } elseif ('integer' === gettype($value[0])) {
                        return 'Array<number>';
                    } elseif ('double' === gettype($value[0])) {
                        return 'Array<number>';
                    }
                }

                return 'any';
            default:
                return $type;
        }
    }

    protected static function isAssoc($var)
    {
        return is_array($var) && array_diff_key($var, array_keys(array_keys($var)));
    }

    protected static function getTsEntityName(ReflectionClass $reflectionClass)
    {
        return ''.static::getPrefixByEntityFqcn($reflectionClass->getName()).$reflectionClass->getShortName();
    }

    protected static function getPrefixByEntityFqcn($fqcn)
    {
        preg_match(':EasternColor\\\\(Ec|Img360)(?<prefix>.*)Bundle\\\\:', $fqcn, $matches);

        if ('V1' == $matches['prefix']) {
            $matches['prefix'] = 'Admin';
        }

        return $matches['prefix'];
    }
}
