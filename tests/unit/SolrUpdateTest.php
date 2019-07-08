<?php


namespace Firesphere\SolrSearch\Tests;

use CircleCITestIndex;
use Firesphere\SolrSearch\Helpers\SolrUpdate;
use Firesphere\SolrSearch\Jobs\SolrIndexJob;
use Firesphere\SolrSearch\Queries\BaseQuery;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;

class SolrUpdateTest extends SapphireTest
{

    /**
     * @var SolrUpdate
     */
    protected $solrUpdate;

    /**
     * @expectedException \LogicException
     */
    public function testUpdateItemsFail()
    {
        $this->solrUpdate->updateItems(null, SolrUpdate::CREATE_TYPE);
    }

    public function testUpdateItems()
    {
        $index = new CircleCITestIndex();
        $query = new BaseQuery();
        $query->addTerm('*:*');
        $items = SiteTree::get();

        $result = $this->solrUpdate->updateItems($items, SolrUpdate::UPDATE_TYPE, CircleCITestIndex::class);
        $this->assertEquals(200, $result->getResponse()->getStatusCode());

        $this->solrUpdate->updateItems($items, SolrUpdate::DELETE_TYPE, CircleCITestIndex::class);
        $this->assertEquals(0, $index->doSearch($query)->getTotalItems());

        $indexJob = Injector::inst()->get(SolrIndexJob::class)->process();
        $this->assertEquals(5, $index->doSearch($query)->getTotalItems());

        $this->solrUpdate->updateItems([], SolrUpdate::DELETE_TYPE_ALL, CircleCITestIndex::class);
        $this->assertEquals(0, $index->doSearch($query)->getTotalItems());
    }

    /**
     * @expectedException \LogicException
     */
    public function testWrongUpdateItems()
    {
        $items = SiteTree::get();

        $this->solrUpdate->updateItems($items, SolrUpdate::UPDATE_TYPE, 'NonExisting');
    }

    protected function setUp()
    {
        $this->solrUpdate = new SolrUpdate();

        return parent::setUp(); // TODO: Change the autogenerated stub
    }
}
