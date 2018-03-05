<?php

namespace EasternColor\NgXBundle\Helpers;

use EasternColor\NgXBundle\Annotations\ApiStructure\Property;
use EasternColor\NgXBundle\Annotations\ApiStructure\Structure;
use ReflectionMethod;
use Stringy\Stringy;
use Symfony\Component\Finder\Finder;

class RoutesHelper
{
    public static function getRouteFileMapping($srcPath, $contains)
    {
        $routingXmlFinder = (new Finder())->in($srcPath)->path('Resources/config')->contains($contains)->name('*.xml');
        $routeNames = [];
        foreach ($routingXmlFinder as $routingXmlFile) {
            $bundleDirectory = realpath(strstr($routingXmlFile->getPathname(), 'Resources', true));
            $routingXml = $routingXmlFile->getPathname();
            $loader = new \Symfony\Component\Routing\Loader\XmlFileLoader(new \Symfony\Component\Config\FileLocator($bundleDirectory));
            $routes = $loader->load($routingXml);
            /* @var $route Symfony\Component\Routing\Route */
            foreach ($routes->getIterator() as $name => $route) {
                $routeNames[$name] = $routingXml;
            }
        }

        return $routeNames;
    }

    public static function getRouteNamePrefix($routes)
    {
        $routeNames = [];
        /* @var $route Symfony\Component\Routing\Route */
        foreach ($routes as $name => $route) {
            $routeNames[] = explode('_', $name);
        }
        if (1 === count($routeNames)) {
            $routeNameCommanParts = array_slice($routeNames[0], 0, array_search('api', $routeNames[0]));
        } else {
            $routeNameCommanParts = call_user_func_array('array_intersect', $routeNames);
        }

        return implode('_', $routeNameCommanParts).'_';
    }

    public static function getRouteTwigVars($io, $annotationCachedReader, $name, $params)
    {
        // $routes = [];
        if (strstr($params->getDefault('_controller'), '::')) {
            list($className, $methodName) = explode('::', $params->getDefault('_controller'));
        } else {
            list($bundleName, $ctrlName, $methodName) = explode(':', $params->getDefault('_controller'));
            $className = str_replace(['EasternColor', 'Bundle', '/'], ['EasternColor\\', 'Bundle\\Controller\\', '\\'], $bundleName.$ctrlName).'Controller';
            $methodName .= 'Action';
        }
        /* @var $structureAnnotation Structure */
        $structureAnnotation = $annotationCachedReader->getMethodAnnotation(new ReflectionMethod($className, $methodName), Structure::class);
        $item = [];
        $item['api_structure'] = ['fields' => []];
        if (null !== $structureAnnotation) {
            /* @var $field Property */
            $io->text('foreach annotation fields L:'.__LINE__.'@'.__FILE__);
            foreach ($structureAnnotation->fields as $field) {
                $item['api_structure']['fields'][] = $field;
            }
        }
        $item['pathname'] = $params->getPath();
        $methods = array_filter($params->getMethods(), function ($val) { return !in_array($val, ['OPTIONS']); });
        $item['method'] = $methods[0];
        $item['name_pascal_cased'] = (new Stringy($name))->upperCamelize();
        // $item['route_parameters'] = [];
        preg_match_all('|{([^}]+)}|', $params->getPath(), $matches);
        $item['route_parameters'] = [];
        $item['route_parameters_as_function_parameters'] = [];
        if (count($matches) > 0) {
            $item['route_parameters'] = $matches[1];
            $io->text('foreach route parameters L:'.__LINE__.'@'.__FILE__);
            foreach ($matches[1] as $routeParameter) {
                $item['route_parameters_as_function_parameters'][] = $routeParameter.': string';
            }
        }
        // $routes[$name] = $item;
        return $item;
    }
}
