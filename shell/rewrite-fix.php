<?php
/**
 * Created by PhpStorm.
 * User: brent
 * Originally from http://www.atwix.com/magento/duplicated-product-url-keys-in-community-edition/
 * Date: 10/23/15
 * Time: 10:17 AM
 */

require_once 'abstract.php';

class Wage_Shell_Rewrite_Fix extends Mage_Shell_Abstract
{
    const PAGE_SIZE = 1000;
    const LOG_MESSAGE_ROWS = 100;

    public function run()
    {
        if($left = $this->getArg('remove_rewrite')){
            $this->clearExtraRewrites($left);
        } elseif($this->getArg('update_keys')) {
            $this->updateDuplicatedKeys();
        } elseif($this->getArg('remove_sku_spaces')) {
            $this->removeSkuSpaces();
        } else {
            echo $this->usageHelp();
        }
    }

    /**
     * Update duplicated url keys by adding product SKU to the duplicated key
     */
    public function updateDuplicatedKeys()
    {
        try {
            $counter = 0;
            $logMessage = '';
            $start = time();
            $storeId = Mage::app()->getStore()->getId() . PHP_EOL;

            //url key attribute load for further use
            $entityType = Mage::getModel('eav/entity_type')->loadByCode('catalog_product');
            $attributes = $entityType->getAttributeCollection()
                ->addFieldToFilter('attribute_code', array('eq' => 'url_key'))
            ;
            $urlKeyAttribute = $attributes->getFirstItem();
            $urlKeyAttributeTable = $attributes->getTable($entityType->getEntityTable());

            //loading collection with number of duplicated url keys
            $duplicatesCollection = Mage::getModel('catalog/product')->getCollection();
            $duplicatesCollection->getSelect()
                ->joinLeft(
                    array('url_key' => $urlKeyAttributeTable . '_' . $urlKeyAttribute->getBackendType()),
                    'e.entity_id' . ' = url_key.entity_id AND url_key.attribute_id = ' . $urlKeyAttribute->getAttributeId() . ' AND url_key.store_id = ' . $storeId,
                    array($urlKeyAttribute->getAttributeCode() => 'url_key.value')
                )
                ->columns(array('duplicates_calculated' => new Zend_Db_Expr ('COUNT(`url_key`.`value`)')))
                ->group('url_key.value')
                ->order('duplicates_calculated DESC')
            ;

            foreach($duplicatesCollection as $item) {
                if($item->getData('duplicates_calculated') > 1) {
                    //loading product ids with duplicated url keys
                    $duplicatedUrlKey = $item->getData('url_key');
                    $productCollection = Mage::getModel('catalog/product')->getCollection()
                        ->addAttributeToSelect('url_key')
                        ->addAttributeToFilter('url_key', array('eq' => $duplicatedUrlKey))
                    ;
                    $ids = $productCollection->getAllIds();

                    foreach($ids as $id){
                        try {
                            //update product url key
                            $product = Mage::getModel('catalog/product')->load($id);
                            $sku = $product->getData('sku');
                            $urlKey = $product->getData('url_key');
                            $product->setData('url_key', $urlKey . '-' . strtolower(str_replace(' ', '-', $sku)));
                            $product->save();
                            $counter++;
                            $message = 'Product id# ' . $product->getId() . ' "' . $product->getName() . '" ' . ' url key was changed from "' . $urlKey . '" to "' . $product->getData('url_key') . '"' . PHP_EOL;
                            $logMessage .= $message;
                            //log will be update with the packs of messages
                            if($counter % self::LOG_MESSAGE_ROWS == 0) {
                                Mage::log($logMessage, null, 'wage_rewrite_fix.log', true);
                                $logMessage = '';
                            }
                            echo $message;
                        } catch (Exception $e) {
                            echo $e->getMessage() . PHP_EOL;
                            Mage::log($e->getMessage(), null, 'wage_rewrite_fix.log', true);
                        }
                    }
                } else {
                    //we will break the cycle after all duplicates in query were processed
                    break;
                }
            }

            if($counter % self::LOG_MESSAGE_ROWS != 0) {
                Mage::log($logMessage, null, 'wage_rewrite_fix.log', true);
            }

            $end = time();
            $message = $counter . ' products were updated, time spent: ' . $this->timeSpent($start, $end);
            Mage::log($message, null, 'wage_rewrite_fix.log', true);
            echo $message . PHP_EOL;

        } catch (Exception $e) {
            echo $e->getMessage() . PHP_EOL;
            Mage::log($e->getMessage(), null, 'wage_rewrite_fix.log', true);
        }
    }

    /**
     * Remove extra product url rewrite leaving $left of last
     *
     * @var $left
     */
    public function clearExtraRewrites($left)
    {
        try {
            $start = time();
            //Get product collection
            $productCollection = Mage::getModel('catalog/product')->getCollection();
            $productCollection->setPageSize(self::PAGE_SIZE);
            $pages = $productCollection->getLastPageNumber();
            $currentPage = 1;
            $counter = 0;

            while($currentPage <= $pages) {
                $productCollection->setCurPage($currentPage);
                $productCollection->load();

                $ids = $productCollection->getAllIds();
                foreach($ids as $id) {
                    //get rewrite collection for current product id
                    $urlRewritesCollection = Mage::getModel('core/url_rewrite')->getCollection()
                        ->addFieldToFilter('product_id', array('eq' => $id))
                        ->addFieldToFilter('is_system', array('eq' => '0'))
                        ->setOrder('url_rewrite_id', 'DESC')
                    ;
                    $urlRewritesCollection->getSelect()->limit(null, $left);

                    foreach($urlRewritesCollection as $urlRewrite) {
                        try {
                            $urlRewrite->delete();
                            $counter++;
                        } catch(Exception $e) {
                            echo "An error was occurred: " . $e->getMessage() . PHP_EOL;
                            Mage::log($e->getMessage(), null, 'wage_rewrite_fix.log', true);

                        }
                    }
                }

                echo $counter . " URL rewrite were deleted" . PHP_EOL;
                $currentPage++;
                $productCollection->clear();
            }

            $end = time();
            $message = 'Total URL rewrite deleted: ' . $counter . ', time spent: ' . $this->timeSpent($start, $end);
            Mage::log($message, null, 'wage_rewrite_fix.log', true);
            echo $message . PHP_EOL;

        } catch (Exception $e) {
            echo "An error was occurred: " . $e->getMessage() . PHP_EOL;
            Mage::log($e->getMessage(), null, 'wage_rewrite_fix.log', true);
        }
    }

    public function timeSpent($start, $end)
    {
        $seconds = $end - $start;
        $hours = floor($seconds / 3600);
        $mins = floor(($seconds - ($hours*3600)) / 60);
        $secs = floor($seconds % 60);

        return $hours . ' hours ' . $mins . ' minutes ' . $secs . ' seconds';
    }

    /**
     * Retrieve Usage Help Message
     *
     */
    public function usageHelp()
    {
        return "
\n
\n Usage:  php -f fix_attributes -- [options]
\n
\n    --remove_rewrite number    Remove old product rewrite, leaving the 'number' of last ones
\n    update_keys                 Update duplicated product keys
\n    remove_sku_spaces           Remove space from all product SKU's if they are present
\n
\n    help                        This help
\n
";
    }
}

$shell = new Wage_Shell_Rewrite_Fix();
$shell->run();