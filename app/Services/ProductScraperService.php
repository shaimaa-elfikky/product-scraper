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
    
    // Real browser user agents to blend in
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

    public function scrape(string $url): array
    {
        Log::info("Starting to scrape: {$url}");
        
        // Take a short break like a human would
        $this->humanDelay();
        
        $proxy = $this->getWorkingProxy();
        if (!$proxy) {
            throw new \Exception("No proxies available right now");
        }

        try {
            $html = $this->fetchPageContent($url, $proxy);
            $this->reportProxySuccess($proxy);
            
            return $this->extractProducts($html, $url);
            
        } catch (\Exception $e) {
            $this->reportProxyFailure($proxy);
            Log::error("Scraping failed: {$e->getMessage()}");
            throw $e;
        }
    }

    private function humanDelay(): void
    {
        // Random delay between 5-10 seconds like a human browsing
        sleep(rand(5, 10));
    }

    private function getRandomUserAgent(): string
    {
        return $this->userAgents[array_rand($this->userAgents)];
    }

    private function getWorkingProxy(): ?array
    {
        try {
            $response = Http::timeout(5)->get("{$this->proxyRotatorUrl}/api/proxy/next");
            
            if ($response->successful() && isset($response->json()['proxy'])) {
                return $response->json()['proxy'];
            }

            Log::error('Proxy rotator failed', [
                'status' => $response->status(),
                'response' => $response->body()
            ]);

        } catch (\Exception $e) {
            Log::error('Error getting proxy: ' . $e->getMessage());
        }

        return null;
    }

    private function fetchPageContent(string $url, array $proxy): string
    {
        Log::info("Fetching page with proxy", ['proxy' => $proxy]);

        $response = $this->client->get($url, [
            'connect_timeout' => 10,
            'timeout' => 30,
            'http_errors' => false,
            'verify' => false,
            'proxy' => "http://{$proxy['host']}:8118", // Privoxy port
            'headers' => $this->getBrowserHeaders(),
            'allow_redirects' => [
                'max' => 5,
                'strict' => false,
                'referer' => true,
                'protocols' => ['http', 'https'],
                'track_redirects' => true
            ],
        ]);

        $statusCode = $response->getStatusCode();
        $html = (string) $response->getBody();

        Log::info("Got response", ['status' => $statusCode]);

        if ($statusCode === 503) {
            throw new \Exception("Amazon blocked us - try again later");
        }

        if ($statusCode !== 200) {
            Log::error("Bad response", ['status' => $statusCode, 'body' => substr($html, 0, 500)]);
            throw new \Exception("Failed to fetch page (Status: {$statusCode})");
        }

        if ($this->isAntiBot($html)) {
            throw new \Exception("Got captcha or anti-bot page - need to wait");
        }

        return $html;
    }

    private function getBrowserHeaders(): array
    {
        return [
            'User-Agent' => $this->getRandomUserAgent(),
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8',
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
        ];
    }

    private function isAntiBot(string $html): bool
    {
        $antiBot = [
            'Type the characters you see in this image',
            'Enter the characters you see below',
            'To discuss automated access to Amazon data',
            'Robot Check',
            'Sorry, we just need to make sure you'
        ];

        foreach ($antiBot as $phrase) {
            if (str_contains($html, $phrase)) {
                Log::error("Anti-bot detected: {$phrase}");
                return true;
            }
        }

        return false;
    }

    private function extractProducts(string $html, string $url): array
    {
        Log::info("Extracting products from HTML");
        
        $crawler = new Crawler($html);
        $products = [];

        // Try different ways to find products
        $selectors = [
            'div[data-component-type="s-card-container"]',
            'div[data-component-type="s-search-result"]',
            'div.s-result-item',
            'div[data-asin]'
        ];

        foreach ($selectors as $selector) {
            $nodes = $crawler->filter($selector);
            Log::info("Selector '{$selector}' found {$nodes->count()} items");
            
            if ($nodes->count() > 0) {
                $nodes->each(function (Crawler $node) use (&$products) {
                    $product = $this->parseProduct($node);
                    if ($product) {
                        $products[] = $product;
                    }
                });
                break; // Found products, stop trying other selectors
            }
        }

        Log::info("Extracted {count} products", ['count' => count($products)]);
        return $products;
    }

    private function parseProduct(Crawler $node): ?array
    {
        try {
            $title = $this->findTitle($node);
            if (!$title) {
                return null; // No title, skip this product
            }

            $price = $this->findPrice($node);
            $imageUrl = $this->findImage($node);
            $productUrl = $this->findProductUrl($node);

            if (!$productUrl) {
                Log::warning("No URL found for product: {$title}");
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
            Log::error("Error parsing product: {$e->getMessage()}");
            return null;
        }
    }

    private function findTitle(Crawler $node): ?string
    {
        $selectors = [
            'h2 a span',
            '.a-size-medium',
            '.a-text-normal',
            'h2 .a-link-normal .a-text-normal',
            '.a-size-medium.a-color-base.a-text-normal',
            '.a-size-base-plus.a-color-base.a-text-normal',
            '.a-size-base-plus',
            '.a-size-mini',
            '.a-link-normal .a-text-normal',
            'a[href*="/dp/"] span',
            'a[href*="/gp/product/"] span'
        ];

        foreach ($selectors as $selector) {
            try {
                $element = $node->filter($selector);
                if ($element->count() > 0) {
                    $title = trim($element->text());
                    if (!empty($title)) {
                        return $title;
                    }
                }
            } catch (\Exception $e) {
                continue;
            }
        }

        // Last resort: try to get any reasonable text
        try {
            $allText = $node->text();
            $lines = array_filter(array_map('trim', explode("\n", $allText)));
            foreach ($lines as $line) {
                if (strlen($line) > 10 && strlen($line) < 200) {
                    return $line;
                }
            }
        } catch (\Exception $e) {
            // Give up
        }

        return null;
    }

    private function findPrice(Crawler $node): ?float
    {
        $selectors = [
            '.a-price .a-offscreen',
            '.a-price-whole',
            '.a-color-price',
            '.a-price',
            '.a-price-range',
            '.a-color-base .a-price'
        ];

        foreach ($selectors as $selector) {
            try {
                $element = $node->filter($selector);
                if ($element->count() > 0) {
                    $priceText = trim($element->text());
                    $price = (float) preg_replace('/[^0-9.]/', '', $priceText);
                    if ($price > 0) {
                        return $price;
                    }
                }
            } catch (\Exception $e) {
                continue;
            }
        }

        return null;
    }

    private function findImage(Crawler $node): ?string
    {
        $selectors = [
            '.s-image',
            '.a-image-container img',
            'img[data-image-latency="s-product-image"]',
            'img[data-a-dynamic-image]',
            'img[src*="images/I"]',
            'img[src*="media/s"]'
        ];

        foreach ($selectors as $selector) {
            try {
                $element = $node->filter($selector);
                if ($element->count() > 0) {
                    $imageUrl = $element->attr('src');
                    if ($imageUrl) {
                        return $imageUrl;
                    }
                }
            } catch (\Exception $e) {
                continue;
            }
        }

        return null;
    }

    private function findProductUrl(Crawler $node): ?string
    {
        $selectors = [
            'h2 a.a-link-normal',
            '.a-link-normal.s-underline-text',
            'a[href*="/dp/"]',
            'a[href*="/gp/product/"]',
            'a[href*="/s?"]',
            'a[href*="/browse.html"]'
        ];

        foreach ($selectors as $selector) {
            try {
                $element = $node->filter($selector);
                if ($element->count() > 0) {
                    $url = $element->attr('href');
                    if (!empty($url)) {
                        return str_starts_with($url, 'http') ? $url : 'https://www.amazon.com' . $url;
                    }
                }
            } catch (\Exception $e) {
                continue;
            }
        }

        return null;
    }

    private function reportProxyFailure(array $proxy): void
    {
        try {
            Http::timeout(5)->post("{$this->proxyRotatorUrl}/api/proxy/failed", $proxy);
        } catch (\Exception $e) {
            Log::error("Couldn't report proxy failure: {$e->getMessage()}");
        }
    }

    private function reportProxySuccess(array $proxy): void
    {
        try {
            Http::timeout(5)->post("{$this->proxyRotatorUrl}/api/proxy/success", $proxy);
        } catch (\Exception $e) {
            Log::error("Couldn't report proxy success: {$e->getMessage()}");
        }
    }
}