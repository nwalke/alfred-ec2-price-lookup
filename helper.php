<?php

use Alfred\Workflows\Workflow;

require 'vendor/autoload.php';

class Helper
{
  protected $query;
  protected $cache;
  protected $location;
  protected $operatingSystem;
  protected $pricingFile;
  protected $productsFile;
  protected $offersFile;
  protected $workflow;

  public function __construct($query, $location, $system)
  {
    $this->workflow = new Workflow;
    $this->cache = getenv('alfred_workflow_cache');
    if (!file_exists($this->cache)) {
      mkdir($this->cache, 0777, true);
    }
    $this->query = $query;
    $this->location = $location;
    $this->operatingSystem = $system;
    $this->pricingFile = $this->cache . '/ec2.json';
    $this->productsFile = $this->cache . '/products.json';
    $this->offersFile = $this->cache . '/offers.json';
  }

  public function operate()
  {
    // We want to find pricing
    return $this->findPricing();
  }

  public function findPricing()
  {
    if (!file_exists($this->pricingFile)) {
      $this->workflow->result()
        ->title('Please run ec2-update')
        ->subtitle('I cannot find the pricing information I need.');
      return $this->workflow->output();
    }
    $productJQ = JsonQueryWrapper\JsonQueryFactory::createWith($this->productsFile);
    $productJQ->setCmd('/usr/local/bin/jq');
    $products = json_decode($productJQ->run('[ .[] | select((.attributes.instanceType | contains("' . $this->query .'"))) ]'), true);
    $offerJQ = JsonQueryWrapper\JsonQueryFactory::createWith($this->offersFile);
    $offerJQ->setCmd('/usr/local/bin/jq');
    $skus = [];
    $finalProducts = [];
    foreach($products as $product) {
      $skus[] = $product['sku'];
      $finalProducts[$product['sku']] = $product;
    }
    $skuString = implode('|', $skus);
    $offers = json_decode($offerJQ->run('[ .[] | select((.sku | test("' . $skuString . '"))) ]'), true);
    foreach($offers as $offer) {
      $hourly = floatval(reset($offer['priceDimensions'])['pricePerUnit']['USD']);
      $daily = $hourly * 24;
      $monthly = $daily * 31;
      $finalProducts[$offer['sku']]['hourly'] = $hourly;
      $finalProducts[$offer['sku']]['subtitle']= $hourly . ' per hour, ' . $daily . ' per day, ' . $monthly . ' per month.';
    }
    usort($finalProducts, function($a, $b) {
      return strcmp($a['hourly'], $b['hourly']);
    });
    foreach($finalProducts as $product) {
      $extendedDetails = 'Clock: ' . $product['attributes']['clockSpeed'] . ' Network: ' . $product['attributes']['networkPerformance'];
      if (isset($product['attributes']['dedicatedEbsThroughput'])) {
        $extendedDetails .= ' EBS Throughput: ' . $product['attributes']['dedicatedEbsThroughput'];
      }
      $this->workflow->result()
        ->title($product['attributes']['instanceType'])
        ->subtitle($product['subtitle'])
        ->text('copy', $product['hourly'])
        ->text('largetype', $product['subtitle'])
        ->cmd('Memory: ' . $product['attributes']['memory'] . ' VCPUS: ' . $product['attributes']['vcpu'] . ' Storage: ' . $product['attributes']['storage'], 'details')
        ->shift($extendedDetails, 'extended-details')
        ->autocomplete($product['attributes']['instanceType']);
    }
    return $this->workflow->output();
  }

}
