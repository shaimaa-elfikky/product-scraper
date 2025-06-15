'use client';

import { useState } from 'react';
import { productService } from '../src/services/api';

export default function Home() {
  const [url, setUrl] = useState<string>('');
  const [isLoading, setIsLoading] = useState(false);
  const [status, setStatus] = useState<string>('');
  const [error, setError] = useState<string>('');

  const handleScrape = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!url) {
      setError('Please enter a URL');
      return;
    }

    setIsLoading(true);
    setError('');
    setStatus('');

    try {
      const result = await productService.scrapeProducts(url);
      setStatus(result.message || 'Scraping started successfully!');
      setUrl('');
    } catch (error) {
      const errorMessage = error instanceof Error ? error.message : 'Error starting scraping process';
      setError(errorMessage);
      console.error('Error:', error);
    } finally {
      setIsLoading(false);
    }
  };

  return (
    <main className="min-h-screen bg-gray-50 py-12 px-4 sm:px-6 lg:px-8">
      <div className="max-w-3xl mx-auto">
        <div className="text-center">
          <h1 className="text-3xl font-bold text-gray-900 sm:text-4xl">
            Product Scraper
          </h1>
          <p className="mt-3 text-lg text-gray-500">
            Enter an Amazon URL to start scraping products
          </p>
        </div>

        <div className="mt-8 bg-white shadow sm:rounded-lg">
          <div className="px-4 py-5 sm:p-6">
            <form onSubmit={handleScrape} className="space-y-4">
              <div>
                <label htmlFor="url" className="block text-sm font-medium text-gray-700">
                  Amazon URL to Scrape
                </label>
                <div className="mt-1">
                  <input
                    type="url"
                    id="url"
                    value={url}
                    onChange={(e) => setUrl(e.target.value)}
                    placeholder="https://www.amazon.com/s?k=wireless+headphones"
                    className="shadow-sm focus:ring-indigo-500 focus:border-indigo-500 block w-full sm:text-sm border-gray-300 rounded-md"
                    required
                  />
                </div>
              </div>

              <div className="text-center">
                <button
                  type="submit"
                  disabled={isLoading}
                  className={`inline-flex justify-center py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-md text-white 
                    ${isLoading 
                      ? 'bg-indigo-400 cursor-not-allowed' 
                      : 'bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500'
                    }`}
                >
                  {isLoading ? 'Scraping...' : 'Start Scraping'}
                </button>
              </div>
            </form>

            {error && (
              <div className="mt-4 p-4 rounded-md bg-red-50">
                <p className="text-sm text-red-700">{error}</p>
              </div>
            )}

            {status && (
              <div className="mt-4 p-4 rounded-md bg-blue-50">
                <p className="text-sm text-blue-700">{status}</p>
              </div>
            )}
          </div>
        </div>
      </div>
    </main>
  );
}
