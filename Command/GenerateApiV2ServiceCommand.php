<?php

namespace EasternColor\NgXBundle\Command;

use Doctrine\Common\Annotations\CachedReader;
use Doctrine\ORM\EntityManager;
use EasternColor\NgXBundle\Annotations\ApiStructure\Structure;
use EasternColor\NgXBundle\Helpers\EntityHelper;
use EasternColor\NgXBundle\Helpers\RoutesHelper;
use JMS\Serializer\Serializer;
use Stringy\Stringy;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;
use Symfony\Component\Routing\Route;
use Symfony\Component\Templating\EngineInterface;

class GenerateApiV2ServiceCommand extends ContainerAwareCommand
{
    const ROUTEOPTIONNAME_APP = 'ngx-apiv2-app';

    /* @var EntityManager */
    protected $doctrine;

    /* @var EngineInterface */
    protected $templating;

    /* @var string */
    protected $srcPath;

    /* @var mixed */
    protected $routeFileMapping;

    /* @var mixed */
    protected $bundleRouteFileMapping;

    /* @var mixed */
    protected $bundleRoutesMapping;

    protected $entities;

    protected $apiRoutes;

    /* @var string[] */
    protected $apps;

    /* @var Route[] */
    protected $appRoutes;

    /* @var string[] */
    protected $appRouteNames;

    protected $generateDestinationBase;

    // public function __construct(EntityManager $doctrine, EngineInterface $templating)
    // {
    //     $this->doctrine = $doctrine;
    //     $this->templating = $templating;
    //
    //     parent::__construct();
    // }

    protected function configure()
    {
        $this
          ->setName('ec:ngx:apiv2')
          ->setDescription('Generate Api V2 Service')
          ->setHelp('This command is to Generate Api V2 Service')
      ;
    }

    protected static function arrayKeyImploded($array)
    {
        $entityProperites = array_keys($array);
        asort($entityProperites);

        return implode('::', $entityProperites);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->doctrine = $this->getContainer()->get('doctrine');
        $this->templating = $this->getContainer()->get('twig');
        $this->srcPath = $this->getContainer()->getParameter('kernel.project_dir').'/src/';
        $this->routeFileMapping = RoutesHelper::getRouteFileMapping($this->srcPath, static::ROUTEOPTIONNAME_APP);

        $io = new SymfonyStyle($input, $output);
        $io->title('[EasternColor] Generate Api V2 Service');

        $this->doctrine->getEntityManager()->getFilters()->disable('softdeleteable');

        $this->generateDestinationBase = $this->getContainer()->getParameter('kernel.project_dir').'/_generated/api-services/';

        $this->initQuestions($io, $input, $output);

        $io->text('=== renderEntities');
        $this->entities = $this->renderEntities($io, $input, $output);
        // dump(__LINE__, $this->entities);

        $io->text('=== renderApiService');
        $this->apiRoutes = $this->renderApiService($io, $input, $output);

        $this->buildBundleRouteFileMapping($io, $input, $output);

        $this->renderTwig($io);
    }

