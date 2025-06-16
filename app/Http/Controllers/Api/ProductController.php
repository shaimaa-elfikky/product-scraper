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

            $products = $this->scraperService->scrape($url);
            
            Log::info('Scraping completed', [
                'total_products' => count($products),
                'products' => $products
            ]);

            // Save products to database
            $savedCount = 0;
            $errors = [];
            
            foreach ($products as $productData) {
                try {
                    // Check if product already exists
                    $existingProduct = Product::where('source_url', $productData['source_url'])->first();
                    
                    if ($existingProduct) {
                        // Update existing product
                        $existingProduct->update([
                            'title' => $productData['title'],
                            'price' => $productData['price'],
                            'image_url' => $productData['image_url']
                        ]);
                        $savedCount++;
                        Log::info('Product updated successfully', [
                            'id' => $existingProduct->id,
                            'title' => $existingProduct->title
                        ]);
                    } else {
                        // Create new product
                        $product = Product::create([
                            'title' => $productData['title'],
                            'price' => $productData['price'],
                            'image_url' => $productData['image_url'],
                            'source_url' => $productData['source_url'],
                            'source_website' => $productData['source_website']
                        ]);
                        $savedCount++;
                        Log::info('Product created successfully', [
                            'id' => $product->id,
                            'title' => $product->title
                        ]);
                    }
                } catch (\Exception $e) {
                    Log::error('Failed to save product', [
                        'error' => $e->getMessage(),
                        'product_data' => $productData,
                        'trace' => $e->getTraceAsString()
                    ]);
                    $errors[] = "Failed to save product: {$productData['title']} - {$e->getMessage()}";
                }
            }

            Log::info('Product saving completed', [
                'total_attempted' => count($products),
                'successfully_saved' => $savedCount,
                'errors' => $errors
            ]);

            $response = [
                'message' => "Successfully scraped and saved {$savedCount} products",
                'products' => $products
            ];

            if (!empty($errors)) {
                $response['errors'] = $errors;
            }

            return response()->json($response);
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