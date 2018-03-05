<?php

namespace EasternColor\NgXBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Routing\Route;

class GenerateRoutingServiceCommand extends ContainerAwareCommand
{
    protected $tags = [];
    protected $tagsRoutes = [];

    protected $generateDestination = '';
    protected $selectedTags = [];
    protected $twigContext = [];

    protected function configure()
    {
        $this
            ->setName('ec:ngx:routing')
            ->setDescription('')
            ->addArgument('tags', InputArgument::IS_ARRAY, 'Tags to generate')
            ->addOption('ignore_common', false, InputOption::VALUE_NONE, 'Force ignore common routes.')
        ;
    }

    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        $allRoutes = $this->getContainer()->get('router')->getRouteCollection()->all();
        /** @var $route Route */
        foreach ($allRoutes as $id => $route) {
            if ($route->getOption('ngx_routing')) {
                $tags = array_map('trim', explode(' ', $route->getOption('ngx_routing')));
                foreach ($tags as $tag) {
                    $this->tags[] = $tag;
                    if (!isset($this->tagsRoutes[$tag])) {
                        $this->tagsRoutes[$tag] = [];
                    }
                    $this->tagsRoutes[$tag][$id] = $route;
                }
            }
        }
        $this->tags = array_values(array_unique($this->tags));
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $argsTags = $input->getArgument('tags');
        $defaultTags = [];
        if (count($argsTags) > 0) {
            foreach ($argsTags as $tag) {
                $i = array_search($tag, $this->tags);
                if (null !== $i and false !== $i) {
                    $defaultTags[] = $i;
                }
            }
        }

        $io = new SymfonyStyle($input, $output);
        $helper = $this->getHelper('question');
        $this->generateDestination = $this->getContainer()->getParameter('kernel.project_dir').'/_generated/';

        $question = new ChoiceQuestion(sprintf('Which tag(s)? (default: %s)', implode(',', $argsTags)), $this->tags, implode(',', $defaultTags));
        $question->setMultiselect(true);
        $question->setErrorMessage('Please select at least one tag(s).');
        $this->selectedTags = $helper->ask($input, $output, $question);
        if (!$input->getOption('ignore_common')) {
            $this->selectedTags[] = 'common';
        }
        $this->selectedTags = array_unique($this->selectedTags);

        $routes = [];
        foreach ($this->selectedTags as $tag) {
            /** @var $route Route */
            foreach ($this->tagsRoutes[$tag] as $id => $route) {
                if (!isset($routes[$id])) {
                    $defaults = $route->getDefaults();
                    unset($defaults['_controller']);
                    $temp = [
                        'id' => $id,
                        'defaults' => $defaults,
                        'requirements' => $route->getRequirements(),
                        'tokens' => $route->compile()->getTokens(),
                        'hosttokens' => $route->compile()->getHostTokens(),
                    ];
                    $routes[$id] = $temp;
                }
            }
        }

        $this->twigContext['routes'] = $routes;

        /* @var $twig Twig */
        $twig = $this->getContainer()->get('twig');
        $content = $twig->render('EasternColorNgXBundle:Command:RoutingService/RoutingService.ts.twig', $this->twigContext);
        file_put_contents($this->generateDestination.'routing-service.ts', $content);

        echo 'DONE';
    }
}
