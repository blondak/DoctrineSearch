<?php

/**
 * This file is part of the Kdyby (http://www.kdyby.org)
 *
 * Copyright (c) 2008 Filip Procházka (filip@prochazka.su)
 *
 * For the full copyright and license information, please view the file license.txt that was distributed with this source code.
 */

namespace Kdyby\DoctrineSearch\Console;

use Doctrine\Search\Mapping\ClassMetadata;
use Elastica\Exception\ResponseException;
use Kdyby;
use Nette;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Doctrine\Search\Mapping\IndexMetadata;



/**
 * @author Filip Procházka <filip@prochazka.su>
 */
class CreateMappingCommand extends Command
{
    protected static $defaultName = 'elastica:mapping:create';

    /**
     * @var \Kdyby\DoctrineSearch\SchemaManager
     * @inject
     */
    public $schema;

    /**
     * @var \Doctrine\Search\SearchManager
     * @inject
     */
    public $searchManager;



    protected function configure()
    {
        $this->setName(self::$defaultName)
            ->setDescription("Creates indexes and type mappings in ElasticSearch")
            ->addOption('init-data', 'i', InputOption::VALUE_NONE, "Should the newly created index also be populated with current data?")
            ->addOption('drop-before', 'd', InputOption::VALUE_NONE, "Should the indexes be dropped first, before they're created? WARNING: this drops data!")
            ->addOption('entity', 'e', InputOption::VALUE_OPTIONAL, 'Synchronizes only specified entity')
        ;

        // todo: filtering to only one type at a time
    }



    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        parent::initialize($input, $output);

        $this->schema->onIndexDropped[] = function ($sm, IndexMetadata $index) use ($output) {
            $output->writeln(sprintf('<error>Dropped</error> index <info>%s</info>', $index->name));
        };
        $this->schema->onTypeDropped[] = function ($sm, ClassMetadata $type) use ($output) {
            $output->writeln(sprintf('<error>Dropped</error> type <info>%s</info>', $type->getName()));
        };

        $this->schema->onIndexCreated[] = function ($sm, $index) use ($output) {
            $output->writeln(sprintf('Created index <info>%s</info>', $index));
        };
        $this->schema->onTypeCreated[] = function ($sm, ClassMetadata $type, IndexMetadata $indexMetadata) use ($output) {
            $output->writeln(sprintf('Created type <info>%s</info> for index <info>%s</info>', $type->getName(), $indexMetadata->name));
        };

        $this->schema->onAliasCreated[] = function ($sm, $original, $alias) use ($output) {
            $output->writeln(sprintf('Created alias <info>%s</info> for index <info>%s</info>', $alias, $original));
        };
        $this->schema->onAliasError[] = function ($sm, ResponseException $e, $original, $alias) use ($output) {
            $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));
        };

        /** @var \Doctrine\Search\ElasticSearch\Client $searchClient */
        $searchClient = $this->searchManager->getClient();

        /** @var Kdyby\ElasticSearch\Client $apiClient */
        $apiClient = $searchClient->getClient();
        $apiClient->onError = [];
        $apiClient->onSuccess = [];
    }



    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $metadataFactory = $this->searchManager->getClassMetadataFactory();

        if ($onlyEntity = $input->getOption('entity')) {
            $classes = [$metadataFactory->getMetadataFor($onlyEntity)];

        } else {
            $classes = $metadataFactory->getAllMetadata();
        }

        if ($input->getOption('drop-before')) {
            $this->schema->dropMappings($classes);
        }
        $aliases = $this->schema->createMappings($classes, FALSE);

        if ($input->getOption('init-data')) {
            $indexAliases = array();
            foreach ($aliases as $alias => $original) {
                $indexAliases[] = $alias . '=' . $original;
            }

            $exitCode = $this->getApplication()->doRun(new ArrayInput(array(
                'elastica:pipe-entities',
                'index-aliases' => $indexAliases
            )), $output);

            if ($exitCode !== 0) {
                return 1;
            }
        }

        $output->writeln('');
        $this->schema->createAliases($aliases);

        return 0;
    }

}
