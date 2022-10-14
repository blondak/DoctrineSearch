<?php

/**
 * This file is part of the Kdyby (http://www.kdyby.org)
 *
 * Copyright (c) 2008 Filip Procházka (filip@prochazka.su)
 *
 * For the full copyright and license information, please view the file license.txt that was distributed with this source code.
 */

namespace Kdyby\DoctrineSearch\DI;

use Elastica;
use Kdyby;
use Kdyby\DoctrineCache\DI\Helpers as CacheHelpers;
use Nette;
use Nette\DI\Config;
use Nette\PhpGenerator as Code;
use Nette\Utils\Validators;
use Nette\Schema\Expect;
use Doctrine;

/**
 * @author Filip Procházka <filip@prochazka.su>
 */
class DoctrineSearchExtension extends Nette\DI\CompilerExtension
{

	public function getConfigSchema(): Nette\Schema\Schema
	{
	    return Expect::structure([
	        'metadataCache' => Expect::string()->default('default'),
	        'defaultSerializer' => Expect::string()->default('callback'),
	        'serializers' => Expect::array(),
	        'metadata' => Expect::array(),
	        'indexPrefix' => Expect::string(),
	        'debugger' => Expect::bool(\Tracy\Debugger::$productionMode === true),
	    ]);
	}

    public function loadConfiguration()
    {
        $builder = $this->getContainerBuilder();
        $config = $this->getConfig();

        $configuration = $builder->addDefinition($this->prefix('config'))
            ->setClass(Doctrine\Search\Configuration::class)
            ->addSetup('setMetadataCacheImpl', array(CacheHelpers::processCache($this, $config->metadataCache, 'metadata', $config->debugger)))
            ->addSetup('setObjectManager', array('@Doctrine\\ORM\\EntityManager'))
            ->addSetup('setIndexPrefix', array($config->indexPrefix));

        $this->loadSerializer($config);
        $configuration->addSetup('setEntitySerializer', array($this->prefix('@serializer')));

        $builder->addDefinition($this->prefix('driver'))
            ->setFactory(Doctrine\Search\Mapping\Driver\DependentMappingDriver::class, array($this->prefix('@driverChain')))
            ->setAutowired(FALSE);
        $configuration->addSetup('setMetadataDriverImpl', array($this->prefix('@driver')));

        $metadataDriverChain = $builder->addDefinition($this->prefix('driverChain'))
            ->setFactory(Doctrine\Persistence\Mapping\Driver\MappingDriverChain::class)
            ->setAutowired(FALSE);

        foreach ($config->metadata as $namespace => $directory) {
            $metadataDriverChain->addSetup('addDriver', array(
                new Nette\DI\Statement('Doctrine\Search\Mapping\Driver\NeonDriver', array($directory)),
                $namespace
            ));
        }

        foreach ($this->compiler->getExtensions() as $extension) {
            if ($extension instanceof ISearchMetadataProvider) {
                $metadata = $extension->getSearchMetadataMappings();
                Validators::assert($metadata, 'array');
                foreach ($metadata as $namespace => $directory) {
                    if (array_key_exists($namespace, $config['metadata'])) {
                        throw new Nette\Utils\AssertionException(sprintf('The namespace %s is already configured, provider cannot change it', $namespace));
                    }
                    if (!file_exists($directory)){
                        throw new Nette\Utils\AssertionException("The metadata path expects to be an existing directory, $path given.");
                    }
                    $metadataDriverChain->addSetup('addDriver', array(
                        new Nette\DI\Statement(\Doctrine\Search\Mapping\Driver\NeonDriver::class, array($directory)),
                        $namespace
                    ));
                }
            }

            if ($extension instanceof  ISearchSerializerProvider){
                $serializer = $builder->getDefinition($this->prefix('serializer'));
                $serializers = $extension->getSearchSerializerMappings();
                Validators::assert($serializers, 'array');
                foreach ($serializers as $type => $impl) {
                    $impl = self::filterArgs($impl);

                    if (is_string($impl->entity) && substr($impl->entity, 0, 1) === '@') {
                        $serializer->addSetup('addSerializer', array($type, $impl->entity));

                    } else {
                        $builder->addDefinition($this->prefix($name = 'serializer.' . str_replace('\\', '_', $type)))
                        ->setFactory($impl->entity, $impl->arguments)
                        ->setClass((is_string($impl->entity) && class_exists($impl->entity)) ? $impl->entity : 'Doctrine\Search\SerializerInterface')
                        ->setAutowired(FALSE);

                        $serializer->addSetup('addSerializer', array($type, $this->prefix('@' . $name)));
                    }
                }
            }
        }

        $builder->addDefinition($this->prefix('client'))
            ->setFactory(Doctrine\Search\ElasticSearch\Client::class, array('@Elastica\Client'));

        $builder->addDefinition($this->prefix('evm'))
            ->setFactory(Kdyby\Events\NamespacedEventManager::class, array(Kdyby\DoctrineSearch\Events::NS . '::'))
            ->setAutowired(FALSE);

        $builder->addDefinition($this->prefix('manager'))
            ->setFactory(Doctrine\Search\SearchManager::class, array(
                $this->prefix('@config'),
                $this->prefix('@client'),
                $this->prefix('@evm'),
            ));

        $builder->addDefinition($this->prefix('searchableListener'))
            ->setFactory(Kdyby\DoctrineSearch\SearchableListener::class)
            ->addTag('kdyby.subscriber');

        $builder->addDefinition($this->prefix('schema'))
            ->setFactory(Kdyby\DoctrineSearch\SchemaManager::class, array($this->prefix('@client')));

        $builder->addDefinition($this->prefix('entityPiper'))
            ->setClass(Kdyby\DoctrineSearch\EntityPiper::class);

        $this->loadConsole();
    }



