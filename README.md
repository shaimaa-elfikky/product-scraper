# Product Scraper

A web scraping service built with Laravel and Next.js, designed to extract product information from eCommerce websites with proxy support and real-time updates.

## Features

- **Amazon Product Scraping**: Extract products from category and search pages
- **Proxy Integration**: Built-in support for Privoxy proxy rotation
- **Real-time Updates**: Automatic product refresh every 30 seconds
- **Responsive UI**: Modern, mobile-friendly interface
- **Detailed Logging**: Comprehensive logging for debugging
- **Database Storage**: MySQL storage with efficient indexing
- **API Support**: RESTful API for integration

## System Requirements

- PHP 8.1 or higher
- Node.js 18 or higher
- MySQL 8.0 or higher
- Composer
- npm or yarn
- Privoxy (for proxy support)

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
├── proxy-rotator/        # Go proxy management service
└── storage/              # Logs and other storage
```

## Installation

### 1. Clone the Repository
```bash
git clone <repository-url>
cd product-scraper
```

### 2. Backend Setup (Laravel)

1. **Install Dependencies**
   ```bash
   composer install
   ```

2. **Environment Setup**
   ```bash
   cp .env.example .env
   ```

3. **Configure Database**
   Edit `.env`:
   ```
   DB_CONNECTION=mysql
   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_DATABASE=product_scraper
   DB_USERNAME=root
   DB_PASSWORD=
   ```

4. **Generate Application Key**
   ```bash
   php artisan key:generate
   ```

5. **Run Migrations**
   ```bash
   php artisan migrate
   ```

6. **Start Laravel Server**
   ```bash
   php artisan serve
   ```

### 3. Frontend Setup (Next.js)

1. **Install Dependencies**
   ```bash
   cd frontend
   npm install
   ```

2. **Start Development Server**
   ```bash
   npm run dev
   ```

### 4. Proxy Setup (Privoxy)

1. **Install Privoxy**
   - Windows: Download from [Privoxy website](https://www.privoxy.org/)
   - Linux: `sudo apt-get install privoxy`
   - macOS: `brew install privoxy`

2. **Configure Privoxy**
   Edit `config.txt`:
   ```
   listen-address 127.0.0.1:8118
   forward-socks5 / 127.0.0.1:9050 .
   ```

3. **Start Privoxy**
   - Windows: Run as service
   - Linux/macOS: `sudo service privoxy start`

## Usage

1. **Access the Application**
   - Frontend: `http://localhost:3000`
   - Backend API: `http://localhost:8000`

2. **Scrape Products**
   - Enter an Amazon URL (category or search page)
   - Click "Scrape Products"
   - View results in the grid layout

3. **API Integration**
   ```bash
   # Get all products
   curl http://localhost:8000/api/products

   # Scrape new products
   curl -X POST http://localhost:8000/api/products/scrape \
     -H "Content-Type: application/json" \
     -d '{"url": "https://www.amazon.com/..."}'
   ```

## Database Schema

### Products Table
```sql
CREATE TABLE products (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    price DECIMAL(10,2) NOT NULL,
    image_url VARCHAR(255) NOT NULL,
    source_url TEXT,
    source_website VARCHAR(255),
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);
```

## Troubleshooting

### Common Issues

1. **Database Connection**
   - Verify MySQL is running
   - Check credentials in `.env`
   - Ensure database exists

2. **Proxy Issues**
   - Check Privoxy is running (port 8118)
   - Verify proxy configuration
   - Check proxy rotator service

3. **Scraping Failures**
   - Check Laravel logs (`storage/logs/laravel.log`)
   - Verify URL format
   - Check proxy status

### Debug Commands

```bash
# Clear Laravel cache
php artisan config:clear
php artisan cache:clear

# Check proxy status
curl http://localhost:8081/api/proxy/stats

# View Laravel logs
tail -f storage/logs/laravel.log
```

## Development

### Code Style
- Follow PSR-12 standards
- Use Laravel's coding style guide
- Write meaningful commit messages

### Testing
```bash
# Run PHP tests
php artisan test

# Run frontend tests
cd frontend
npm test
```




