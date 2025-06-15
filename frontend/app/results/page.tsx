'use client';

import { useEffect, useState } from 'react';
import { productService, Product } from '../../src/services/api';

export default function ResultsPage() {
    const [products, setProducts] = useState<Product[]>([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);
    const [lastUpdated, setLastUpdated] = useState<Date>(new Date());

    const fetchProducts = async () => {
        try {
            console.log('Fetching products...');
            const data = await productService.getProducts();
            console.log('Received products:', data);
            // Ensure price is converted to number
            const formattedProducts = Array.isArray(data) ? data.map(product => ({
                ...product,
                price: typeof product.price === 'string' ? parseFloat(product.price) : product.price
            })) : [];
            setProducts(formattedProducts);
            setLastUpdated(new Date());
            setError(null);
        } catch (err) {
            console.error('Error details:', err);
            setError(err instanceof Error ? err.message : 'Failed to fetch products');
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        // Initial fetch
        fetchProducts();

        // Set up interval for auto-refresh
        const intervalId = setInterval(fetchProducts, 30000); // 30 seconds

        // Cleanup interval on component unmount
        return () => clearInterval(intervalId);
    }, []);

    if (loading) {
        return (
            <div className="flex justify-center items-center min-h-screen">
                <div className="animate-spin rounded-full h-12 w-12 border-t-2 border-b-2 border-blue-500"></div>
            </div>
        );
    }

    if (error) {
        return (
            <div className="flex justify-center items-center min-h-screen">
                <div className="text-red-500">
                    <h2 className="text-xl font-bold mb-2">Error</h2>
                    <p>{error}</p>
                </div>
            </div>
        );
    }

    return (
        <div className="container mx-auto px-4 py-8">
            <div className="flex justify-between items-center mb-8">
                <h1 className="text-3xl font-bold">Scraped Products</h1>
                <p className="text-sm text-gray-500">
                    Last updated: {lastUpdated.toLocaleTimeString()}
                </p>
            </div>
            {products.length === 0 ? (
                <div className="text-center text-gray-500">
                    <p className="text-lg mb-2">No products found</p>
                    <p className="text-sm">Try scraping some products from the home page first.</p>
                </div>
            ) : (
                <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                    {products.map((product) => (
                        <div key={product.id} className="bg-white rounded-lg shadow-md overflow-hidden">
                            <img
                                src={product.image_url}
                                alt={product.title}
                                className="w-full h-48 object-cover"
                            />
                            <div className="p-4">
                                <h2 className="text-xl font-semibold mb-2">{product.title}</h2>
                                <p className="text-green-600 font-bold mb-2">
                                    ${typeof product.price === 'number' ? product.price.toFixed(2) : parseFloat(product.price).toFixed(2)}
                                </p>
                                <p className="text-sm text-gray-500 mb-2">
                                    Source: {product.source_website}
                                </p>
                                <a
                                    href={product.source_url}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className="text-blue-500 hover:text-blue-700 text-sm"
                                >
                                    View Original
                                </a>
                            </div>
                        </div>
                    ))}
                </div>
            )}
        </div>
    );
} 