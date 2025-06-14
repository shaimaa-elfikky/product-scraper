<?php

namespace App\Services;

use App\Models\Product;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

class ProductScraperService
{
    private Client $client;

    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    /**
     * Scrape products from the given URL
     *
     * @param string $url
     * @return array
     * @throws GuzzleException
     */
    public function scrape(string $url): array
    {
        try {
            Log::info("Starting scraping for URL: {$url}");

            // Add random delay between 2-5 seconds
            sleep(rand(2, 5));

            // Now try to fetch the actual URL
            $response = $this->client->get($url);
            $statusCode = $response->getStatusCode();
            
            if ($statusCode !== 200) {
                Log::error("Failed to fetch URL. Status code: {$statusCode}");
                Log::error("Response body: " . (string) $response->getBody());
                throw new \Exception("Failed to fetch URL. Status code: {$statusCode}");
            }

            $html = (string) $response->getBody();
            
            // Log the HTML response for debugging
            Log::info("Amazon HTML Response: " . substr($html, 0, 1000) . "...");
            
            // Check if we got a captcha page
            if (str_contains($html, 'Type the characters you see in this image') || 
                str_contains($html, 'Enter the characters you see below') ||
                str_contains($html, 'To discuss automated access to Amazon data') ||
                str_contains($html, 'Robot Check') ||
                str_contains($html, 'Sorry, we just need to make sure you')) {
                Log::error("Received captcha or anti-bot page");
                throw new \Exception("Amazon is showing a captcha or anti-bot page. Please try again later.");
            }

            $products = $this->parseProducts($html, $url);

            if (empty($products)) {
                Log::warning("No products found for URL: {$url}");
                Log::warning("HTML content: " . substr($html, 0, 1000) . "...");
                return [];
            }

            Log::info("Successfully scraped " . count($products) . " products");
            return $products;

        } catch (GuzzleException $e) {
            Log::error('Scraping failed: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Parse products from HTML content
     *
     * @param string $html
     * @param string $sourceUrl
     * @return array
     */
    private function parseProducts(string $html, string $sourceUrl): array
    {
        $products = [];
        $crawler = new Crawler($html);

        try {
            // Check if it's a product detail page
            if (str_contains($sourceUrl, '/dp/')) {
                $product = $this->parseProductDetail($crawler, $sourceUrl);
                if ($product) {
                    $products[] = $product;
                }
            } else {
                // It's a search results page
                $crawler->filter('div.s-result-item[data-component-type="s-search-result"]')->each(function (Crawler $node) use (&$products, $sourceUrl) {
                    try {
                        $product = $this->parseSearchResult($node, $sourceUrl);
                        if ($product) {
                            $products[] = $product;
                        }
                    } catch (\Exception $e) {
                        Log::warning("Failed to parse search result: {$e->getMessage()}");
                    }
                });
            }
        } catch (\Exception $e) {
            Log::error("Failed to parse products: {$e->getMessage()}");
        }

        return $products;
    }

    /**
     * Parse a product from search results
     *
     * @param Crawler $node
     * @param string $sourceUrl
     * @return array|null
     */
    private function parseSearchResult(Crawler $node, string $sourceUrl): ?array
    {
        try {
            // Get product title - try multiple selectors
            $title = null;
            $titleSelectors = [
                'h2 .a-link-normal .a-text-normal',
                '.a-size-medium.a-color-base.a-text-normal',
                '.a-size-base-plus.a-color-base.a-text-normal',
                'h2 a span'
            ];

            foreach ($titleSelectors as $selector) {
                try {
                    $titleElement = $node->filter($selector);
                    if ($titleElement->count() > 0) {
                        $title = trim($titleElement->text());
                        break;
                    }
                } catch (\Exception $e) {
                    continue;
                }
            }

            if (empty($title)) {
                Log::warning("Could not find title for product");
                return null;
            }

            // Get product price - try multiple selectors
            $price = null;
            $priceSelectors = [
                '.a-price .a-offscreen',
                '.a-price-whole',
                '.a-price'
            ];

            foreach ($priceSelectors as $selector) {
                try {
                    $priceElement = $node->filter($selector);
                    if ($priceElement->count() > 0) {
                        $priceText = $priceElement->text();
                        $price = (float) preg_replace('/[^0-9.]/', '', $priceText);
                        if ($price > 0) break;
                    }
                } catch (\Exception $e) {
                    continue;
                }
            }

            if (empty($price)) {
                Log::warning("Could not find price for product: {$title}");
                return null;
            }

            // Get product image
            $imageUrl = null;
            try {
                $imageElement = $node->filter('.s-image');
                if ($imageElement->count() > 0) {
                    $imageUrl = $imageElement->attr('src');
                }
            } catch (\Exception $e) {
                Log::warning("Could not find image for product: {$title}");
            }

            // Get product URL
            $productUrl = null;
            $urlSelectors = [
                'h2 a.a-link-normal',
                '.a-link-normal.s-underline-text'
            ];

            foreach ($urlSelectors as $selector) {
                try {
                    $urlElement = $node->filter($selector);
                    if ($urlElement->count() > 0) {
                        $productUrl = $urlElement->attr('href');
                        if (!empty($productUrl) && !str_starts_with($productUrl, 'http')) {
                            $productUrl = 'https://www.amazon.com' . $productUrl;
                        }
                        break;
                    }
                } catch (\Exception $e) {
                    continue;
                }
            }

            if (empty($productUrl)) {
                Log::warning("Could not find URL for product: {$title}");
                return null;
            }

            return [
                'title' => $title,
                'price' => $price,
                'image_url' => $imageUrl,
                'source_url' => $productUrl,
                'source_website' => 'amazon.com'
            ];

        } catch (\Exception $e) {
            Log::warning("Failed to parse search result: {$e->getMessage()}");
            return null;
        }
    }

    /**
     * Parse a product detail page
     *
     * @param Crawler $crawler
     * @param string $sourceUrl
     * @return array|null
     */
    private function parseProductDetail(Crawler $crawler, string $sourceUrl): ?array
    {
        try {
            // Get product title
            $title = null;
            $titleSelectors = [
                '#productTitle',
                '#title'
            ];

            foreach ($titleSelectors as $selector) {
                try {
                    $titleElement = $crawler->filter($selector);
                    if ($titleElement->count() > 0) {
                        $title = trim($titleElement->text());
                        break;
                    }
                } catch (\Exception $e) {
                    continue;
                }
            }

            if (empty($title)) {
                Log::warning("Could not find title for product detail page");
                return null;
            }

            // Get product price
            $price = null;
            $priceSelectors = [
                '.a-price .a-offscreen',
                '#priceblock_ourprice',
                '#priceblock_dealprice'
            ];

            foreach ($priceSelectors as $selector) {
                try {
                    $priceElement = $crawler->filter($selector);
                    if ($priceElement->count() > 0) {
                        $priceText = $priceElement->text();
                        $price = (float) preg_replace('/[^0-9.]/', '', $priceText);
                        if ($price > 0) break;
                    }
                } catch (\Exception $e) {
                    continue;
                }
            }

            if (empty($price)) {
                Log::warning("Could not find price for product: {$title}");
                return null;
            }

            // Get product image
            $imageUrl = null;
            $imageSelectors = [
                '#landingImage',
                '#imgBlkFront',
                '#main-image'
            ];

            foreach ($imageSelectors as $selector) {
                try {
                    $imageElement = $crawler->filter($selector);
                    if ($imageElement->count() > 0) {
                        $imageUrl = $imageElement->attr('src');
                        break;
                    }
                } catch (\Exception $e) {
                    continue;
                }
            }

            return [
                'title' => $title,
                'price' => $price,
                'image_url' => $imageUrl,
                'source_url' => $sourceUrl,
                'source_website' => 'amazon.com'
            ];

        } catch (\Exception $e) {
            Log::warning("Failed to parse product detail: {$e->getMessage()}");
            return null;
        }
    }
} 