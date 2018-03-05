EasternColorNgXBundle
=========================
This Symfony bundle provide some tools to generate service for Angular2+

Installation
------------
1. `composer require eastern-color/ng-x-bundle`
2. Enable bundle in symfony's __/app/AppKernel.php__
    - `new EasternColor\NgXBundle\EasternColorNgXBundle()`,

Prerequisites
-------------
- You MUST set "framework.templating" in Symfony's __config.yml__

TODO
----
- Turn Helpers to symfony services
- Remove our project-naming-convention from the code
- Try to review the license and provide ISO-3166-2 subregion mapping
- Extends this README

Command
-------
1. `ec:ngx:apiv2`
2. `ec:ngx:country`
3. `ec:ngx:routing`

License
-------
This bundle is under the MIT license.