    protected function loadSerializer($config)
    {
        $builder = $this->getContainerBuilder();

        switch ($config->defaultSerializer) {
            case 'callback':
                $serializer = new Nette\DI\Statement('Doctrine\Search\Serializer\CallbackSerializer');
                break;

            case 'jms':
                $builder->addDefinition($this->prefix('jms.serializationBuilder'))
                    ->setClass('JMS\Serializer\SerializerBuilder')
                    ->addSetup('setPropertyNamingStrategy', array(
                        new Nette\DI\Statement('JMS\Serializer\Naming\SerializedNameAnnotationStrategy', array(
                            new Nette\DI\Statement('JMS\Serializer\Naming\IdenticalPropertyNamingStrategy')
                        ))
                    ))
                    ->addSetup('addDefaultHandlers')
                    ->addSetup('setAnnotationReader')
                    ->setAutowired(FALSE);

                $builder->addDefinition($this->prefix('jms.serializer'))
                    ->setClass('JMS\Serializer\Serializer')
                    ->setFactory($this->prefix('@jms.serializationBuilder::build'))
                    // todo: getMetadataFactory()->setCache()
                    ->setAutowired(FALSE);

                $builder->addDefinition($this->prefix('jms.serializerContext'))
                    ->setClass('JMS\Serializer\SerializationContext')
                    ->addSetup('setGroups', array('search'))
                    ->setAutowired(FALSE);

                $serializer = new Nette\DI\Statement('Doctrine\Search\Serializer\JMSSerializer', array(
                    $this->prefix('@jms.serializer'),
                    $this->prefix('@jms.serializerContext')
                ));
                break;

            default:
                throw new Kdyby\DoctrineSearch\NotImplementedException(
                    sprintf('Serializer "%s" is not supported', $config['defaultSerializer'])
                );
        }

        $serializer = $builder->addDefinition($this->prefix('serializer'))
            ->setClass('Doctrine\Search\Serializer\ChainSerializer')
            ->addSetup('setDefaultSerializer', array($serializer));

        foreach ($config->serializers as $type => $impl) {
            $impl = self::filterArgs($impl);

            if (is_string($impl->entity) && substr($impl->entity, 0, 1) === '@') {
                $serializer->addSetup('addSerializer', array($type, $impl->entity));

            } else {
                $builder->addDefinition($this->prefix($name = 'serializer.' . str_replace('\\', '_', $type)))
                    ->setFactory($impl->entity, $impl->arguments)
                    ->setClass((is_string($impl->entity) && class_exists($impl->entity)) ? $impl->entity : 'Doctrine\Search\SerializerInterface')
                    ->setAutowired(FALSE);

                $serializer->addSetup('addSerializer', array($type, $this->prefix('@' . $name)));
            }
        }
    }



    protected function loadConsole()
    {
        $builder = $this->getContainerBuilder();

        $builder->addDefinition($this->prefix('console.createMapping'))
            ->setClass('Kdyby\DoctrineSearch\Console\CreateMappingCommand')
            ->addTag('kdyby.console.command');

        $builder->addDefinition($this->prefix('console.pipeEntities'))
            ->setClass('Kdyby\DoctrineSearch\Console\PipeEntitiesCommand')
            ->addTag('kdyby.console.command');

        $builder->addDefinition($this->prefix('console.info'))
            ->setClass('Kdyby\DoctrineSearch\Console\InfoCommand')
            ->addTag('kdyby.console.command');
    }

    /**
     * @param string|Nette\DI\Statement $statement
     * @return Nette\DI\Statement
     */
    private static function filterArgs($statement)
    {
        $args = Nette\DI\Helpers::filterArguments(array(is_string($statement) ? new Nette\DI\Statement($statement) : $statement));
        return $args[0];
    }

    public static function register(Nette\Configurator $configurator)
    {
        $configurator->onCompile[] = function ($config, Nette\DI\Compiler $compiler) {
            $compiler->addExtension('doctrineSearch', new DoctrineSearchExtension());
        };
    }

}

