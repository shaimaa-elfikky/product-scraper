<?php

namespace App\Services;

use App\Models\Product;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

class ProductScraperService
{
    private Client $client;
    private string $proxyRotatorUrl;
    private array $userAgents = [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:123.0) Gecko/20100101 Firefox/123.0',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.3.1 Safari/605.1.15',
    ];

    public function __construct(Client $client)
    {
        $this->client = $client;
        $this->proxyRotatorUrl = env('PROXY_ROTATOR_URL', 'http://localhost:8081');
    }

    /**
     * Get a random user agent
     */
    private function getRandomUserAgent(): string
    {
        return $this->userAgents[array_rand($this->userAgents)];
    }

    /**
     * Get next proxy from rotator
     */
    private function getNextProxy(): ?array
    {
        try {
            $response = Http::timeout(5)->get("{$this->proxyRotatorUrl}/api/proxy/next");
            
            if ($response->successful()) {
                return $response->json();
            }

            Log::error('Failed to get proxy from rotator', [
                'status' => $response->status(),
                'body' => $response->body()
            ]);

            return null;
        } catch (\Exception $e) {
            Log::error('Error getting proxy', [
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Report proxy failure
     */
    private function reportProxyFailure(array $proxy): void
    {
        try {
            Http::timeout(5)->post("{$this->proxyRotatorUrl}/api/proxy/failed", $proxy);
        } catch (\Exception $e) {
            Log::error('Error reporting proxy failure', [
                'error' => $e->getMessage(),
                'proxy' => $proxy
            ]);
        }
    }

    /**
     * Report proxy success
     */
    private function reportProxySuccess(array $proxy): void
    {
        try {
            Http::timeout(5)->post("{$this->proxyRotatorUrl}/api/proxy/success", $proxy);
        } catch (\Exception $e) {
            Log::error('Error reporting proxy success', [
                'error' => $e->getMessage(),
                'proxy' => $proxy
            ]);
        }
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

            // Add random delay between 5-10 seconds
            sleep(rand(5, 10));

            // Get next proxy
            $proxy = $this->getNextProxy();
            if (!$proxy) {
                throw new \Exception("No proxies available");
            }

            // Now try to fetch the actual URL
            $response = $this->client->get($url, [
                'connect_timeout' => 10,
                'timeout' => 30,
                'http_errors' => false,
                'verify' => false,
                'proxy' => sprintf(
                    '%s://%s:%s@%s:%d',
                    $proxy['protocol'] ?? 'http',
                    $proxy['username'] ?? '',
                    $proxy['password'] ?? '',
                    $proxy['host'],
                    $proxy['port']
                ),
                'headers' => [
                    'User-Agent' => $this->getRandomUserAgent(),
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.7',
                    'Accept-Language' => 'en-US,en;q=0.9',
                    'Accept-Encoding' => 'gzip, deflate, br',
                    'Connection' => 'keep-alive',
                    'Upgrade-Insecure-Requests' => '1',
                    'Sec-Fetch-Dest' => 'document',
                    'Sec-Fetch-Mode' => 'navigate',
                    'Sec-Fetch-Site' => 'none',
                    'Sec-Fetch-User' => '?1',
                    'Cache-Control' => 'max-age=0',
                    'sec-ch-ua' => '"Chromium";v="122", "Not(A:Brand";v="24", "Google Chrome";v="122"',
                    'sec-ch-ua-mobile' => '?0',
                    'sec-ch-ua-platform' => '"Windows"',
                    'DNT' => '1',
                    'Referer' => 'https://www.amazon.com/',
                ],
                'allow_redirects' => [
                    'max' => 5,
                    'strict' => false,
                    'referer' => true,
                    'protocols' => ['http', 'https'],
                    'track_redirects' => true
                ],
            ]);

            $statusCode = $response->getStatusCode();
            
            if ($statusCode === 503) {
                $this->reportProxyFailure($proxy);
                Log::error("Amazon blocked the request with 503 Service Unavailable");
                throw new \Exception("Amazon has temporarily blocked our access. Please try again later.");
            }
            
            if ($statusCode !== 200) {
                $this->reportProxyFailure($proxy);
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
                $this->reportProxyFailure($proxy);
                Log::error("Received captcha or anti-bot page");
                throw new \Exception("Amazon is showing a captcha or anti-bot page. Please try again later.");
            }

            $products = $this->parseProducts($html, $url);

            if (empty($products)) {
                $this->reportProxyFailure($proxy);
                Log::warning("No products found for URL: {$url}");
                Log::warning("HTML content: " . substr($html, 0, 1000) . "...");
                return [];
            }

            $this->reportProxySuccess($proxy);
            Log::info("Successfully scraped " . count($products) . " products");
            return $products;

        } catch (GuzzleException $e) {
            if (isset($proxy)) {
                $this->reportProxyFailure($proxy);
            }
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