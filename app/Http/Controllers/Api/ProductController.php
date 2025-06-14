<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\ProductScraperService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ProductController extends Controller
{
    private ProductScraperService $scraperService;

    public function __construct(ProductScraperService $scraperService)
    {
        $this->scraperService = $scraperService;
    }

    /**
     * Get all products
     *
     * @return JsonResponse
     */
    public function index(): JsonResponse
    {
        try {
            $products = Product::latest()->get();
            Log::info('Retrieved products from database', ['count' => $products->count()]);
            return response()->json($products);
        } catch (\Exception $e) {
            Log::error('Error retrieving products', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Failed to retrieve products'], 500);
        }
    }

    public function scrape(Request $request): JsonResponse
    {
        $request->validate([
            'url' => 'required|url'
        ]);

        try {
            Log::info('Received scrape request', ['request_data' => $request->all()]);

            // Get and clean the URL
            $url = trim($request->input('url'));
            
            if (empty($url)) {
                return response()->json(['error' => 'URL is required'], 422);
            }

            // Basic URL validation
            if (!preg_match('/^https?:\/\//', $url)) {
                $url = 'https://' . $url;
            }

            // Validate URL format
            if (!filter_var($url, FILTER_VALIDATE_URL)) {
                return response()->json(['error' => 'Invalid URL format. Please provide a valid URL.'], 422);
            }

            // Check if it's an Amazon URL
            if (!preg_match('/amazon\.com/i', $url)) {
                return response()->json(['error' => 'Only Amazon URLs are supported. Please provide an Amazon URL.'], 422);
            }

            Log::info('Starting product scraping', [
                'url' => $url,
                'max_pages' => $request->max_pages ?? 1
            ]);

            $products = $this->scraperService->scrape($url, $request->max_pages ?? 1);
            
            Log::info('Scraping completed', [
                'total_products' => count($products),
                'products' => $products
            ]);

            // Save products to database
            $savedCount = 0;
            foreach ($products as $productData) {
                try {
                    Log::info('Attempting to save product', ['product_data' => $productData]);
                    $product = Product::create($productData);
                    $savedCount++;
                    Log::info('Product saved successfully', [
                        'id' => $product->id,
                        'title' => $product->title
                    ]);
                } catch (\Exception $e) {
                    Log::error('Failed to save product', [
                        'error' => $e->getMessage(),
                        'product_data' => $productData,
                        'trace' => $e->getTraceAsString()
                    ]);
                }
            }

            Log::info('Product saving completed', [
                'total_attempted' => count($products),
                'successfully_saved' => $savedCount
            ]);

            return response()->json([
                'message' => "Successfully scraped and saved {$savedCount} products",
                'products' => $products
            ]);
        } catch (\Exception $e) {
            Log::error('Scraping failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request_data' => $request->all()
            ]);
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
} 