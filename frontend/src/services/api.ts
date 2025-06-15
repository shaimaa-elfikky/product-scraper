import axios, { AxiosError } from 'axios';
import { API_CONFIG } from '../config/api';

const api = axios.create({
    baseURL: API_CONFIG.BASE_URL,
    headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
    },
});

export const scrapingService = {
    startScraping: async (urls: string[]) => {
        const response = await api.post(API_CONFIG.ENDPOINTS.SCRAPING.START, { urls });
        return response.data;
    },

    getStatus: async () => {
        const response = await api.get(API_CONFIG.ENDPOINTS.SCRAPING.STATUS);
        return response.data;
    },

    getResults: async () => {
        const response = await api.get(API_CONFIG.ENDPOINTS.SCRAPING.RESULTS);
        return response.data;
    },
};

export interface Product {
    id: number;
    title: string;
    price: number | string;
    image_url: string;
    product_url: string;
    source_website: string;
    created_at: string;
    updated_at: string;
}

export interface ApiResponse<T> {
    message: string;
    products?: T[];
    error?: string;
}

export const productService = {
    async getProducts(): Promise<Product[]> {
        try {
            console.log('Fetching products from:', `${API_CONFIG.BASE_URL}${API_CONFIG.ENDPOINTS.PRODUCTS.LIST}`);
            const response = await axios.get(`${API_CONFIG.BASE_URL}${API_CONFIG.ENDPOINTS.PRODUCTS.LIST}`);
            console.log('Products response:', response.data);
            return response.data;
        } catch (error) {
            console.error('Error fetching products:', error);
            throw error;
        }
    },

    async scrapeProducts(url: string): Promise<{ message: string; products: Product[] }> {
        try {
            // Ensure the URL is properly formatted
            const formattedUrl = url.trim();
            console.log('Sending scrape request for URL:', formattedUrl);
            
            const response = await axios.post(
                `${API_CONFIG.BASE_URL}${API_CONFIG.ENDPOINTS.PRODUCTS.SCRAPE}`,
                { url: formattedUrl },
                {
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                    },
                    validateStatus: function (status) {
                        return status < 500; // Resolve only if the status code is less than 500
                    }
                }
            );

            if (response.status === 422) {
                throw new Error(response.data.error || 'Invalid URL format');
            }

            return response.data;
        } catch (error) {
            console.error('Scraping error:', error);
            if (axios.isAxiosError(error)) {
                const axiosError = error as AxiosError;
                if (axiosError.response?.data?.error) {
                    throw new Error(axiosError.response.data.error);
                }
                throw new Error(axiosError.message);
            }
            if (error instanceof Error) {
                throw new Error(error.message);
            }
            throw new Error('An unknown error occurred');
        }
    }
}; 