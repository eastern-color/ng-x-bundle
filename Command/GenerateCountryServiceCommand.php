<?php

namespace EasternColor\NgXBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;
use Symfony\Component\Intl\Intl;

class GenerateCountryServiceCommand extends ContainerAwareCommand
{
    protected static $localesMapping = [
        'en' => 'en',
        'zh_Hant' => 'zh',
        'zh_Hans' => 'zh_hans',
    ];

    protected function configure()
    {
        $this
          // the name of the command (the part after "bin/console")
          ->setName('ec:ngx:country')

          // the short description shown while running "php bin/console list"
          ->setDescription('Generate Country Service for Angular')

          // the full command description shown when running the command with
          // the "--help" option
          ->setHelp('This command is to Generate Country Service for Angular')

        //   ->addArgument('name', InputArgument::REQUIRED, 'Name')
        //   ->addArgument('route_prefix', InputArgument::REQUIRED, 'Route Prefix')
        //   ->addArgument('route_const_prefix', InputArgument::OPTIONAL, 'Route Const Prefix')
      ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('[EasternColor] Generate Country Service for Angular');
        $this->generateDestination = $this->getContainer()->getParameter('kernel.project_dir').'/_generated/';

        $rb = Intl::getRegionBundle();
        $allowedLocale = [];
        $countries = [];
        foreach (static::$localesMapping as $symfonyLocale => $jsonLocale) {
            $allowedLocale[] = $jsonLocale;
            $countries[$jsonLocale] = $rb->getCountryNames($symfonyLocale);
        }
        $this->twigContext['locales'] = $allowedLocale;
        $this->twigContext['countries'] = $countries;

        $subregions = [];
        $container = $this->getContainer();
        // $dir = $container->get('kernel')->locateResource('@EasternColorCoreBundle/Resources/data/ISO3166-2');
        // $finder = (new Finder());
        // $finder->in($dir)->files('*.php');
        // /* @var $file SplFileInfo */
        // foreach ($finder as $file) {
        //     $countryCode = $file->getBasename('.php');
        //     // dump($file->getPathname(), );
        //     list($nameValue, $valueName) = require $file->getPathname();
        //     $subregions['en'][$countryCode] = $valueName;
        // }
        // $this->twigContext['subregions'] = $subregions;

        /* @var $twig Twig */
        $twig = $this->getContainer()->get('twig');
        $content = $twig->render('EasternColorCoreBundle:Command:CountryService/CountrySerice.ts.twig', $this->twigContext);
        file_put_contents($this->generateDestination.'country-service.ts', $content);

        // $content = $twig->render('EasternColorCoreBundle:Command:CountryService/SubregionSerice.ts.twig', $this->twigContext);
        // file_put_contents($this->generateDestination.'subregion-service.ts', $content);

        $io->text('DONE');
    }
}
