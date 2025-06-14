# Product Scraper

A web scraping service that fetches product details from eCommerce websites, built with Laravel and Next.js.

## Features

- Scrape product details from eCommerce websites (e.g., Amazon)
- Store product data in MySQL database
- Real-time product updates
- Responsive web interface
- Proxy rotation for reliable scraping

## Prerequisites

- PHP 8.1 or higher
- Node.js 18 or higher
- MySQL 8.0 or higher
- Composer
- npm or yarn

## Project Structure

```
.
├── app/                    # Laravel backend
│   ├── Http/Controllers/   # API controllers
│   ├── Models/            # Database models
│   └── Services/          # Business logic
├── frontend/              # Next.js frontend
│   ├── app/              # Next.js pages
│   └── components/       # React components
└── proxy-service/        # Go proxy management service
```

## Setup

### Backend (Laravel)

1. Install PHP dependencies:
   ```bash
   composer install
   ```

2. Copy the environment file:
   ```bash
   cp .env.example .env
   ```

3. Generate application key:
   ```bash
   php artisan key:generate
   ```

4. Configure your database in `.env`:
   ```
   DB_CONNECTION=mysql
   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_DATABASE=product_scraper
   DB_USERNAME=your_username
   DB_PASSWORD=your_password
   ```

5. Run migrations:
   ```bash
   php artisan migrate
   ```

6. Start the Laravel development server:
   ```bash
   php artisan serve
   ```

### Frontend (Next.js)

1. Navigate to the frontend directory:
   ```bash
   cd frontend
   ```

2. Install dependencies:
   ```bash
   npm install
   ```

3. Start the development server:
   ```bash
   npm run dev
   ```

### Proxy Service (Go)

1. Navigate to the proxy service directory:
   ```bash
   cd proxy-service
   ```

2. Install Go dependencies:
   ```bash
   go mod download
   ```

3. Start the proxy service:
   ```bash
   go run main.go
   ```

## Usage

1. Open your browser and navigate to `http://localhost:3000/results`
2. Enter a product URL (e.g., Amazon product page)
3. Click "Scrape Products" to fetch and store product details
4. View the scraped products in the grid layout
5. Products will automatically refresh every 30 seconds

## API Endpoints

- `GET /api/products` - Get all products
- `POST /api/products/scrape` - Scrape products from a URL