    protected function renderEachTwig($io, $app, $generateDestination, $usedOtherBundleEntitiesAll)
    {
        $io->text('renderEachTwig L:'.__LINE__.'@'.__FILE__);

        $appEntities = [];
        foreach ($this->bundleRouteFileMapping[$app] as $bundle => $data) {
            $io->text('renderEachTwig $bundle: '.$bundle.' L:'.__LINE__.'@'.__FILE__);
            $this->twigContext['bundle_ns'] = $data['api_class_prefix'];
            $this->twigContext['api_service_class_prefix'] = $data['api_service_class_prefix'];
            $this->twigContext['api_service_class_file_prefix'] = $data['api_service_class_file_prefix'];
            if (isset($usedOtherBundleEntitiesAll[$this->twigContext['bundle_ns']])) {
                $this->twigContext['used_other_bundle_entities'] = array_unique($usedOtherBundleEntitiesAll[$this->twigContext['bundle_ns']]);
            } else {
                $this->twigContext['used_other_bundle_entities'] = [];
            }

            $routeNamesPrefix = implode('_', $data['routeNameCommanParts']).'_';
            $routes = [];
            $hasPostOrPut = false;
            $usedEntities = [];
            foreach ($this->bundleRoutesMapping[$app][$bundle] as $name => $route) {
                if (!$hasPostOrPut and in_array($route['method'], ['POST', 'PUT'])) {
                    $hasPostOrPut = true;
                }
                $io->text('foreach api_structure fields L:'.__LINE__.'@'.__FILE__);
                foreach ($route['api_structure']['fields'] as $field) {
                    if (preg_match('@(?<entity>Entity\w+)@', $field->type, $match)) {
                        $usedEntities[] = $match['entity'];
                    }
                }

                $shortName = (str_replace($routeNamesPrefix, '', $route['route_name']));
                // dump(__LINE__, $routeNamesPrefix, $route['route_name'], (str_replace($routeNamesPrefix, '', $route['route_name'])));
                $route['name_pascal_cased'] = (new Stringy($shortName))->upperCamelize();
                $routes[$shortName] = $route;
            }

            $this->twigContext['routes'] = $routes;
            $this->twigContext['used_entities'] = array_unique($usedEntities);
            $this->twigContext['has_post_or_put'] = $hasPostOrPut;
            // dump(__LINE__, $this->twigContext, $this->entities, $data['api_class_prefix']);
            // foreach ($this->twigContext['used_entities'] as $name) {
            //     $name = substr($name, 6);
            //     if (isset($this->entities[$data['api_class_prefix']][$name])) {
            //         $appEntities[$name] = $this->entities[$data['api_class_prefix']][$name];
            //     }
            // }
            // $appEntities = array_merge($appEntities, );
            // dump($this->usedOtherBundleEntities, $this->twigContext);
            // exit;
            // $bundleEntitiesTsContent = $this->templating->render('EasternColorNgXBundle:Command:ApiService/BundleEntities.ts.twig', $this->twigContext);
            // file_put_contents($generateDestination.$this->apiClassFilePrefix.'-bundle-entities.ts', $bundleEntitiesTsContent);
            $apiServiceTsContent = $this->templating->render('EasternColorNgXBundle:Command:ApiService/ApiService.ts.twig', $this->twigContext);
            file_put_contents($generateDestination.$this->twigContext['api_service_class_file_prefix'].'-api-v1-service.ts', $apiServiceTsContent);
            // $apiStructureTsContent = $this->templating->render('EasternColorNgXBundle:Command:ApiService/ApiStructure.ts.twig', $this->twigContext);
            // file_put_contents($generateDestination.$this->twigContext['api_service_class_file_prefix'].'-api-structure.ts', $apiStructureTsContent);

            $apiStructureTsContent = $this->templating->render('EasternColorNgXBundle:Command:ApiService/AllApiStructure.ts.twig', $this->twigContext);
            file_put_contents($generateDestination.$this->twigContext['api_service_class_file_prefix'].'-api-v1-structure.ts', $apiStructureTsContent);
        }
        // dump(__LINE__, $appEntities);
        // $this->twigContext['entities'] = [$bundle => $appEntities];
        $this->twigContext['entities'] = $this->entities;
        $bundleEntitiesTsContent = $this->templating->render('EasternColorNgXBundle:Command:ApiService/AllBundleEntities.ts.twig', $this->twigContext);
        file_put_contents($generateDestination.'bundle-entities.ts', $bundleEntitiesTsContent);
    }

    protected function renderTwig($io)
    {
        $this->twigContext['last_modified'] = date('Y-m-d H:i:s');
        $usedOtherBundleEntitiesAll = EntityHelper::getUsedOtherBundleEntities();

        foreach ($this->apps as $app) {
            $generateDestination = $this->generateDestinationBase.$app.'/';
            if (!is_dir($generateDestination)) {
                mkdir($generateDestination);
            }

            $this->renderEachTwig($io, $app, $generateDestination, $usedOtherBundleEntitiesAll);
        }

        echo 'Done';
    }

    protected function initQuestions(SymfonyStyle $io, InputInterface $input, OutputInterface $output)
    {
        $helper = $this->getHelper('question');

        $allRoutes = $this->getContainer()->get('router')->getRouteCollection();

        /* @var $route \Symfony\Component\Routing\Route */
        foreach ($allRoutes as $index => $route) {
            if ($route->hasOption(static::ROUTEOPTIONNAME_APP)) {
                $names = explode(' ', $route->getOption(static::ROUTEOPTIONNAME_APP));
                foreach ($names as $name) {
                    if (!isset($this->appRoutes[$name])) {
                        $this->appRoutes[$name] = [];
                        $this->appRouteNames[] = $name;
                    }
                    $this->appRoutes[$name][$index] = $route;
                }
            }
        }
        $question = new ChoiceQuestion('Which app? ', $this->appRouteNames);
        $question->setMultiselect(true);
        $question->setErrorMessage('App %s is invalid.');
        $this->apps = $helper->ask($input, $output, $question);
    }

