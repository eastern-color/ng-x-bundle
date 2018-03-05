<?php

namespace EasternColor\NgXBundle\Command;

use Doctrine\Common\Annotations\CachedReader;
use Doctrine\ORM\PersistentCollection;
use EasternColor\NgXBundle\Annotations\ApiStructure\Property;
use EasternColor\NgXBundle\Annotations\ApiStructure\Structure;
use Exception;
use JMS\Serializer\SerializationContext;
use JMS\Serializer\Serializer;
use ReflectionClass;
use ReflectionMethod;
use Stringy\Stringy;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;

class GenerateApiServiceCommand extends ContainerAwareCommand
{
    protected $bundle;
    protected $routingXml;
    protected $routeNamesPrefix;
    // protected $apiClassPrefix;
    // protected $apiClassFilePrefix;
    protected $apiServiceClassPrefix;
    protected $apiServiceClassFilePrefix;
    protected $bundleDirectory;
    protected $generateDestination;
    protected $twigContext = [];
    protected $usedOtherBundleEntities = [];

    protected function configure()
    {
        $this
          // the name of the command (the part after "bin/console")
          ->setName('ec:ngx:api')

          // the short description shown while running "php bin/console list"
          ->setDescription('Generate Api Service')

          // the full command description shown when running the command with
          // the "--help" option
          ->setHelp('This command is to Generate Api Service')

        //   ->addArgument('name', InputArgument::REQUIRED, 'Name')
        //   ->addArgument('route_prefix', InputArgument::REQUIRED, 'Route Prefix')
        //   ->addArgument('route_const_prefix', InputArgument::OPTIONAL, 'Route Const Prefix')
      ;
    }

    protected function tsTypeMapping($object, $name, $value)
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
                // if ($value === date('c', strtotime($value))) {
                //     return 'Date';
                // }

