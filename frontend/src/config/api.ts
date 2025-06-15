export const API_CONFIG = {
    BASE_URL: process.env.NEXT_PUBLIC_API_URL || 'http://localhost:8000',
    ENDPOINTS: {
        PRODUCTS: {
            LIST: '/api/products',
            SCRAPE: '/api/products/scrape'
        }
    }
}; 