<?php

namespace EasternColor\NgXBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;

class DefaultController extends Controller
{
    public function indexAction()
    {
        return $this->render('EasternColorNgXBundle:Default:index.html.twig');
    }
}
