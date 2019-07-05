<?php


namespace Firesphere\SolrSearch\Tasks;

use Exception;
use Firesphere\SolrSearch\Factories\DocumentFactory;
use Firesphere\SolrSearch\Helpers\SearchIntrospection;
use Firesphere\SolrSearch\Helpers\SolrUpdate;
use Firesphere\SolrSearch\Indexes\BaseIndex;
use Firesphere\SolrSearch\Services\SolrCoreService;
use GuzzleHttp\Exception\RequestException;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\BuildTask;
use SilverStripe\ORM\DataList;
use SilverStripe\ORM\DataObject;
use SilverStripe\Versioned\Versioned;
use Solarium\Core\Client\Client;

class SolrIndexTask extends BuildTask
{
    /**
     * @var string
     */
    private static $segment = 'SolrIndexTask';

    /**
     * @var string
     */
    protected $title = 'Solr Index update';

    /**
     * @var string
     */
    protected $description = 'Add or update documents to an existing Solr core.';

    /**
     * @var SearchIntrospection
     */
    protected $introspection;

    /**
     * @var bool
     */
    protected $debug = false;

    /**
     * @var null|LoggerInterface
     */
    protected $logger;

    /**
     * @var Client
     */
    protected $client;

    /**
     * SolrIndexTask constructor. Sets up the document factory
     */
    public function __construct()
    {
        parent::__construct();
        // Only index live items.
        // The old FTS module also indexed Draft items. This is unnecessary
        Versioned::set_reading_mode(Versioned::DRAFT . '.' . Versioned::LIVE);
        $this->logger = Injector::inst()->get(LoggerInterface::class);


        $this->introspection = new SearchIntrospection();
    }

    /**
     * Implement this method in the task subclass to
     * execute via the TaskRunner
     *
     * @param HTTPRequest $request
     * @return int|bool
     * @throws Exception
     * @todo clean up a bit, this is becoming a mess
     * @todo defer to background because it may run out of memory
     * @todo give Solr more time to respond
     * @todo Change the debug messaging to use the logger
     */
    public function run($request)
    {
        $startTime = time();
        $vars = $request->getVars();
        $indexes = (new SolrCoreService())->getValidIndexes($request->getVar('index'));

        $this->debug = isset($vars['debug']) || (Director::isDev() || Director::is_cli());

        $this->getLogger()->info(date('Y-m-d H:i:s') . PHP_EOL);
        $start = $request->getVar('start') ?: 0;

        $groups = 0;
        foreach ($indexes as $indexName) {
            /** @var BaseIndex $index */
            $index = Injector::inst()->get($indexName);
            $this->client = $index->getClient();

            // Only index the classes given in the var if needed, should be a single class
            $classes = isset($vars['class']) ? [$vars['class']] : $index->getClasses();


            foreach ($classes as $class) {
                $group = $request->getVar('group') ?: 0;
                $isGroup = $request->getVar('group');
                // Set the start point to the requested value, if there is only one class to index
                if ($start > $group && count($classes) === 1) {
                    $group = $start;
                }
                $this->reindexClass($isGroup, $class, $index, $group);
            }
        }
        $end = time();

        $this->getLogger()->info(
            sprintf('It took me %d seconds to do all the indexing%s', ($end - $startTime), PHP_EOL)
        );
        gc_collect_cycles(); // Garbage collection to prevent php from running out of memory

        return $groups;
    }

    /**
     * @return LoggerInterface|null
     */
    public function getLogger(): ?LoggerInterface
    {
        return $this->logger;
    }

    /**
     * @param $isGroup
     * @param $class
     * @param BaseIndex $index
     * @param int $group
     * @throws Exception
     */
    private function reindexClass($isGroup, $class, BaseIndex $index, int $group): void
    {
        $group = $group ?: 0;
        if ($this->debug) {
            $this->getLogger()->info(sprintf('Indexing %s for %s', $class, $index->getIndexName()), []);
        }

        // Run a single group
        if ($isGroup) {
            $this->doReindex($group, $class, $index);
        } else {
            $batchLength = DocumentFactory::config()->get('batchLength');
            $groups = (int)ceil($class::get()->count() / $batchLength);
            // Otherwise, run them all
            while ($group <= $groups) { // Run from oldest to newest
                try {
                    $this->getLogger()->info(sprintf('Indexing group %s', $group));
                    $group = $this->doReindex($group, $class, $index);
                } catch (RequestException $e) {
                    $this->getLogger()->error($e->getResponse()->getBody()->getContents());
                    $this->getLogger()->error(date('Y-m-d H:i:s') . PHP_EOL, []);
                    $this->getLogger()->info(sprintf('Failure indexing at group %s', $group));
                    gc_collect_cycles(); // Garbage collection to prevent php from running out of memory
                    $group++;
                    continue;
                }
            }
        }
    }

    /**
     * @param int $group
     * @param string $class
     * @param BaseIndex $index
     * @return int
     * @throws Exception
     */
    private function doReindex($group, $class, BaseIndex $index): int
    {
        gc_collect_cycles(); // Garbage collection to prevent php from running out of memory
        // Generate filtered list of local records
        $baseClass = DataObject::getSchema()->baseDataClass($class);
        /** @var DataList|DataObject[] $items */
        $batchLength = DocumentFactory::config()->get('batchLength');
        // This limit is scientifically determined by keeping on trying until it didn't break anymore
        $items = $baseClass::get()
            ->sort('ID ASC')
            ->limit($batchLength, ($group * $batchLength));
        if ($items->count()) {
            $update = $this->getClient()->createUpdate();
            $solrUpdate = new SolrUpdate();
            $solrUpdate->setDebug($this->debug);
            $solrUpdate->updateIndex($index, $items, $update);
            // If there are no docs, no need to execute an action
            $update->addCommit();
            $this->client->update($update);
        }
        $group++;
        gc_collect_cycles(); // Garbage collection to prevent php from running out of memory

        return $group;
    }

    /**
     * @return Client
     */
    public function getClient(): Client
    {
        return $this->client;
    }

    /**
     * @param Client $client
     * @return SolrIndexTask
     */
    public function setClient(Client $client): SolrIndexTask
    {
        $this->client = $client;

        return $this;
    }
}