    protected function buildBundleRouteFileMapping(SymfonyStyle $io, InputInterface $input, OutputInterface $output)
    {
        $this->bundleRouteFileMapping = [];
        $this->bundleRoutesMapping = [];
        foreach ($this->apps as $app) {
            $this->bundleRouteFileMapping[$app] = [];
            $this->bundleRoutesMapping[$app] = [];
            foreach ($this->apiRoutes[$app] as $name => $route) {
                $xmlPath = $this->routeFileMapping[$route['route_name']];
                $bundle = strstr(strstr($xmlPath, '\\EasternColor\\'), '\\Resources', true);
                if (!isset($this->bundleRoutesMapping[$app][$bundle])) {
                    $this->bundleRoutesMapping[$app][$bundle] = [];
                }
                $this->bundleRoutesMapping[$app][$bundle][$name] = $route;
            }
            foreach ($this->bundleRoutesMapping[$app] as $bundle => $nameRouteMapping) {
                $routeNames = [];
                foreach ($nameRouteMapping as $route) {
                    $routeNames[] = explode('_', $route['route_name']);
                }
                if (1 === count($routeNames)) {
                    $routeNameCommanParts = array_slice($routeNames[0], 0, array_search('api', $routeNames[0]));
                } else {
                    $routeNameCommanParts = call_user_func_array('array_intersect', $routeNames);
                }

                // TODO remove hard-coded api-class-prefix guessing
                $apiClassPrefix = substr($bundle, 2 + (strstr($bundle, 'Img360') ? 18 : 14), -6);
                $apiClassPrefix = ('V1' === $apiClassPrefix) ? 'Admin' : $apiClassPrefix;
                $apiClassFilePrefix = (new Stringy($apiClassPrefix))->underscored();

                $apiServiceClassPrefix = $apiClassPrefix.ucfirst($routeNameCommanParts[count($routeNameCommanParts) - 1]);
                $apiServiceClassFilePrefix = (new Stringy($apiServiceClassPrefix))->dasherize();

                $this->bundleRouteFileMapping[$app][$bundle] = [
                    'bundle' => $bundle,
                    'routeNameCommanParts' => $routeNameCommanParts,
                    'api_class_prefix' => $apiClassPrefix,
                    'api_class_file_prefix' => $apiClassFilePrefix,
                    'api_service_class_prefix' => $apiServiceClassPrefix,
                    'api_service_class_file_prefix' => $apiServiceClassFilePrefix,
                ];
            }
        }
    }

    protected function renderEntities(SymfonyStyle $io, InputInterface $input, OutputInterface $output)
    {
        /* @var $serializer Serializer */
        $serializer = $this->getContainer()->get('jms_serializer');

        $finder = EntityHelper::getFinder($this->srcPath);

        $io->text('jmsGroups L:'.__LINE__.'@'.__FILE__);
        $jmsGroups = EntityHelper::getJmsGroups($io, $finder);

        /* @var $file SplFileInfo */
        $io->text('foreach finder L:'.__LINE__.'@'.__FILE__);
        $entities = EntityHelper::getEntities($io, $finder, $serializer, $this->doctrine, $jmsGroups, $this->srcPath);

        $io->text(__FUNCTION__.' end');

        return $entities;
    }

    protected function renderApiService(SymfonyStyle $io, InputInterface $input, OutputInterface $output)
    {
        /* @var $annotationCachedReader CachedReader */
        $annotationCachedReader = $this->getContainer()->get('annotation_reader');

        $routes = [];

        /* @var $params \Symfony\Component\Routing\Route */
        $io->text('foreach $allRoutes L:'.__LINE__.'@'.__FILE__);
        foreach ($this->apps as $app) {
            $routes[$app] = [];
            $routeNamesPrefix = RoutesHelper::getRouteNamePrefix($this->appRoutes[$app]);
            foreach ($this->appRoutes[$app] as $route => $params) {
                $name = str_replace($routeNamesPrefix, '', $route);
                $temp = RoutesHelper::getRouteTwigVars($io, $annotationCachedReader, $name, $params);
                $temp['route_name'] = $route;
                $routes[$app][$name] = $temp;
            }
        }

        return $routes;
    }
}