                return $type;
            case 'array':
                if (static::isAssoc($value)) {
                    if ('json_trans' === $name and isset($value['en']) and static::isAssoc($value['en'])) {
                        return 'JsonTrans';
                    } elseif (is_object($object->{$getterName}())) {
                        $reflectionClass = new ReflectionClass($object->{$getterName}());
                        if ($this->apiClassPrefix !== static::getPrefixByEntityFqcn($reflectionClass->getName())) {
                            $this->usedOtherBundleEntities[static::getPrefixByEntityFqcn($reflectionClass->getName())][] = static::getTsEntityName($reflectionClass);
                        }

                        return 'Entity'.static::getTsEntityName($reflectionClass);
                    }
                } elseif ($object->{$getterName}() instanceof PersistentCollection) {
                    $reflectionClass = $object->{$getterName}()->getTypeClass()->getReflectionClass();
                    if ($this->apiClassPrefix !== static::getPrefixByEntityFqcn($reflectionClass->getName())) {
                        $this->usedOtherBundleEntities[static::getPrefixByEntityFqcn($reflectionClass->getName())][] = static::getTsEntityName($reflectionClass);
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

    protected static function arrayKeyImploded($array)
    {
        $entityProperites = array_keys($array);
        asort($entityProperites);

        return implode('::', $entityProperites);
    }

    protected static function isAssoc($var)
    {
        return is_array($var) && array_diff_key($var, array_keys(array_keys($var)));
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Deprecated!!! [EasternColor] Generate Api Service');

        $this->getContainer()->get('doctrine')->getEntityManager()->getFilters()->disable('softdeleteable');

        $this->initQuestions($io, $input, $output);

        $io->text('=== renderEntities');
        $this->renderEntities($io, $input, $output);

        $io->text('=== renderApiService');
        $this->renderApiService($io, $input, $output);

        if (!is_dir($this->generateDestination)) {
            mkdir($this->generateDestination);
        }

        /* @var $twig Twig */
        $twig = $this->getContainer()->get('twig');
        $this->twigContext['last_modified'] = date('Y-m-d H:i:s');
        $this->twigContext['bundle_ns'] = $this->apiClassPrefix;
        $usedOtherBundleEntities = $this->usedOtherBundleEntities;

        $io->text('foreach $usedOtherBundleEntities @'.__LINE__);
        foreach (array_keys($usedOtherBundleEntities) as $bundleNs) {
            $usedOtherBundleEntities[$bundleNs] = array_unique($this->usedOtherBundleEntities[$bundleNs]);
        }
        $this->twigContext['used_other_bundle_entities'] = $usedOtherBundleEntities;

        $hasPostOrPut = false;
        $usedEntities = [];
        $io->text('foreach routes @'.__LINE__);
        foreach ($this->twigContext['routes'] as $name => $route) {
            if (!$hasPostOrPut and in_array($route['method'], ['POST', 'PUT'])) {
                $hasPostOrPut = true;
            }
            $io->text('foreach api_structure fields @'.__LINE__);
            foreach ($route['api_structure']['fields'] as $field) {
                if (preg_match('@(?<entity>Entity\w+)@', $field->type, $match)) {
                    $usedEntities[] = $match['entity'];
                }
            }
        }
        $this->twigContext['used_entities'] = array_unique($usedEntities);
        $this->twigContext['has_post_or_put'] = $hasPostOrPut;
        // dump($this->usedOtherBundleEntities, $this->twigContext);
        // exit;
        // $bundleEntitiesTsContent = $twig->render('EasternColorNgXBundle:Command:ApiService/BundleEntities.ts.twig', $this->twigContext);
        // file_put_contents($this->generateDestination.$this->apiClassFilePrefix.'-bundle-entities.ts', $bundleEntitiesTsContent);
        $apiServiceTsContent = $twig->render('EasternColorNgXBundle:Command:ApiService/ApiService.ts.twig', $this->twigContext);
        file_put_contents($this->generateDestination.$this->apiServiceClassFilePrefix.'-api-v1-service.ts', $apiServiceTsContent);
        // $apiStructureTsContent = $twig->render('EasternColorNgXBundle:Command:ApiService/ApiStructure.ts.twig', $this->twigContext);
        // file_put_contents($this->generateDestination.$this->apiServiceClassFilePrefix.'-api-structure.ts', $apiStructureTsContent);

        $bundleEntitiesTsContent = $twig->render('EasternColorNgXBundle:Command:ApiService/AllBundleEntities.ts.twig', $this->twigContext);
        file_put_contents($this->generateDestination.'bundle-entities.ts', $bundleEntitiesTsContent);
        $apiStructureTsContent = $twig->render('EasternColorNgXBundle:Command:ApiService/AllApiStructure.ts.twig', $this->twigContext);
        file_put_contents($this->generateDestination.$this->apiServiceClassFilePrefix.'-api-v1-structure.ts', $apiStructureTsContent);

        echo 'Done';
    }

    protected function initQuestions(SymfonyStyle $io, InputInterface $input, OutputInterface $output)
    {
        $helper = $this->getHelper('question');

        // Ask for Bundle
        $ecBundles = array_keys(array_filter($this->getContainer()->getParameter('kernel.bundles'), function ($val) { return strstr($val, 'EasternColor'); }));
        $question = new ChoiceQuestion('Which bundle (default: 9)? ', $ecBundles, 9);
        $question->setErrorMessage('Bundle %s is invalid.');
        $this->bundle = $helper->ask($input, $output, $question);
        $this->bundleDirectory = $this->getContainer()->get('kernel')->locateResource('@'.$this->bundle);
        $io->text('Bundle:    '.$this->bundle);
        $io->text('Directory: '.$this->bundleDirectory);

        // Ask for Routing XML
        $routingXmlFinder = (new Finder())->in($this->bundleDirectory.'Resources/config/routing')->name('*_api.xml');
        $routingXmls = [];
        foreach ($routingXmlFinder as $routingXmlFile) {
            $routingXmls[] = $routingXmlFile->getPathname();
        }
        $question = new ChoiceQuestion('Which routing XML (default: 1)? ', $routingXmls, 1);
        $question->setErrorMessage('Routing XML %s is invalid.');
        $this->routingXml = $helper->ask($input, $output, $question);

        $loader = new \Symfony\Component\Routing\Loader\XmlFileLoader(new \Symfony\Component\Config\FileLocator($this->bundleDirectory));
        $routes = $loader->load($this->routingXml);
        $routeNames = [];
        /* @var $route Symfony\Component\Routing\Route */
        foreach ($routes->getIterator() as $name => $route) {
            $routeNames[] = explode('_', $name);
        }
        if (1 === count($routeNames)) {
            $routeNameCommanParts = array_slice($routeNames[0], 0, array_search('api', $routeNames[0]));
        } else {
            $routeNameCommanParts = call_user_func_array('array_intersect', $routeNames);
        }

        $this->routeNamesPrefix = implode('_', $routeNameCommanParts).'_';
        // $question = new Question(sprintf('Route Name Prefix (default: %s):', $this->routeNamesPrefix), $this->routeNamesPrefix);
        // $this->routeNamesPrefix = $helper->ask($input, $output, $question);

        $this->apiClassPrefix = substr($this->bundle, strstr($this->bundle, 'Img360') ? 18 : 14, -6);
        $this->apiClassPrefix = ('V1' === $this->apiClassPrefix) ? 'Admin' : $this->apiClassPrefix;
        // $question = new Question(sprintf('Ionic Api Class Prefix (default: %s):', $this->apiClassPrefix), $this->apiClassPrefix);
        // $this->apiClassPrefix = $helper->ask($input, $output, $question);
        $this->apiClassFilePrefix = (new Stringy($this->apiClassPrefix))->underscored();
        $this->twigContext['api_class_prefix'] = $this->apiClassPrefix;
        $this->twigContext['api_class_file_prefix'] = $this->apiClassFilePrefix;

        $this->apiServiceClassPrefix = $this->apiClassPrefix.ucfirst($routeNameCommanParts[count($routeNameCommanParts) - 1]);
        // $question = new Question(sprintf('Ionic Api Service Class Prefix (default: %s):', $this->apiServiceClassPrefix), $this->apiServiceClassPrefix);
        // $this->apiServiceClassPrefix = $helper->ask($input, $output, $question);
        $this->apiServiceClassFilePrefix = (new Stringy($this->apiServiceClassPrefix))->dasherize();
        $this->twigContext['api_service_class_prefix'] = $this->apiServiceClassPrefix;
        $this->twigContext['api_service_class_file_prefix'] = $this->apiServiceClassFilePrefix;

        $this->generateDestination = $this->getContainer()->getParameter('kernel.project_dir').'/_generated/api-services/';
        // $question = new Question(sprintf('Ionic Api Class Prefix (default: %s):', $this->generateDestination), $this->generateDestination);
        // $this->generateDestination = $helper->ask($input, $output, $question);
    }

    protected function renderEntities(SymfonyStyle $io, InputInterface $input, OutputInterface $output)
    {
        $finder = (new Finder())->path('Entity')->notPath('Traits')->name('*.php')->contains('JMS\Serializer\Annotation')->in($this->getContainer()->getParameter('kernel.project_dir').'/src/');

        /* @var $serializer Serializer */
        $serializer = $this->getContainer()->get('jms_serializer');

        $this->twigContext['entities'] = [];

        $jmsGroups = [];
        /* @var $file SplFileInfo */
        $io->text('foreach finder @'.__LINE__);
        foreach ($finder as $file) {
            if (preg_match('|@(JMS\\\\)*Groups\(\{"(?<name>.*)"\}\)|', $file->getContents(), $matches)) {
                $values = explode(',', $matches['name']);
                foreach ($values as $value) {
                    $jmsGroups[] = trim($value, ' "');
                }
            }
        }
        $jmsGroups = array_unique($jmsGroups);

        /* @var $file SplFileInfo */
        $io->text('foreach finder @'.__LINE__);
        foreach ($finder as $file) {
            $entityFqcn = str_replace([$this->getContainer()->getParameter('kernel.project_dir').'/src/', '/', '.php'], '', $file->getPathname());
            if (!class_exists($entityFqcn)
              or strstr($file->getContents(), 'abstract class')
            ) {
                $io->warning('Skipped: '.$file->getPathname());
                continue;
            }
            $objects = $this->getContainer()->get('doctrine')->getRepository($entityFqcn)->findBy([], [], 10);

            if (count($objects) > 0) {
                $temp = [];

                $io->text('foreach objects @'.__LINE__);
                foreach ($objects as $index => $object) {
                    try {
                        $result = $serializer->toArray($object, SerializationContext::create()->setGroups($jmsGroups));
                        if (0 === $index) {
                            $io->text(static::getPrefixByEntityFqcn($entityFqcn).': '.$entityFqcn);
                        }

                        $io->text('foreach result @'.__LINE__.'  '.(is_array($result) ? 'y' : 'n'));
                        foreach ($result as $name => $value) {
                            if (!isset($temp[$name]) or 'any' === $temp[$name] or 'Array<any>' === $temp[$name]) {
                                $temp[$name] = static::tsTypeMapping($object, $name, $value);
                            }
                        }
                    } catch (Exception $ex) {
                    }
                }
                $this->twigContext['entities'][static::getPrefixByEntityFqcn($entityFqcn)][static::getPrefixByEntityFqcn($entityFqcn).$file->getBasename('.php')] = $temp;
            } else {
                $io->warning(sprintf("Can't generate ApiStructure for %s as there are no entity in DB.", $entityFqcn));
            }
        }
        $io->text(__FUNCTION__.' end');
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

    protected function renderApiService(SymfonyStyle $io, InputInterface $input, OutputInterface $output)
    {
        $helper = $this->getHelper('question');

        $allRoutes = $this->getContainer()->get('router')->getRouteCollection()->all();
        $loader = new \Symfony\Component\Routing\Loader\XmlFileLoader(new \Symfony\Component\Config\FileLocator($this->bundleDirectory));
        $routesByXml = $loader->load($this->routingXml);
        $routeNamesByXml = [];

        /* @var $params \Symfony\Component\Routing\Route */
        $io->text('foreach $routesByXml @'.__LINE__);
        foreach ($routesByXml as $route => $params) {
            $routeNamesByXml[] = $route;
        }

        /* @var $annotationCachedReader CachedReader */
        $annotationCachedReader = $this->getContainer()->get('annotation_reader');

        $routes = [];

        /* @var $params \Symfony\Component\Routing\Route */
        $io->text('foreach $allRoutes @'.__LINE__);
        foreach ($allRoutes as $route => $params) {
            if (in_array($route, $routeNamesByXml) and strstr($route, $this->routeNamesPrefix)) {
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
                    $io->text('foreach annotation fields @'.__LINE__);
                    foreach ($structureAnnotation->fields as $field) {
                        $item['api_structure']['fields'][] = $field;
                    }
                }
                $item['pathname'] = $params->getPath();
                $methods = array_filter($params->getMethods(), function ($val) { return !in_array($val, ['OPTIONS']); });
                $item['method'] = $methods[0];
                $item['name_pascal_cased'] = (new Stringy((str_replace($this->routeNamesPrefix, '', $route))))->upperCamelize();
                // $item['route_parameters'] = [];
                preg_match_all('|{([^}]+)}|', $params->getPath(), $matches);
                $item['route_parameters'] = [];
                $item['route_parameters_as_function_parameters'] = [];
                if (count($matches) > 0) {
                    $item['route_parameters'] = $matches[1];
                    $io->text('foreach route parameters @'.__LINE__);
                    foreach ($matches[1] as $routeParameter) {
                        $item['route_parameters_as_function_parameters'][] = $routeParameter.': string';
                    }
                }
                $routes[(str_replace($this->routeNamesPrefix, '', $route))] = $item;
            }
        }

        $this->twigContext['routes'] = $routes;
    }
}
